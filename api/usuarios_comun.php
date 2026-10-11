<?php
require_once __DIR__ . '/autorizacion.php';

function usuarios_validar(bool $condicion, string $mensaje, int $codigo = 400): void {
    if (!$condicion) throw new RuntimeException($mensaje, $codigo);
}
function usuarios_id(mixed $valor): int {
    usuarios_validar(is_int($valor) || is_string($valor), 'Identificador inválido.');
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    usuarios_validar($id !== false, 'Identificador inválido.');
    return $id;
}
function usuarios_clave(array $datos): string {
    $clave = $datos['contrasena'] ?? null;
    usuarios_validar(is_string($clave) && strlen($clave) >= 8 && strlen($clave) <= 72 && !str_contains($clave, "\0"), 'La contraseña debe tener entre 8 y 72 bytes.');
    usuarios_validar($clave === ($datos['confirmacion'] ?? null), 'Las contraseñas no coinciden.');
    return password_hash($clave, PASSWORD_DEFAULT);
}
function usuarios_ejecutar(string $accion): void {
    requerir_permiso('VER_USUARIOS');
    $lectura = in_array($accion, ['listar','obtener','empleados','roles'], true);
    if (!$lectura) requerir_permiso('GESTIONAR_USUARIOS');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $metodo = $lectura ? 'GET' : 'POST';
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) { header('Allow: '.$metodo); autorizacion_error(405, 'Método no permitido.'); }
    $db = null;
    try {
        $db = autorizacion_conexion();
        $seleccion = "SELECT u.id_usuario,u.nombre_usuario,u.estado,u.id_empleado,u.id_rol,e.nombres || ' ' || e.apellidos AS empleado,e.correo,r.nombre AS rol FROM usuario u JOIN empleado e USING(id_empleado) JOIN rol r USING(id_rol)";
        $roles = "('ADMINISTRADOR','ENCARGADO_OBRA','COMPRAS','CONTABILIDAD','SECRETARIA')";
        if ($lectura) {
            $params = [];
            if ($accion === 'listar') {
                $buscar = $_GET['buscar'] ?? '';
                usuarios_validar(is_string($buscar) && strlen($buscar) <= 500, 'Búsqueda inválida.');
                $buscar = '%'.str_replace(['!','%','_'], ['!!','!%','!_'], trim($buscar)).'%';
                $sql = $seleccion." WHERE u.nombre_usuario ILIKE :usuario ESCAPE '!' OR (e.nombres || ' ' || e.apellidos) ILIKE :empleado ESCAPE '!' ORDER BY u.id_usuario DESC";
                $params = ['usuario'=>$buscar,'empleado'=>$buscar];
            } elseif ($accion === 'obtener') { $sql = $seleccion.' WHERE u.id_usuario=:id'; $params=['id'=>usuarios_id($_GET['id'] ?? null)];
            } elseif ($accion === 'empleados') {
                $sql = "SELECT e.id_empleado,e.nombres || ' ' || e.apellidos AS nombre,e.estado FROM empleado e WHERE NOT EXISTS (SELECT 1 FROM usuario u WHERE u.id_empleado=e.id_empleado) ORDER BY e.nombres,e.apellidos";
            } else { $sql = "SELECT id_rol,nombre FROM rol WHERE nombre IN $roles ORDER BY nombre"; }
            $q=$db->prepare($sql); $q->execute($params);
            $resultado = $accion === 'obtener' ? $q->fetch(PDO::FETCH_ASSOC) : $q->fetchAll(PDO::FETCH_ASSOC);
            usuarios_validar($resultado !== false, 'Usuario no encontrado.', 404);
            echo json_encode(['ok'=>true,'datos'=>$resultado], JSON_UNESCAPED_UNICODE); return;
        }
        // Exigir JSON impide envíos de formularios de otros sitios; no se habilita CORS.
        usuarios_validar(strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) === 'application/json', 'Envía los datos como JSON.', 415);
        $datos=json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        usuarios_validar(is_array($datos) && !array_is_list($datos), 'Datos inválidos.');
        $hash = in_array($accion, ['crear','contrasena'], true) ? usuarios_clave($datos) : null;
        $db->beginTransaction();
        // Serializa las escrituras: la tabla existente no tiene UNIQUE para cuenta/empleado.
        // También evita que dos administradores desactiven simultáneamente al último.
        $db->exec('LOCK TABLE usuario IN SHARE ROW EXCLUSIVE MODE');
        $id = $accion === 'crear' ? null : usuarios_id($datos['id'] ?? null);
        $actual = null;
        if ($id !== null) {
            $q=$db->prepare($seleccion.' WHERE u.id_usuario=:id'); $q->execute(['id'=>$id]); $actual=$q->fetch(PDO::FETCH_ASSOC);
            usuarios_validar($actual !== false, 'Usuario no encontrado.', 404);
        }
        $valores = [];
        if (in_array($accion, ['crear','editar'], true)) {
            $nombre=$datos['nombre_usuario'] ?? null;
            usuarios_validar(is_string($nombre) && preg_match('/^[\p{L}\p{N}_.@-]{1,50}$/uD', $nombre) === 1, 'Usuario: usa de 1 a 50 letras, números, puntos, guiones, @ o _.');
            $q=$db->prepare('SELECT 1 FROM usuario WHERE lower(nombre_usuario)=lower(:nombre) AND id_usuario<>:id');$q->execute(['nombre'=>$nombre,'id'=>$id ?? 0]);
            usuarios_validar(!$q->fetchColumn(), 'El nombre de usuario ya existe.', 409);
            $rol=usuarios_id($datos['id_rol'] ?? null);
            $q=$db->prepare("SELECT nombre FROM rol WHERE id_rol=:id AND nombre IN $roles");$q->execute(['id'=>$rol]);$rolNombre=$q->fetchColumn();
            // Las cuentas antiguas pueden conservar su rol; no se asigna a otras cuentas.
            usuarios_validar($rolNombre !== false || ($actual && (int)$actual['id_rol'] === $rol), 'Selecciona un rol permitido.');
            $valores=['nombre_usuario'=>$nombre,'id_rol'=>$rol];
            if ($actual) usuarios_validar(!isset($datos['id_empleado']) || (int)$datos['id_empleado'] === (int)$actual['id_empleado'], 'El empleado de una cuenta no se puede cambiar.');
        }
        if (in_array($accion, ['crear','editar','estado'], true)) {
            $estado=$datos['estado'] ?? null;
            usuarios_validar(in_array($estado, ['ACTIVO','INACTIVO'], true), 'Selecciona ACTIVO o INACTIVO.');
            $valores['estado']=$estado;
            if ($actual) {
                usuarios_validar($estado !== 'INACTIVO' || $id !== autorizacion_usuario()['id_usuario'], 'No puedes desactivar tu propia cuenta.', 409);
                $rolDestino=$valores['id_rol'] ?? (int)$actual['id_rol'];
                if ($actual['rol'] === 'ADMINISTRADOR' && $actual['estado'] === 'ACTIVO' && ($estado !== 'ACTIVO' || $rolDestino !== (int)$actual['id_rol'])) {
                    $q=$db->prepare("SELECT count(*) FROM usuario u JOIN rol r USING(id_rol) WHERE r.nombre='ADMINISTRADOR' AND u.estado='ACTIVO' AND u.id_usuario<>:id");$q->execute(['id'=>$id]);
                    usuarios_validar((int)$q->fetchColumn() > 0, 'Debe quedar al menos un administrador activo. No puedes modificar al último administrador.', 409);
                }
            }
        }
        if ($accion === 'crear') {
            $empleado=usuarios_id($datos['id_empleado'] ?? null);
            $q=$db->prepare('SELECT 1 FROM empleado WHERE id_empleado=:id');$q->execute(['id'=>$empleado]);usuarios_validar((bool)$q->fetchColumn(), 'Selecciona un empleado existente.');
            $q=$db->prepare('SELECT 1 FROM usuario WHERE id_empleado=:id');$q->execute(['id'=>$empleado]);usuarios_validar(!$q->fetchColumn(), 'El empleado ya tiene una cuenta.', 409);
            $valores['id_empleado']=$empleado;$valores['contrasena']=$hash;
            $sql='INSERT INTO usuario (nombre_usuario,id_rol,estado,id_empleado,contrasena) VALUES (:nombre_usuario,:id_rol,:estado,:id_empleado,:contrasena) RETURNING id_usuario';
        } else {
            if ($accion === 'contrasena') $valores=['contrasena'=>$hash];
            $asignaciones=implode(',',array_map(static fn($campo)=>"$campo=:$campo",array_keys($valores)));
            $sql="UPDATE usuario SET $asignaciones WHERE id_usuario=:id RETURNING id_usuario";$valores['id']=$id;
        }
        $q=$db->prepare($sql);$q->execute($valores);$id=(int)$q->fetchColumn();$db->commit();
        http_response_code($accion === 'crear' ? 201 : 200);
        echo json_encode(['ok'=>true,'id'=>$id,'mensaje'=>'Cambios guardados correctamente.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        if ($e instanceof JsonException) autorizacion_error(400, 'JSON inválido.');
        if ($e instanceof RuntimeException && !$e instanceof PDOException && in_array($e->getCode(), [400,404,409,415], true)) autorizacion_error($e->getCode(), $e->getMessage());
        autorizacion_error(500, 'No se pudo guardar o consultar el usuario. Intenta nuevamente.');
    }
}
