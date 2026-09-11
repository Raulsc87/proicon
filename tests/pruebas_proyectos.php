<?php
// Integración HTTP local. La sesión de prueba sólo se crea desde CLI.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.use_cookies', '0');
session_cache_limiter('');
$sesion = bin2hex(random_bytes(24));
$marca = 'TEST_' . bin2hex(random_bytes(10));
$db = null;
$originales = [];
$fallo = false;
$pid = null;
function verificar(bool $ok, string $texto): void {
    if (!$ok) throw new RuntimeException($texto);
    echo "OK: $texto\n";
}
function consulta(PDO $db, string $sql, array $params = []): PDOStatement {
    $q = $db->prepare($sql); $q->execute($params); return $q;
}
function peticion(string $accion, ?array $datos = null, array $params = [], bool $auth = true): array {
    global $sesion;
    $opts = ['method' => $datos === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 15,
        'header' => "Content-Type: application/json\r\n" . ($auth ? "Cookie: PHPSESSID=$sesion\r\n" : '')];
    if ($datos !== null) $opts['content'] = json_encode($datos);
    $texto = file_get_contents('http://localhost:8000/api/proyectos_' . $accion . '.php?' . http_build_query($params), false, stream_context_create(['http' => $opts]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), json_decode($texto, true, 512, JSON_THROW_ON_ERROR)];
}
try {
    ob_start(); require __DIR__ . '/../config/database.local.php'; ob_end_clean();
    $db = $conexion; $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (['proyecto', 'proyecto_empleado', 'presupuesto', 'detalle_presupuesto', 'cliente', 'proveedor', 'material', 'empleado', 'categoria_costo'] as $tabla) {
        $originales[$tabla] = consulta($db, "SELECT row_to_json(t)::text AS registro FROM $tabla t ORDER BY registro")->fetchAll(PDO::FETCH_COLUMN);
    }
    $uid = consulta($db, 'SELECT id_usuario FROM usuario ORDER BY id_usuario LIMIT 1')->fetchColumn();
    verificar((bool) $uid, 'hay un usuario real para la sesión temporal');
    session_id($sesion); session_start(); $_SESSION = ['id_usuario' => $uid, 'nombre' => 'Prueba local', 'rol' => 'PRUEBA']; session_write_close();
    $lecturas = ['catalogos', 'listar', 'obtener', 'empleados_listar', 'presupuestos_listar', 'presupuestos_obtener'];
    $escrituras = ['crear', 'editar', 'estado', 'empleados_crear', 'empleados_editar', 'presupuestos_crear', 'presupuestos_editar', 'detalles_crear', 'detalles_editar'];
    foreach (array_merge($lecturas, $escrituras) as $a) {
        [$s] = peticion($a, in_array($a, $escrituras, true) ? ['id' => 1] : null, [], false);
        verificar($s === 401, "$a rechaza acceso sin sesión");
    }
    [$s, $r] = peticion('catalogos'); $c = $r['datos'];
    verificar($s === 200 && count($c['clientes']) > 0 && count($c['empleados']) > 0 && count($c['categorias']) > 0 && count($c['materiales']) > 0, 'catálogos reales disponibles');
    [$s, $r] = peticion('listar');
    verificar($s === 200 && count($r['datos']) === count($originales['proyecto']), 'listar proyectos originales');
    $existente = $r['datos'][0]['id_proyecto'] ?? null;
    if ($existente) { [$s, $r] = peticion('obtener', null, ['id' => $existente]); verificar($s === 200 && isset($r['datos']['cliente']), 'abrir detalle original con cliente'); }
    $p = ['codigo' => $marca, 'nombre' => $marca, 'id_cliente' => $c['clientes'][0]['id_cliente'], 'estado' => $c['estados'][0], 'estado_avance' => $c['avances'][0], 'fecha_inicio' => '2026-01-01', 'fecha_fin_estimada' => '2026-12-31'];
    [$s] = peticion('crear', array_replace($p, ['fecha_inicio' => '2026-02-30'])); verificar($s === 400, 'rechaza fecha inválida');
    [$s] = peticion('crear', array_replace($p, ['estado' => 'INVENTADO'])); verificar($s === 400, 'rechaza estado no existente');
    [$s] = peticion('crear', array_replace($p, ['nombre' => str_repeat('a', 151)])); verificar($s === 400, 'valida longitud de nombre');
    [$s, $r] = peticion('crear', $p); verificar($s === 201, 'crear proyecto temporal con cliente real'); $pid = $r['datos']['id_proyecto'];
    foreach (['codigo', 'nombre'] as $campo) { [$s, $r] = peticion('listar', null, ['buscar' => $p[$campo]]); verificar($s === 200 && count($r['datos']) === 1, "buscar por $campo"); }
    $p['nombre'] .= '_EDITADO';
    [$s, $r] = peticion('editar', $p + ['id' => $pid]); verificar($s === 200 && $r['datos']['nombre'] === $p['nombre'], 'editar proyecto temporal');
    [$s, $r] = peticion('obtener', null, ['id' => $pid]); verificar($s === 200 && (int) $r['datos']['id_cliente'] === (int) $p['id_cliente'], 'persistencia y relación con cliente');
    foreach ($c['avances'] as $avance) { [$s, $r] = peticion('editar', array_replace($p, ['id' => $pid, 'estado_avance' => $avance])); verificar($s === 200 && $r['datos']['estado_avance'] === $avance, "edita avance $avance"); }
    [$s, $r] = peticion('estado', ['id' => $pid, 'estado' => $c['estados'][0]]); verificar($s === 200, 'guardar estado existente sin borrar proyecto');
    $e = ['id_proyecto' => $pid, 'id_empleado' => $c['empleados'][0]['id_empleado'], 'funcion_en_proyecto' => 'Supervisor temporal', 'fecha_asignacion' => '2026-09-10', 'estado' => $c['estados_empleado'][0]];
    [$s] = peticion('empleados_crear', $e); verificar($s === 201, 'asignar empleado real');
    [$s] = peticion('empleados_crear', $e); verificar($s === 409, 'rechaza empleado duplicado');
    $e['funcion_en_proyecto'] = 'Encargado de compras';
    [$s, $r] = peticion('empleados_editar', $e); verificar($s === 200 && $r['datos']['funcion_en_proyecto'] === $e['funcion_en_proyecto'], 'editar función y estado de asignación');
    foreach (['INACTIVO', 'ACTIVO'] as $estado) {
        [$s, $r] = peticion('empleados_editar', array_replace($e, ['estado' => $estado]));
        verificar($s === 200 && $r['datos']['estado'] === $estado, 'asignación cambia a ' . $estado);
    }
    [$s, $r] = peticion('empleados_listar', null, ['id_proyecto' => $pid]); verificar($s === 200 && count($r['datos']) === 1 && isset($r['datos'][0]['nombre']), 'consultar empleados asignados');
    $b = ['id_proyecto' => $pid, 'version' => 1, 'fecha_registro' => '2026-09-10', 'estado' => $c['estados_presupuesto'][0], 'creado_por' => 2147483647];
    [$s, $r] = peticion('presupuestos_crear', $b); verificar($s === 201 && (int) $r['datos']['creado_por'] === (int) $uid, 'crear presupuesto con autor tomado de sesión'); $bid = $r['datos']['id_presupuesto'];
    verificar($r['datos']['estado'] === 'BORRADOR', 'crear presupuesto BORRADOR');
    foreach (['PENDIENTE', 'APROBADO', 'RECHAZADO'] as $estado) {
        [$s, $r] = peticion('presupuestos_editar', array_replace($b, ['id_presupuesto' => $bid, 'estado' => $estado]));
        verificar($s === 200 && $r['datos']['estado'] === $estado && (int) $r['datos']['creado_por'] === (int) $uid, 'presupuesto cambia a ' . $estado . ' conservando autor');
    }
    [$s] = peticion('presupuestos_editar', array_replace($b, ['id_presupuesto' => $bid, 'estado' => 'INVALIDO'])); verificar($s === 400, 'rechaza estado de presupuesto inválido');
    if ($existente) { [$s] = peticion('presupuestos_editar', array_replace($b, ['id_presupuesto' => $bid, 'id_proyecto' => $existente])); verificar($s === 404, 'rechaza edición de presupuesto de otro proyecto'); }
    [$s] = peticion('presupuestos_crear', $b); verificar($s === 409, 'rechaza versión duplicada');
    [$s] = peticion('presupuestos_crear', array_replace($b, ['version' => 2])); verificar($s === 201, 'crear segunda versión');
    $d = ['id_proyecto' => $pid, 'id_presupuesto' => $bid, 'concepto' => 'Material temporal', 'id_categoria_costo' => $c['categorias'][0]['id_categoria_costo'], 'id_material' => $c['materiales'][0]['id_material'], 'cantidad' => '450', 'precio_unitario' => '82.50'];
    [$s] = peticion('detalles_crear', array_replace($d, ['cantidad' => '-1'])); verificar($s === 400, 'rechaza cantidad negativa');
    [$s] = peticion('detalles_crear', array_replace($d, ['precio_unitario' => '1.234'])); verificar($s === 400, 'rechaza precisión excesiva');
    [$s] = peticion('detalles_crear', array_replace($d, ['id_material' => 2147483647])); verificar($s === 409, 'rechaza material inexistente');
    [$s, $r] = peticion('detalles_crear', $d); verificar($s === 201, 'agregar detalle con material'); $did = $r['datos']['id_detalle_presupuesto'];
    [$s] = peticion('detalles_crear', array_replace($d, ['concepto' => 'Mano de obra temporal', 'id_material' => '', 'cantidad' => '1', 'precio_unitario' => '95000'])); verificar($s === 201, 'agregar detalle sin material');
    [$s, $r] = peticion('presupuestos_obtener', null, ['id_proyecto' => $pid, 'id_presupuesto' => $bid]);
    verificar($s === 200 && (float) $r['datos']['detalles'][0]['subtotal'] === 37125.0 && (float) $r['datos']['total'] === 132125.0 && $r['datos']['detalles'][1]['id_material'] === null, 'subtotal 37125.00 y SUM total 132125.00, material opcional NULL');
    [$s] = peticion('detalles_editar', array_replace($d, ['id_detalle_presupuesto' => $did, 'cantidad' => '2'])); verificar($s === 200, 'editar detalle');
    [$s, $r] = peticion('presupuestos_listar', null, ['id_proyecto' => $pid]); verificar($s === 200 && count($r['datos']) === 2 && (float) $r['datos'][1]['total'] === 95165.0, 'total recalculado 95165.00 y dos versiones');
    if ($existente) {
        [$s] = peticion('presupuestos_obtener', null, ['id_proyecto' => $existente, 'id_presupuesto' => $bid]); verificar($s === 404, 'rechaza presupuesto de otro proyecto');
        [$s] = peticion('detalles_editar', array_replace($d, ['id_proyecto' => $existente, 'id_detalle_presupuesto' => $did])); verificar($s === 404, 'rechaza edición fuera del contexto del proyecto');
    }
    [$s] = peticion('obtener', null, ['id' => 'incorrecto']); verificar($s === 400, 'rechaza ID inválido');
    [$s] = peticion('obtener', null, ['id' => 2147483647]); verificar($s === 404, 'proyecto inexistente devuelve 404');
    [$s] = peticion('crear'); verificar($s === 405, 'rechaza método incorrecto');
    foreach (['proyectos.html', 'proyecto_detalle.html?id=' . $pid] as $pagina) {
        $html = file_get_contents('http://localhost:8000/' . $pagina);
        verificar(str_contains($html, 'proyectos_comun.js'), "$pagina disponible por HTTP");
    }
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    echo 'FALLO: ' . ($e instanceof PDOException ? 'Error de base de datos en prueba.' : $e->getMessage()) . PHP_EOL;
    $fallo = true;
} finally {
    if ($db instanceof PDO) {
        try {
            $db->beginTransaction();
            // Sólo elimina proyectos con el código aleatorio exclusivo de esta ejecución.
            $ids = consulta($db, 'SELECT id_proyecto FROM proyecto WHERE codigo = :marca', ['marca' => $marca])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                foreach (['DELETE FROM detalle_presupuesto WHERE id_presupuesto IN (SELECT id_presupuesto FROM presupuesto WHERE id_proyecto = :id)', 'DELETE FROM presupuesto WHERE id_proyecto = :id', 'DELETE FROM proyecto_empleado WHERE id_proyecto = :id', 'DELETE FROM proyecto WHERE id_proyecto = :id'] as $sql) {
                    $q = consulta($db, $sql, ['id' => $id]); echo 'Limpieza: ' . $q->rowCount() . " registros temporales eliminados.\n";
                }
            }
            $db->commit();
            foreach ($originales as $tabla => $filas) verificar(consulta($db, "SELECT row_to_json(t)::text AS registro FROM $tabla t ORDER BY registro")->fetchAll(PDO::FETCH_COLUMN) === $filas, "$tabla conserva todos sus registros originales");
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo "FALLO: limpieza o verificación final incompleta.\n"; $fallo = true;
        }
    }
    session_id($sesion); session_start(); $_SESSION = []; session_destroy();
}
exit($fallo ? 1 : 0);
