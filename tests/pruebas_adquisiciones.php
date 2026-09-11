<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.use_cookies', '0'); session_cache_limiter('');
$marca = 'ADQ_' . bin2hex(random_bytes(8));
$sesion = bin2hex(random_bytes(24)); $db = null; $uid = null; $sid = null; $fallo = false; $originales = []; $archivos = []; $proyectoTemporal = null;
function aq(PDO $db, string $sql, array $p = []): PDOStatement { $q = $db->prepare($sql); $q->execute($p); return $q; }
function ok(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); echo "OK: $m\n"; }
function http_adq(string $ruta, string $metodo = 'GET', ?string $body = null, string $tipo = 'application/json', bool $auth = true): array {
    global $sesion;
    $o = ['method' => $metodo, 'ignore_errors' => true, 'timeout' => 20, 'header' => "Content-Type: $tipo\r\n" . ($auth ? "Cookie: PHPSESSID=$sesion\r\n" : '')];
    if ($body !== null) $o['content'] = $body;
    $texto = file_get_contents('http://localhost:8000/' . $ruta, false, stream_context_create(['http' => $o]));
    if ($auth) foreach ($http_response_header as $h) {
        if (preg_match('/^Set-Cookie: PHPSESSID=([a-zA-Z0-9,-]+)/i', $h, $cookie)) $sesion = $cookie[1];
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), $texto, $http_response_header];
}
function adq(string $a, ?array $d = null, array $get = [], bool $auth = true, ?array $file = null, string $endpoint = 'adquisiciones'): array {
    $tipo = 'application/json'; $body = $d === null ? null : json_encode($d);
    if ($file !== null) {
        $boundary = 'Boundary' . bin2hex(random_bytes(12)); $body = '';
        foreach ($d as $k => $v) $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        if ($file) $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"{$file[0]}\"\r\nContent-Type: {$file[1]}\r\n\r\n{$file[2]}\r\n";
        $body .= "--$boundary--\r\n"; $tipo = 'multipart/form-data; boundary=' . $boundary;
    }
    [$s, $t] = http_adq('api/' . $endpoint . '.php?' . http_build_query(['accion' => $a] + $get), $d === null ? 'GET' : 'POST', $body, $tipo, $auth);
    return [$s, json_decode($t, true, 512, JSON_THROW_ON_ERROR)];
}
try {
    ob_start(); require __DIR__ . '/../config/database.local.php'; ob_end_clean(); $db = $conexion; $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ok(extension_loaded('fileinfo'), 'fileinfo nativo disponible');
    foreach (['solicitud_material', 'detalle_solicitud', 'compra', 'detalle_compra', 'factura', 'pago', 'usuario', 'cliente', 'proveedor', 'material', 'proyecto', 'proyecto_empleado', 'presupuesto', 'detalle_presupuesto', 'actividad', 'bitacora', 'fotografia_avance'] as $tabla) $originales[$tabla] = aq($db, "SELECT row_to_json(t)::text AS r FROM $tabla t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN);
    $base = aq($db, 'SELECT id_empleado, id_rol FROM usuario ORDER BY id_usuario LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $clave = bin2hex(random_bytes(24));
    $uid = aq($db, "INSERT INTO usuario (nombre_usuario, contrasena, estado, id_empleado, id_rol) VALUES (:nombre, :clave, 'ACTIVO', :empleado, :rol) RETURNING id_usuario", ['nombre' => $marca, 'clave' => $clave, 'empleado' => $base['id_empleado'], 'rol' => $base['id_rol']])->fetchColumn();
    session_id($sesion); session_start(); session_write_close();
    [$s] = http_adq('api/login.php'); ok($s === 405, 'login exige POST');
    foreach (['{', 'null', '{"usuario":[],"contrasena":"x"}'] as $entrada) {
        [$s, $t] = http_adq('api/login.php', 'POST', $entrada); ok($s === 400 && !json_decode($t, true)['ok'], 'login rechaza entrada malformada sin error interno');
    }
    [$s] = http_adq('tests/prueba_conexion.php'); ok($s === 404, 'diagnóstico de conexión no se expone por HTTP');
    $sesionInicial = $sesion;
    [$s] = http_adq('api/login.php', 'POST', json_encode(['usuario' => $marca, 'contrasena' => 'incorrecta'])); ok($s === 401, 'login rechaza contraseña incorrecta');
    [$s, $t] = http_adq('api/login.php', 'POST', json_encode(['usuario' => $marca, 'contrasena' => $clave])); ok($s === 200 && json_decode($t, true)['ok'], 'login real con usuario temporal');
    unset($clave);
    ok($sesionInicial !== $sesion, 'login renueva identificador de sesión');
    [$s, $t] = http_adq('api/sesion.php'); ok($s === 200 && json_decode($t, true)['activa'], 'sesión activa después del login');
    [$s, $t] = http_adq('api/proyectos_catalogos.php'); ok($s === 200, 'catálogos para flujo completo'); $pc = json_decode($t, true)['datos'];
    $proyecto = ['codigo' => $marca, 'nombre' => $marca, 'id_cliente' => $pc['clientes'][0]['id_cliente'], 'estado' => $pc['estados'][0], 'estado_avance' => $pc['avances'][0], 'fecha_inicio' => '2026-09-01', 'fecha_fin_estimada' => '2026-12-31'];
    [$s, $t] = http_adq('api/proyectos_crear.php', 'POST', json_encode($proyecto)); ok($s === 201, 'flujo: crear proyecto temporal desde API'); $pid = $proyectoTemporal = json_decode($t, true)['datos']['id_proyecto'];
    [$s] = http_adq('api/proyectos_presupuestos_crear.php', 'POST', json_encode(['id_proyecto' => $pid, 'version' => 1, 'fecha_registro' => '2026-09-10', 'estado' => 'BORRADOR'])); ok($s === 201, 'flujo: presupuesto BORRADOR del proyecto');
    [$s, $r] = adq('listar', null, ['id_proyecto' => $pid], true, null, 'avances'); ok($s === 200, 'catálogos de actividad');
    [$s] = adq('crear', ['id_proyecto' => $pid, 'nombre' => $marca, 'estado' => $r['datos']['estados'][0], 'situacion_tiempo' => $r['datos']['situaciones'][0], 'porcentaje_avance' => '0'], [], true, null, 'avances'); ok($s === 201, 'flujo: actividad del proyecto');
    $ctx = ['id_proyecto' => $pid];
    $reads = ['catalogos', 'solicitudes_listar', 'solicitudes_obtener', 'compras_obtener', 'facturas_obtener', 'archivo'];
    $writes = ['solicitudes_crear', 'solicitudes_revisar', 'solicitud_detalle_guardar', 'solicitud_detalle_quitar', 'compras_crear', 'compra_detalle_guardar', 'compras_estado', 'facturas_crear', 'pagos_crear'];
    foreach (array_merge($reads, $writes) as $a) { [$s] = adq($a, in_array($a, $writes, true) ? $ctx : null, $ctx, false); ok($s === 401, "$a exige sesión"); }
    [$s, $r] = adq('catalogos', null, $ctx); ok($s === 200, 'catálogos reales de adquisición'); $cat = $r['datos'];
    [$s, $r] = adq('solicitudes_listar', null, $ctx); ok($s === 200, 'listar solicitudes existentes del proyecto');
    $sol = aq($db, 'SELECT id_solicitud, id_proyecto FROM solicitud_material WHERE codigo = :codigo LIMIT 1', ['codigo' => 'SOL-001'])->fetch(PDO::FETCH_ASSOC);
    ok((bool) $sol, 'SOL-001 existe');
    [$s, $r] = adq('solicitudes_obtener', null, ['id_proyecto' => $sol['id_proyecto'], 'id_solicitud' => $sol['id_solicitud']]); ok($s === 200 && count($r['datos']['detalles']) > 0, 'consultar SOL-001 y sus materiales');
    $sd = $ctx + ['codigo' => $marca, 'fecha_solicitud' => '2026-09-10', 'fecha_necesaria' => '2026-10-01', 'estado' => $cat['estados_solicitud'][0], 'solicitada_por' => 2147483647, 'revisada_por' => 2147483647, 'id_actividad' => 2147483647];
    [$s] = adq('solicitudes_crear', array_replace($sd, ['estado' => 'INVENTADO'])); ok($s === 400, 'rechaza estado inventado');
    [$s] = adq('solicitudes_crear', array_replace($sd, ['fecha_solicitud' => '2026-02-30'])); ok($s === 400, 'rechaza fecha inválida');
    [$s] = adq('solicitudes_crear', array_replace($sd, ['fecha_necesaria' => '2026-09-01'])); ok($s === 400, 'rechaza fecha necesaria anterior a solicitud');
    [$s, $r] = adq('solicitudes_crear', $sd); ok($s === 201, 'crear solicitud temporal'); $sid = $r['datos']['id_solicitud'];
    ok((int) $r['datos']['solicitada_por'] === (int) $uid && $r['datos']['revisada_por'] === null && $r['datos']['id_actividad'] === null, 'autor de sesión y campos opcionales NULL');
    $sc = $ctx + ['id_solicitud' => $sid];
    [$s] = adq('solicitudes_revisar', $sc); ok($s === 409, 'no revisa solicitud vacía');
    $cd = $sc + ['codigo' => $marca, 'id_proveedor' => $cat['proveedores'][0]['id_proveedor'], 'fecha_compra' => '2026-09-10', 'estado' => $cat['estados_compra'][0], 'creada_por' => 2147483647];
    [$s] = adq('compras_crear', $cd); ok($s === 409, 'no crea compra sin revisión');
    $ds = $sc + ['id_material' => $cat['materiales'][0]['id_material'], 'cantidad' => '180', 'observaciones' => 'Temporal'];
    [$s, $r] = adq('solicitud_detalle_guardar', $ds); ok($s === 200, 'agregar material sin precio'); $dsid = $r['datos']['id_detalle_solicitud'];
    [$s] = adq('solicitud_detalle_guardar', $ds); ok($s === 409, 'rechaza material duplicado en solicitud');
    [$s] = adq('solicitudes_revisar', $sc); ok($s === 200, 'revisar solicitud con usuario autenticado');
    [$s] = adq('solicitud_detalle_guardar', $ds + ['id_detalle_solicitud' => $dsid]); ok($s === 200, 'editar cantidad de solicitud');
    [$s, $r] = adq('solicitudes_obtener', null, $sc); ok($r['datos']['revisada_por'] === null, 'edición invalida revisión anterior');
    $ds2 = array_replace($ds, ['id_material' => $cat['materiales'][1]['id_material'], 'cantidad' => '800']);
    [$s, $r] = adq('solicitud_detalle_guardar', $ds2); ok($s === 200, 'agregar segundo material'); $ds2id = $r['datos']['id_detalle_solicitud'];
    [$s] = adq('solicitud_detalle_quitar', $sc + ['id_detalle_solicitud' => $ds2id]); ok($s === 200, 'quitar detalle sin relaciones posteriores');
    [$s] = adq('solicitud_detalle_guardar', $ds2); ok($s === 200, 'reponer segundo material temporal');
    [$s, $r] = adq('solicitudes_revisar', $sc); ok($s === 200 && (int) $r['datos']['revisada_por'] === (int) $uid, 'revisor procede de sesión');
    [$s] = adq('compras_crear', array_replace($cd, ['id_proveedor' => 2147483647])); ok($s === 409, 'rechaza proveedor inexistente');
    [$s, $r] = adq('compras_crear', $cd); ok($s === 201 && (int) $r['datos']['creada_por'] === (int) $uid, 'compra asociada a solicitud y proveedor con autor de sesión'); $cid = $r['datos']['id_compra']; $cc = $ctx + ['id_compra' => $cid];
    [$s, $r] = adq('compras_crear', array_replace($cd, ['codigo' => $marca . '_2'])); ok($s === 201, 'segunda compra para la misma solicitud'); $cid2 = $r['datos']['id_compra'];
    [$s] = adq('solicitud_detalle_quitar', $sc + ['id_detalle_solicitud' => $dsid]); ok($s === 409, 'no quita detalle con compras posteriores');
    [$s] = adq('solicitud_detalle_guardar', $ds + ['id_detalle_solicitud' => $dsid]); ok($s === 409, 'no edita solicitud con compras');
    [$s, $r] = adq('compras_obtener', null, $cc); ok($s === 200 && count($r['datos']['solicitados']) === 2, 'materiales disponibles para copiar desde solicitud');
    $dc = $cc + ['id_material' => $ds['id_material'], 'cantidad' => '180', 'precio_unitario' => '81.75'];
    [$s] = adq('compra_detalle_guardar', array_replace($dc, ['cantidad' => '181'])); ok($s === 409, 'rechaza compra mayor a lo solicitado');
    [$s] = adq('compra_detalle_guardar', array_replace($dc, ['cantidad' => '-1'])); ok($s === 400, 'rechaza cantidad negativa');
    [$s, $r] = adq('compra_detalle_guardar', $dc); ok($s === 200, 'copiar material y cantidad con precio de compra'); $dcid = $r['datos']['id_detalle_compra'];
    [$s] = adq('compra_detalle_guardar', $dc); ok($s === 409, 'rechaza duplicado de compra');
    [$s] = adq('compra_detalle_guardar', array_replace($dc, ['id_compra' => $cid2, 'cantidad' => '1'])); ok($s === 409, 'evita exceso acumulado entre compras');
    [$s] = adq('compra_detalle_guardar', $cc + ['id_material' => $ds2['id_material'], 'cantidad' => '800', 'precio_unitario' => '7.10']); ok($s === 200, 'segundo material con precio');
    [$s, $r] = adq('compras_obtener', null, $cc); ok($s === 200 && (float) $r['datos']['detalles'][0]['subtotal'] === 14715.0 && (float) $r['datos']['total'] === 20395.0, 'subtotal 14715.00 y total compra 20395.00');
    [$s] = adq('compra_detalle_guardar', $dc + ['id_detalle_compra' => $dcid]); ok($s === 200, 'editar detalle antes de facturar');
    [$s] = adq('compras_estado', $cc + ['estado' => 'RECIBIDA']); ok($s === 200, 'confirmar recepción con estado real');
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $fd = $cc + ['numero_factura' => $marca, 'fecha_factura' => '2026-09-10', 'monto_total' => '20400.00', 'estado' => $cat['estados_factura'][0], 'registrada_por' => 2147483647];
    [$s] = adq('facturas_crear', $fd, [], true, ['mal.php', 'application/pdf', $pdf]); ok($s === 400, 'rechaza extensión ejecutable');
    [$s] = adq('facturas_crear', $fd, [], true, ['falso.pdf', 'application/pdf', '<?php echo 1;']); ok($s === 400, 'rechaza MIME falso');
    [$s] = adq('facturas_crear', $fd, [], true, ['grande.pdf', 'application/pdf', $pdf . str_repeat('a', 2 * 1024 * 1024)]); ok($s === 400, 'rechaza archivo mayor de 2 MB');
    [$s, $r] = adq('facturas_crear', $fd, [], true, ['../../original.pdf', 'application/pdf', $pdf]); ok($s === 201 && (int) $r['datos']['registrada_por'] === (int) $uid, 'registrar factura con monto diferente y autor de sesión'); $fid = $r['datos']['id_factura']; $archivos[] = $r['datos']['ruta_archivo'];
    ok((bool) preg_match('~^uploads/facturas/[a-f0-9]{48}\.pdf$~D', end($archivos)), 'nombre de archivo aleatorio sin path traversal');
    [$s] = adq('facturas_crear', $fd, [], true, ['otro.pdf', 'application/pdf', $pdf]); ok($s === 409, 'rechaza factura duplicada');
    [$s] = adq('compra_detalle_guardar', $dc + ['id_detalle_compra' => $dcid]); ok($s === 409, 'no modifica compra facturada');
    [$s, $t] = http_adq('api/adquisiciones.php?' . http_build_query(['accion' => 'archivo', 'id_factura' => $fid] + $ctx)); ok($s === 200 && $t === $pdf, 'descargar factura autenticada conserva archivo');
    [$s] = http_adq(end($archivos)); ok($s === 404, 'router bloquea acceso directo a uploads');
    $fc = $ctx + ['id_factura' => $fid];
    $pd = $fc + ['tipo_pago' => $cat['tipos_pago'][0], 'numero_operacion' => $marca . '_1', 'fecha_pago' => '2026-09-10', 'monto' => '10000.00', 'observaciones' => 'Pago parcial temporal', 'registrado_por' => 2147483647];
    [$s, $r] = adq('pagos_crear', $pd, [], true, ['recibo.png', 'image/png', $png]); ok($s === 201 && (int) $r['datos']['registrado_por'] === (int) $uid, 'pago parcial con comprobante PNG y autor de sesión'); $pagoid = $r['datos']['id_pago']; $archivos[] = $r['datos']['ruta_comprobante'];
    [$s] = adq('pagos_crear', $pd, [], true, []); ok($s === 409, 'rechaza operación de pago repetida');
    [$s, $r] = adq('facturas_obtener', null, $fc); ok($s === 200 && (float) $r['datos']['total_pagado'] === 10000.0 && (float) $r['datos']['saldo'] === 10400.0, 'total parcial 10000.00 y saldo 10400.00');
    [$s, $r] = adq('pagos_crear', array_replace($pd, ['tipo_pago' => 'Cheque', 'numero_operacion' => $marca . '_2', 'monto' => '10400.00']), [], true, ['recibo.pdf', 'application/pdf', $pdf]); ok($s === 201, 'segundo pago y tipo libre permitido por VARCHAR'); $archivos[] = $r['datos']['ruta_comprobante'];
    [$s, $r] = adq('facturas_obtener', null, $fc); ok($s === 200 && count($r['datos']['pagos']) === 2 && (float) $r['datos']['total_pagado'] === 20400.0 && (float) $r['datos']['saldo'] === 0.0 && $r['datos']['estado'] === 'REGISTRADA', 'pago completo: saldo cero sin inventar estado persistente');
    [$s, $t] = http_adq('api/adquisiciones.php?' . http_build_query(['accion' => 'archivo', 'id_pago' => $pagoid] + $fc)); ok($s === 200 && $t === $png, 'descargar comprobante PNG');
    $otro = aq($db, 'SELECT id_proyecto FROM proyecto WHERE id_proyecto <> :id LIMIT 1', ['id' => $pid])->fetchColumn();
    if ($otro) {
        foreach (['solicitudes_obtener' => ['id_solicitud' => $sid], 'compras_obtener' => ['id_compra' => $cid], 'facturas_obtener' => ['id_factura' => $fid], 'archivo' => ['id_factura' => $fid]] as $a => $q) { [$s] = adq($a, null, $q + ['id_proyecto' => $otro]); ok($s === 404, "$a rechaza otro proyecto"); }
    }
    [$s] = adq('solicitudes_crear', null, $ctx); ok($s === 405, 'valida método HTTP');
    foreach (['solicitud_detalle.html', 'compra_detalle.html', 'factura_detalle.html', 'proyecto_detalle.html'] as $pagina) { [$s, $t] = http_adq($pagina); ok($s === 200 && str_contains($t, 'adquisiciones.js'), "$pagina disponible"); }
    [$s, $r] = adq('foto_subir', $ctx + ['descripcion' => $marca], [], true, ['temporal.png', 'image/png', $png], 'avances'); ok($s === 201, 'flujo: fotografía tras pago completo'); $archivos[] = $r['datos']['ruta_archivo'];
    ok((int) aq($db, 'SELECT id_proyecto FROM bitacora WHERE id_bitacora=:id', ['id' => $r['datos']['id_bitacora']])->fetchColumn() === (int) $pid, 'flujo: fotografía ligada al mismo proyecto mediante bitácora');
    [$s] = http_adq('api/logout.php', 'POST'); ok($s === 200, 'cerrar sesión');
    [$s, $t] = http_adq('api/sesion.php'); ok($s === 200 && !json_decode($t, true)['activa'], 'sesión cerrada');
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    echo 'FALLO: ' . ($e instanceof PDOException ? 'Error de base de datos en pruebas.' : $e->getMessage()) . PHP_EOL; $fallo = true;
} finally {
    if ($db instanceof PDO) {
        try {
            $db->beginTransaction();
            $solicitudes = aq($db, 'SELECT id_solicitud FROM solicitud_material WHERE codigo = :marca AND solicitada_por = :uid', ['marca' => $marca, 'uid' => $uid])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($solicitudes as $s) {
                // Recupera rutas incluso si una respuesta HTTP se perdió después del INSERT.
                $rutas = aq($db, 'SELECT f.ruta_archivo AS ruta FROM factura f JOIN compra c USING (id_compra) WHERE c.id_solicitud = :sid UNION ALL SELECT p.ruta_comprobante FROM pago p JOIN factura f USING (id_factura) JOIN compra c USING (id_compra) WHERE c.id_solicitud = :sid2', ['sid' => $s, 'sid2' => $s])->fetchAll(PDO::FETCH_COLUMN);
                $archivos = array_merge($archivos, $rutas);
                foreach (['DELETE FROM pago WHERE id_factura IN (SELECT f.id_factura FROM factura f JOIN compra c USING (id_compra) WHERE c.id_solicitud = :id)', 'DELETE FROM factura WHERE id_compra IN (SELECT id_compra FROM compra WHERE id_solicitud = :id)', 'DELETE FROM detalle_compra WHERE id_compra IN (SELECT id_compra FROM compra WHERE id_solicitud = :id)', 'DELETE FROM compra WHERE id_solicitud = :id', 'DELETE FROM detalle_solicitud WHERE id_solicitud = :id', 'DELETE FROM solicitud_material WHERE id_solicitud = :id'] as $sql) echo 'Limpieza: ' . aq($db, $sql, ['id' => $s])->rowCount() . " registros temporales.\n";
            }
            if ($proyectoTemporal) {
                $archivos = array_merge($archivos, aq($db, 'SELECT ruta_archivo FROM fotografia_avance WHERE id_bitacora IN (SELECT id_bitacora FROM bitacora WHERE id_proyecto=:id)', ['id' => $proyectoTemporal])->fetchAll(PDO::FETCH_COLUMN));
                foreach (['DELETE FROM fotografia_avance WHERE id_bitacora IN (SELECT id_bitacora FROM bitacora WHERE id_proyecto=:id)', 'DELETE FROM bitacora WHERE id_proyecto=:id', 'DELETE FROM actividad WHERE id_proyecto=:id', 'DELETE FROM presupuesto WHERE id_proyecto=:id', 'DELETE FROM proyecto WHERE id_proyecto=:id'] as $sql) echo 'Limpieza flujo: ' . aq($db, $sql, ['id' => $proyectoTemporal])->rowCount() . PHP_EOL;
            }
            echo 'Limpieza usuario: ' . aq($db, 'DELETE FROM usuario WHERE id_usuario = :id AND nombre_usuario = :marca', ['id' => $uid, 'marca' => $marca])->rowCount() . PHP_EOL;
            $db->commit();
            foreach (array_unique(array_filter($archivos)) as $ruta) {
                if (!preg_match('~^uploads/(?:facturas|pagos|avances)/[a-f0-9]{48}\.(?:pdf|png|jpe?g)$~D', $ruta)) throw new RuntimeException('Ruta de limpieza no válida');
                $real = realpath(__DIR__ . '/../' . $ruta); $raiz = realpath(__DIR__ . '/../uploads');
                if ($real && $raiz && str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $raiz) . '/')) ok(unlink($real), 'archivo temporal eliminado');
            }
            foreach ($originales as $tabla => $filas) ok(aq($db, "SELECT row_to_json(t)::text AS r FROM $tabla t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN) === $filas, "$tabla conserva originales");
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); echo "FALLO: limpieza o comparación incompleta.\n"; $fallo = true; }
    }
    session_id($sesion); session_start(); $_SESSION = []; session_destroy();
}
exit($fallo ? 1 : 0);
