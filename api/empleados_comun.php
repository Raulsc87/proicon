<?php
require_once __DIR__.'/autorizacion.php';
require_once __DIR__.'/empleados_guardar.php';

function empleados_puede_gestionar(): bool {
    return usuario_tiene_permiso('GESTIONAR_EMPLEADOS');
}
function empleados_ejecutar(string $accion): void {
    $lectura = in_array($accion, ['listar','obtener'], true);
    requerir_permiso($lectura ? 'VER_EMPLEADOS' : 'GESTIONAR_EMPLEADOS');
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $metodo = $lectura ? 'GET' : 'POST';
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) { header('Allow: '.$metodo); autorizacion_error(405, 'Método no permitido.'); }
    try {
        $db = autorizacion_conexion();
        $columnas = 'id_empleado,nombres,apellidos,telefono,correo,estado';
        $datos=[];$params=[];
        if (!$lectura) {
            if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') autorizacion_error(415, 'Envía datos JSON.');
            $datos=json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($datos) || array_is_list($datos)) autorizacion_error(400, 'Datos inválidos.');
        }
        if ($accion === 'listar') {
            $buscar=$_GET['buscar'] ?? '';
            if (!is_string($buscar) || strlen($buscar)>500) autorizacion_error(400, 'Búsqueda inválida.');
            $buscar='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($buscar)).'%';
            $estado=$_GET['estado'] ?? 'TODOS';
            if (!in_array($estado,['ACTIVO','INACTIVO','TODOS'],true)) autorizacion_error(400,'Filtro de estado inválido.');
            $sql="SELECT $columnas FROM empleado WHERE (nombres ILIKE :n ESCAPE '!' OR apellidos ILIKE :a ESCAPE '!' OR correo ILIKE :c ESCAPE '!')";
            $params=['n'=>$buscar,'a'=>$buscar,'c'=>$buscar];
            if ($estado!=='TODOS') { $sql.=' AND estado=:estado'; $params['estado']=$estado; }
            $sql.=' ORDER BY id_empleado DESC';
        } else {
            if ($accion !== 'crear') {
                $id=filter_var($lectura ? ($_GET['id'] ?? null) : ($datos['id'] ?? null),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($id===false) autorizacion_error(400,'Identificador inválido.');
                $params['id']=$id;
            }
            if ($accion === 'obtener') $sql="SELECT $columnas FROM empleado WHERE id_empleado=:id";
            else {
                $reglas=$accion==='estado' ? ['estado'=>20] : ['nombres'=>80,'apellidos'=>80,'telefono'=>20,'correo'=>120,'estado'=>20];
                $valores=[];
                foreach($reglas as $campo=>$max) {
                    $valor=$datos[$campo] ?? '';
                    if (!is_string($valor)) autorizacion_error(400,'Los campos deben ser texto.');
                    $valor=preg_replace('/\s+/u',' ',trim($valor));
                    if ($valor !== null) $valor=trim($valor);
                    if ($valor===null || preg_match_all('/./us',$valor)>$max) autorizacion_error(400,"Longitud inválida para $campo (máximo $max). ");
                    if (in_array($campo,['nombres','apellidos','estado'],true) && $valor==='') autorizacion_error(400,"El campo $campo es obligatorio.");
                    if ($campo==='correo' && $valor!=='' && !filter_var($valor,FILTER_VALIDATE_EMAIL)) autorizacion_error(400,'El correo no es válido.');
                    if ($campo==='estado' && !in_array($valor,['ACTIVO','INACTIVO'],true)) autorizacion_error(400,'Selecciona ACTIVO o INACTIVO.');
                    $valores[$campo]=$valor===''?null:$valor;
                }
                $params=array_merge($params,$valores);
                if ($accion==='crear') $sql="INSERT INTO empleado(nombres,apellidos,telefono,correo,estado) VALUES (:nombres,:apellidos,:telefono,:correo,:estado) RETURNING $columnas";
                else {
                    $asignaciones=implode(',',array_map(static fn($c)=>"$c=:$c",array_keys($valores)));
                    $sql="UPDATE empleado SET $asignaciones WHERE id_empleado=:id RETURNING $columnas";
                }
            }
        }
        $advertencia=null;
        if ($lectura) {
            $q=$db->prepare($sql);$q->execute($params);
            $r=$accion==='listar'?$q->fetchAll(PDO::FETCH_ASSOC):$q->fetch(PDO::FETCH_ASSOC);
        } else {
            $db->beginTransaction();
            $guardado=empleados_guardar($db,$sql,$params,$accion);
            $r=$guardado['datos'];$advertencia=$guardado['advertencia'];
            $db->commit();
        }
        if ($r===false) autorizacion_error(404,'Empleado no encontrado.');
        http_response_code($accion==='crear'?201:200);
        echo json_encode(['ok'=>true,'datos'=>$r,'gestionar'=>empleados_puede_gestionar(),'mensaje'=>'Empleado guardado correctamente.','advertencia'=>$advertencia],JSON_UNESCAPED_UNICODE);
    } catch (JsonException $e) { autorizacion_error(400,'JSON inválido.'); }
    catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        autorizacion_error(500,'No se pudo completar la operación. Intenta nuevamente.');
    }
}
