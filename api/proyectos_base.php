<?php
// Núcleo separado para conservar intactos los mantenimientos existentes.
require_once __DIR__ . '/mantenimiento.php';

function proyecto_query(PDO $db, string $sql, array $params = []): PDOStatement {
    $q = $db->prepare($sql);
    $q->execute($params);
    return $q;
}
function proyecto_id(mixed $valor): int {
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($id === false) responder(['ok' => false, 'mensaje' => 'Identificador o versión inválidos.'], 400);
    return $id;
}
function proyecto_texto(array $datos, string $campo, int $max, bool $requerido = false): ?string {
    $v = $datos[$campo] ?? '';
    if (!is_string($v)) responder(['ok' => false, 'mensaje' => "Valor inválido para $campo."], 400);
    $v = trim($v);
    $largo = preg_match_all('/./us', $v);
    if ($largo === false || $largo > $max || ($requerido && $v === '')) responder(['ok' => false, 'mensaje' => "Revisa el campo $campo (máximo $max caracteres)."], 400);
    return $v === '' ? null : $v;
}
function proyecto_fecha(array $datos, string $campo, bool $requerido = true): ?string {
    $v = proyecto_texto($datos, $campo, 10, $requerido);
    if ($v === null) return null;
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$f || $f->format('Y-m-d') !== $v) responder(['ok' => false, 'mensaje' => "Fecha inválida: $campo."], 400);
    return $v;
}
function proyecto_estados(PDO $db, string $tabla, string $campo = 'estado'): array {
    // Ambos identificadores provienen exclusivamente del código del servidor.
    return proyecto_query($db, "SELECT DISTINCT $campo FROM $tabla ORDER BY $campo")->fetchAll(PDO::FETCH_COLUMN);
}
function proyecto_estado(PDO $db, array $datos, string $tabla, string $campo = 'estado'): string {
    $v = proyecto_texto($datos, $campo, 30, true);
    if (!in_array($v, proyecto_estados($db, $tabla, $campo), true)) responder(['ok' => false, 'mensaje' => "El valor de $campo no existe en el catálogo actual."], 400);
    return $v;
}
function proyecto_decimal(array $datos, string $campo, int $enteros, bool $positivo): string {
    $v = $datos[$campo] ?? '';
    if (!is_string($v) && !is_int($v) && !is_float($v)) responder(['ok' => false, 'mensaje' => "Número inválido: $campo."], 400);
    $v = (string) $v;
    if (!preg_match('/^\d{1,' . $enteros . '}(\.\d{1,2})?$/D', $v) || ($positivo && (float) $v <= 0)) responder(['ok' => false, 'mensaje' => "$campo debe ser un número válido, con máximo dos decimales."], 400);
    return $v;
}
function proyecto_guardar(PDO $db, string $tabla, array $valores, string $where = '', array $ids = []): array {
    $campos = array_keys($valores);
    $sql = $where === ''
        ? "INSERT INTO $tabla (" . implode(', ', $campos) . ') VALUES (:' . implode(', :', $campos) . ')'
        : "UPDATE $tabla SET " . implode(', ', array_map(fn($c) => "$c = :$c", $campos)) . " WHERE $where";
    $r = proyecto_query($db, $sql . ' RETURNING *', array_merge($valores, $ids))->fetch(PDO::FETCH_ASSOC);
    if (!$r) responder(['ok' => false, 'mensaje' => 'Registro no encontrado en este proyecto.'], 404);
    return $r;
}
function proyectos_api(string $accion): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    session_start();
    $usuario = $_SESSION['id_usuario'] ?? null;
    session_write_close();
    if (!$usuario) responder(['ok' => false, 'mensaje' => 'Inicia sesión nuevamente.'], 401);
    $lecturas = ['catalogos', 'listar', 'obtener', 'empleados_listar', 'presupuestos_listar', 'presupuestos_obtener'];
    $metodo = in_array($accion, $lecturas, true) ? 'GET' : 'POST';
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
        header('Allow: ' . $metodo);
        responder(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
    }
    $db = null;
    ob_start();
    try {
        require __DIR__ . '/../config/database.local.php';
        ob_end_clean();
        $db = $conexion;
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $d = $_GET;
        if ($metodo === 'POST') {
            try { $d = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { responder(['ok' => false, 'mensaje' => 'JSON inválido.'], 400); }
            if (!is_array($d) || array_is_list($d)) responder(['ok' => false, 'mensaje' => 'Datos inválidos.'], 400);
        }
        if ($accion === 'catalogos') {
            $r = [
                'estados' => proyecto_estados($db, 'proyecto'),
                'avances' => proyecto_estados($db, 'proyecto', 'estado_avance'),
                'estados_empleado' => proyecto_estados($db, 'proyecto_empleado'),
                'estados_presupuesto' => proyecto_estados($db, 'presupuesto'),
                'clientes' => proyecto_query($db, 'SELECT id_cliente, nombre FROM cliente ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC),
                'empleados' => proyecto_query($db, "SELECT id_empleado, nombres || ' ' || apellidos AS nombre, telefono, estado FROM empleado ORDER BY nombres, apellidos")->fetchAll(PDO::FETCH_ASSOC),
                'categorias' => proyecto_query($db, 'SELECT id_categoria_costo, nombre FROM categoria_costo ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC),
                'materiales' => proyecto_query($db, 'SELECT id_material, codigo, nombre FROM material ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC)
            ];
        } elseif ($accion === 'listar') {
            $buscar = proyecto_texto($d, 'buscar', 500) ?? '';
            $buscar = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $buscar) . '%';
            $r = proyecto_query($db, "SELECT p.*, c.nombre AS cliente FROM proyecto p JOIN cliente c USING (id_cliente) WHERE p.codigo ILIKE :codigo ESCAPE '!' OR p.nombre ILIKE :nombre ESCAPE '!' ORDER BY p.id_proyecto DESC", ['codigo' => $buscar, 'nombre' => $buscar])->fetchAll(PDO::FETCH_ASSOC);
        } elseif (in_array($accion, ['crear', 'editar', 'estado'], true)) {
            $v = ['estado' => proyecto_estado($db, $d, 'proyecto')];
            if ($accion !== 'estado') {
                foreach (['codigo' => 30, 'nombre' => 150, 'descripcion' => 100000, 'ubicacion' => 200, 'observaciones' => 100000] as $c => $max) $v[$c] = proyecto_texto($d, $c, $max, in_array($c, ['codigo', 'nombre'], true));
                $v['id_cliente'] = proyecto_id($d['id_cliente'] ?? null);
                $v['estado_avance'] = proyecto_estado($db, $d, 'proyecto', 'estado_avance');
                $v['fecha_inicio'] = proyecto_fecha($d, 'fecha_inicio', false);
                $v['fecha_fin_estimada'] = proyecto_fecha($d, 'fecha_fin_estimada', false);
                if ($v['fecha_inicio'] && $v['fecha_fin_estimada'] && $v['fecha_inicio'] > $v['fecha_fin_estimada']) responder(['ok' => false, 'mensaje' => 'La fecha final no puede preceder al inicio.'], 400);
            }
            $r = proyecto_guardar($db, 'proyecto', $v, $accion === 'crear' ? '' : 'id_proyecto = :id', $accion === 'crear' ? [] : ['id' => proyecto_id($d['id'] ?? null)]);
        } else {
            $pid = proyecto_id($d['id_proyecto'] ?? $d['id'] ?? null);
            $proyecto = proyecto_query($db, 'SELECT p.*, c.nombre AS cliente FROM proyecto p JOIN cliente c USING (id_cliente) WHERE p.id_proyecto = :id', ['id' => $pid])->fetch(PDO::FETCH_ASSOC);
            if (!$proyecto) responder(['ok' => false, 'mensaje' => 'Proyecto no encontrado.'], 404);
            if ($accion === 'obtener') $r = $proyecto;
            elseif ($accion === 'empleados_listar') {
                $r = proyecto_query($db, "SELECT pe.*, e.nombres || ' ' || e.apellidos AS nombre, e.telefono FROM proyecto_empleado pe JOIN empleado e USING (id_empleado) WHERE pe.id_proyecto = :id ORDER BY e.nombres, e.apellidos", ['id' => $pid])->fetchAll(PDO::FETCH_ASSOC);
            } elseif (in_array($accion, ['empleados_crear', 'empleados_editar'], true)) {
                $eid = proyecto_id($d['id_empleado'] ?? null);
                $v = ['funcion_en_proyecto' => proyecto_texto($d, 'funcion_en_proyecto', 100), 'fecha_asignacion' => proyecto_fecha($d, 'fecha_asignacion'), 'estado' => proyecto_estado($db, $d, 'proyecto_empleado')];
                $r = $accion === 'empleados_crear'
                    ? proyecto_guardar($db, 'proyecto_empleado', $v + ['id_proyecto' => $pid, 'id_empleado' => $eid])
                    : proyecto_guardar($db, 'proyecto_empleado', $v, 'id_proyecto = :pid AND id_empleado = :eid', ['pid' => $pid, 'eid' => $eid]);
            } elseif ($accion === 'presupuestos_crear') {
                $v = ['version' => proyecto_id($d['version'] ?? null), 'fecha_registro' => proyecto_fecha($d, 'fecha_registro'), 'estado' => proyecto_estado($db, $d, 'presupuesto'), 'observaciones' => proyecto_texto($d, 'observaciones', 100000), 'id_proyecto' => $pid, 'creado_por' => $usuario];
                // Serializa las versiones del mismo proyecto sin modificar el esquema.
                $db->beginTransaction();
                proyecto_query($db, 'SELECT id_proyecto FROM proyecto WHERE id_proyecto = :id FOR UPDATE', ['id' => $pid]);
                if (proyecto_query($db, 'SELECT 1 FROM presupuesto WHERE id_proyecto = :id AND version = :version', ['id' => $pid, 'version' => $v['version']])->fetchColumn()) {
                    $db->rollBack();
                    responder(['ok' => false, 'mensaje' => 'Esta versión ya existe en el proyecto.'], 409);
                }
                $r = proyecto_guardar($db, 'presupuesto', $v);
                $db->commit();
            } elseif (in_array($accion, ['presupuestos_listar', 'presupuestos_obtener', 'detalles_crear', 'detalles_editar'], true)) {
                $sql = "SELECT p.*, u.nombre_usuario AS creador, COALESCE((SELECT SUM(d.cantidad * d.precio_unitario) FROM detalle_presupuesto d WHERE d.id_presupuesto = p.id_presupuesto), 0) AS total FROM presupuesto p JOIN usuario u ON u.id_usuario = p.creado_por WHERE p.id_proyecto = :pid";
                if ($accion === 'presupuestos_listar') $r = proyecto_query($db, $sql . ' ORDER BY p.version DESC, p.id_presupuesto DESC', ['pid' => $pid])->fetchAll(PDO::FETCH_ASSOC);
                else {
                    $bid = proyecto_id($d['id_presupuesto'] ?? null);
                    $presupuesto = proyecto_query($db, $sql . ' AND p.id_presupuesto = :bid', ['pid' => $pid, 'bid' => $bid])->fetch(PDO::FETCH_ASSOC);
                    if (!$presupuesto) responder(['ok' => false, 'mensaje' => 'Presupuesto no encontrado en este proyecto.'], 404);
                    if ($accion === 'presupuestos_obtener') {
                        $r = $presupuesto;
                        $r['detalles'] = proyecto_query($db, 'SELECT d.*, c.nombre AS categoria, m.nombre AS material, d.cantidad * d.precio_unitario AS subtotal FROM detalle_presupuesto d JOIN categoria_costo c USING (id_categoria_costo) LEFT JOIN material m USING (id_material) WHERE d.id_presupuesto = :id ORDER BY d.id_detalle_presupuesto', ['id' => $bid])->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $v = ['concepto' => proyecto_texto($d, 'concepto', 180, true), 'cantidad' => proyecto_decimal($d, 'cantidad', 10, true), 'precio_unitario' => proyecto_decimal($d, 'precio_unitario', 12, false), 'observaciones' => proyecto_texto($d, 'observaciones', 250), 'id_categoria_costo' => proyecto_id($d['id_categoria_costo'] ?? null), 'id_material' => ($d['id_material'] ?? '') === '' || ($d['id_material'] ?? null) === null ? null : proyecto_id($d['id_material'])];
                        $r = $accion === 'detalles_crear'
                            ? proyecto_guardar($db, 'detalle_presupuesto', $v + ['id_presupuesto' => $bid])
                            : proyecto_guardar($db, 'detalle_presupuesto', $v, 'id_detalle_presupuesto = :did AND id_presupuesto = :bid', ['did' => proyecto_id($d['id_detalle_presupuesto'] ?? null), 'bid' => $bid]);
                    }
                }
            } else responder(['ok' => false, 'mensaje' => 'Operación no encontrada.'], 404);
        }
        responder(['ok' => true, 'datos' => $r, 'mensaje' => 'Operación completada.'], str_ends_with($accion, 'crear') ? 201 : 200);
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        $codigo = $e instanceof PDOException ? (string) $e->getCode() : '';
        responder(['ok' => false, 'mensaje' => match ($codigo) {
            '23505' => 'El registro ya existe. Si es un empleado, edita su asignación actual.',
            '23503' => 'El cliente, empleado, categoría o material seleccionado ya no está disponible.',
            default => 'No se pudo completar la operación. Intenta nuevamente.'
        }], in_array($codigo, ['23505', '23503'], true) ? 409 : 500);
    }
}
