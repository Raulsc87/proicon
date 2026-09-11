<?php
require_once __DIR__ . '/proyectos_base.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start();
$usuario = $_SESSION['id_usuario'] ?? null;
session_write_close();
if (!$usuario) responder(['ok' => false, 'mensaje' => 'Inicia sesión nuevamente.'], 401);

function adquisicion_error(string $mensaje, int $codigo = 400): never {
    responder(['ok' => false, 'mensaje' => $mensaje], $codigo);
}
function adquisicion_fila(PDO $db, string $sql, array $params): array {
    $r = proyecto_query($db, $sql, $params)->fetch(PDO::FETCH_ASSOC);
    if (!$r) adquisicion_error('Registro no encontrado en este proyecto.', 404);
    return $r;
}
function adquisicion_insertar(PDO $db, string $tabla, array $v): array {
    return proyecto_guardar($db, $tabla, $v);
}
function adquisicion_archivo(string $carpeta): ?string {
    $f = $_FILES['archivo'] ?? null;
    if (!$f || ($f['error'] ?? null) === UPLOAD_ERR_NO_FILE) return null;
    if (!is_array($f) || !isset($f['error'], $f['tmp_name'], $f['name']) || !is_int($f['error']) || $f['error'] !== UPLOAD_ERR_OK) adquisicion_error('No se pudo cargar el archivo. Máximo 2 MB.');
    if (!is_string($f['tmp_name']) || !is_string($f['name']) || !is_uploaded_file($f['tmp_name']) || filesize($f['tmp_name']) > 2 * 1024 * 1024) adquisicion_error('Archivo inválido o mayor de 2 MB.');
    if (!class_exists('finfo')) adquisicion_error('El servidor necesita la extensión nativa fileinfo para validar archivos.', 503);
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $permitidos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($permitidos[$ext]) || $mime !== $permitidos[$ext]) adquisicion_error('El archivo debe ser PDF, JPG, JPEG o PNG auténtico.');
    if ($ext !== 'pdf' && @getimagesize($f['tmp_name']) === false) adquisicion_error('La imagen no es válida.');
    $ruta = 'uploads/' . $carpeta;
    $directorio = __DIR__ . '/../' . $ruta;
    if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) throw new RuntimeException('storage');
    $ruta .= '/' . bin2hex(random_bytes(24)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $ruta)) throw new RuntimeException('upload');
    return $ruta;
}
function adquisicion_descargar(?string $ruta): never {
    if (!$ruta || !preg_match('~^(?:uploads/)?(?:facturas|pagos)/[A-Za-z0-9_.-]+\.(?:pdf|jpe?g|png)$~iD', $ruta)) adquisicion_error('Archivo no disponible.', 404);
    $raiz = realpath(__DIR__ . '/..');
    $archivo = realpath($raiz . '/' . $ruta);
    $directorio = realpath($raiz . '/' . dirname($ruta));
    if (!$archivo || !$directorio || !str_starts_with(str_replace('\\', '/', $archivo), str_replace('\\', '/', $raiz) . '/') || dirname($archivo) !== $directorio || !is_file($archivo)) adquisicion_error('El archivo registrado no está disponible en el servidor.', 404);
    if (!class_exists('finfo')) adquisicion_error('El servidor necesita la extensión nativa fileinfo para descargar archivos.', 503);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo);
    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) adquisicion_error('Formato no permitido.', 404);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . basename($archivo) . '"');
    header('Content-Length: ' . filesize($archivo));
    readfile($archivo); exit;
}

$lecturas = ['catalogos', 'solicitudes_listar', 'solicitudes_obtener', 'compras_obtener', 'facturas_obtener', 'archivo'];
$escrituras = ['solicitudes_crear', 'solicitudes_revisar', 'solicitud_detalle_guardar', 'solicitud_detalle_quitar', 'compras_crear', 'compra_detalle_guardar', 'compras_estado', 'facturas_crear', 'pagos_crear'];
$accion = $_GET['accion'] ?? '';
if (!is_string($accion) || !in_array($accion, array_merge($lecturas, $escrituras), true)) adquisicion_error('Operación no encontrada.', 404);
$escribir = in_array($accion, $escrituras, true);
if ($_SERVER['REQUEST_METHOD'] !== ($escribir ? 'POST' : 'GET')) { header('Allow: ' . ($escribir ? 'POST' : 'GET')); adquisicion_error('Método no permitido.', 405); }
$db = null; $subido = null;
ob_start();
try {
    require __DIR__ . '/../config/database.local.php';
    ob_end_clean();
    $db = $conexion;
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $d = $_GET;
    if ($escribir) {
        if (in_array($accion, ['facturas_crear', 'pagos_crear'], true)) $d = $_POST;
        else {
            try { $d = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { adquisicion_error('JSON inválido.'); }
            if (!is_array($d) || array_is_list($d)) adquisicion_error('Datos inválidos.');
        }
    }
    $pid = proyecto_id($d['id_proyecto'] ?? null);
    $proyecto = adquisicion_fila($db, 'SELECT id_proyecto, codigo, nombre FROM proyecto WHERE id_proyecto = :id', ['id' => $pid]);
    if ($escribir) $db->beginTransaction();
    // Serializar operaciones por proyecto protege revisiones, cantidades y pagos concurrentes.
    if ($escribir) proyecto_query($db, 'SELECT id_proyecto FROM proyecto WHERE id_proyecto = :id FOR UPDATE', ['id' => $pid]);
    $sqlSolicitud = 'SELECT s.*, u.nombre_usuario AS solicitante, r.nombre_usuario AS revisor, a.nombre AS actividad FROM solicitud_material s JOIN usuario u ON u.id_usuario = s.solicitada_por LEFT JOIN usuario r ON r.id_usuario = s.revisada_por LEFT JOIN actividad a ON a.id_actividad = s.id_actividad WHERE s.id_proyecto = :pid';
    $sqlCompra = 'SELECT c.*, p.nombre AS proveedor, s.codigo AS solicitud, u.nombre_usuario AS creador, COALESCE((SELECT SUM(d.cantidad * d.precio_unitario) FROM detalle_compra d WHERE d.id_compra = c.id_compra), 0) AS total FROM compra c JOIN proveedor p USING (id_proveedor) LEFT JOIN solicitud_material s USING (id_solicitud) JOIN usuario u ON u.id_usuario = c.creada_por WHERE c.id_proyecto = :pid';
    $sqlFactura = 'SELECT f.*, u.nombre_usuario AS registrador, c.id_proyecto, c.codigo AS compra, COALESCE((SELECT SUM(p.monto) FROM pago p WHERE p.id_factura = f.id_factura), 0) AS total_pagado, f.monto_total - COALESCE((SELECT SUM(p.monto) FROM pago p WHERE p.id_factura = f.id_factura), 0) AS saldo FROM factura f JOIN compra c USING (id_compra) JOIN usuario u ON u.id_usuario = f.registrada_por WHERE c.id_proyecto = :pid';
    if ($accion === 'catalogos') {
        $r = ['proyecto' => $proyecto,
            'estados_solicitud' => proyecto_estados($db, 'solicitud_material'), 'estados_compra' => proyecto_estados($db, 'compra'), 'estados_factura' => proyecto_estados($db, 'factura'), 'tipos_pago' => proyecto_estados($db, 'pago', 'tipo_pago'),
            'materiales' => proyecto_query($db, 'SELECT m.id_material, m.nombre, u.abreviatura AS unidad FROM material m JOIN unidad_medida u USING (id_unidad) ORDER BY m.nombre')->fetchAll(PDO::FETCH_ASSOC),
            'proveedores' => proyecto_query($db, 'SELECT id_proveedor, nombre FROM proveedor ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC)];
    } elseif ($accion === 'solicitudes_listar') {
        $r = proyecto_query($db, $sqlSolicitud . ' ORDER BY s.id_solicitud DESC', ['pid' => $pid])->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($accion === 'solicitudes_crear') {
        $r = adquisicion_insertar($db, 'solicitud_material', ['codigo' => proyecto_texto($d, 'codigo', 30, true), 'fecha_solicitud' => proyecto_fecha($d, 'fecha_solicitud'), 'fecha_necesaria' => proyecto_fecha($d, 'fecha_necesaria', false), 'estado' => proyecto_estado($db, $d, 'solicitud_material'), 'observaciones' => proyecto_texto($d, 'observaciones', 100000), 'id_proyecto' => $pid, 'id_actividad' => null, 'solicitada_por' => $usuario, 'revisada_por' => null]);
    } elseif (in_array($accion, ['solicitudes_obtener', 'solicitudes_revisar', 'solicitud_detalle_guardar', 'solicitud_detalle_quitar', 'compras_crear'], true)) {
        $sid = proyecto_id($d['id_solicitud'] ?? null);
        $s = adquisicion_fila($db, $sqlSolicitud . ' AND s.id_solicitud = :sid', ['pid' => $pid, 'sid' => $sid]);
        $tieneCompras = (bool) proyecto_query($db, 'SELECT 1 FROM compra WHERE id_solicitud = :sid LIMIT 1', ['sid' => $sid])->fetchColumn();
        if ($accion === 'solicitudes_obtener') {
            $r = $s; $r['proyecto'] = $proyecto; $r['editable'] = !$tieneCompras;
            $r['detalles'] = proyecto_query($db, 'SELECT d.*, m.nombre AS material, u.abreviatura AS unidad FROM detalle_solicitud d JOIN material m USING (id_material) JOIN unidad_medida u USING (id_unidad) WHERE d.id_solicitud = :id ORDER BY d.id_detalle_solicitud', ['id' => $sid])->fetchAll(PDO::FETCH_ASSOC);
            $r['compras'] = proyecto_query($db, $sqlCompra . ' AND c.id_solicitud = :sid ORDER BY c.id_compra DESC', ['pid' => $pid, 'sid' => $sid])->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($accion === 'solicitudes_revisar') {
            if ($tieneCompras) adquisicion_error('La solicitud ya tiene compras; su revisión se conserva.', 409);
            if (!proyecto_query($db, 'SELECT 1 FROM detalle_solicitud WHERE id_solicitud = :id LIMIT 1', ['id' => $sid])->fetchColumn()) adquisicion_error('Agrega materiales antes de revisar.', 409);
            $r = proyecto_guardar($db, 'solicitud_material', ['revisada_por' => $usuario], 'id_solicitud = :id', ['id' => $sid]);
        } elseif ($accion === 'compras_crear') {
            if (!$s['revisada_por'] || $s['estado'] !== 'APROBADA') adquisicion_error('La solicitud debe estar revisada y aprobada.', 409);
            $r = adquisicion_insertar($db, 'compra', ['codigo' => proyecto_texto($d, 'codigo', 30, true), 'fecha_compra' => proyecto_fecha($d, 'fecha_compra'), 'estado' => proyecto_estado($db, $d, 'compra'), 'observaciones' => proyecto_texto($d, 'observaciones', 100000), 'id_proyecto' => $pid, 'id_solicitud' => $sid, 'id_proveedor' => proyecto_id($d['id_proveedor'] ?? null), 'creada_por' => $usuario]);
        } else {
            if ($tieneCompras) adquisicion_error('No se pueden cambiar materiales de una solicitud con compras.', 409);
            $did = empty($d['id_detalle_solicitud']) ? null : proyecto_id($d['id_detalle_solicitud']);
            if ($did) adquisicion_fila($db, 'SELECT id_detalle_solicitud FROM detalle_solicitud WHERE id_detalle_solicitud = :id AND id_solicitud = :sid', ['id' => $did, 'sid' => $sid]);
            if ($accion === 'solicitud_detalle_quitar') {
                if (!$did) adquisicion_error('Selecciona el detalle.');
                proyecto_query($db, 'DELETE FROM detalle_solicitud WHERE id_detalle_solicitud = :id AND id_solicitud = :sid', ['id' => $did, 'sid' => $sid]); $r = ['eliminado' => true];
            } else {
                $mid = proyecto_id($d['id_material'] ?? null);
                if (proyecto_query($db, 'SELECT 1 FROM detalle_solicitud WHERE id_solicitud = :sid AND id_material = :mid AND id_detalle_solicitud <> :did', ['sid' => $sid, 'mid' => $mid, 'did' => $did ?? 0])->fetchColumn()) adquisicion_error('Este material ya existe; edita su cantidad.', 409);
                $v = ['id_material' => $mid, 'cantidad' => proyecto_decimal($d, 'cantidad', 10, true), 'observaciones' => proyecto_texto($d, 'observaciones', 250)];
                $r = $did ? proyecto_guardar($db, 'detalle_solicitud', $v, 'id_detalle_solicitud = :id', ['id' => $did]) : adquisicion_insertar($db, 'detalle_solicitud', $v + ['id_solicitud' => $sid]);
            }
            // Cualquier cambio de materiales exige revisar otra vez; no inventa un estado.
            proyecto_query($db, 'UPDATE solicitud_material SET revisada_por = NULL WHERE id_solicitud = :id', ['id' => $sid]);
        }
    } elseif (in_array($accion, ['compras_obtener', 'compra_detalle_guardar', 'compras_estado', 'facturas_crear'], true)) {
        $cid = proyecto_id($d['id_compra'] ?? null);
        $c = adquisicion_fila($db, $sqlCompra . ' AND c.id_compra = :cid', ['pid' => $pid, 'cid' => $cid]);
        $facturada = (bool) proyecto_query($db, 'SELECT 1 FROM factura WHERE id_compra = :id LIMIT 1', ['id' => $cid])->fetchColumn();
        if ($accion === 'compras_obtener') {
            $r = $c; $r['proyecto'] = $proyecto; $r['editable'] = !$facturada;
            $r['detalles'] = proyecto_query($db, 'SELECT d.*, m.nombre AS material, d.cantidad * d.precio_unitario AS subtotal FROM detalle_compra d JOIN material m USING (id_material) WHERE d.id_compra = :id ORDER BY d.id_detalle_compra', ['id' => $cid])->fetchAll(PDO::FETCH_ASSOC);
            $r['solicitados'] = proyecto_query($db, 'SELECT d.id_material, m.nombre AS material, SUM(d.cantidad) AS cantidad, SUM(d.cantidad) - COALESCE((SELECT SUM(dc.cantidad) FROM detalle_compra dc JOIN compra cc USING (id_compra) WHERE cc.id_solicitud = :sid2 AND dc.id_material = d.id_material), 0) AS disponible FROM detalle_solicitud d JOIN material m USING (id_material) WHERE d.id_solicitud = :sid GROUP BY d.id_material, m.nombre ORDER BY m.nombre', ['sid' => $c['id_solicitud'], 'sid2' => $c['id_solicitud']])->fetchAll(PDO::FETCH_ASSOC);
            $r['facturas'] = proyecto_query($db, $sqlFactura . ' AND f.id_compra = :cid ORDER BY f.id_factura DESC', ['pid' => $pid, 'cid' => $cid])->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($accion === 'compras_estado') {
            if (!proyecto_query($db, 'SELECT 1 FROM detalle_compra WHERE id_compra = :id LIMIT 1', ['id' => $cid])->fetchColumn()) adquisicion_error('Agrega materiales antes de confirmar recepción.', 409);
            $r = proyecto_guardar($db, 'compra', ['estado' => proyecto_estado($db, $d, 'compra')], 'id_compra = :id', ['id' => $cid]);
        } elseif ($accion === 'compra_detalle_guardar') {
            if ($facturada) adquisicion_error('La compra ya tiene facturas; sus materiales e importes no pueden editarse.', 409);
            $did = empty($d['id_detalle_compra']) ? null : proyecto_id($d['id_detalle_compra']);
            if ($did) adquisicion_fila($db, 'SELECT id_detalle_compra FROM detalle_compra WHERE id_detalle_compra = :id AND id_compra = :cid', ['id' => $did, 'cid' => $cid]);
            $mid = proyecto_id($d['id_material'] ?? null);
            $cantidad = proyecto_decimal($d, 'cantidad', 10, true);
            if (proyecto_query($db, 'SELECT 1 FROM detalle_compra WHERE id_compra = :cid AND id_material = :mid AND id_detalle_compra <> :did', ['cid' => $cid, 'mid' => $mid, 'did' => $did ?? 0])->fetchColumn()) adquisicion_error('El material ya está en la compra; edita su detalle.', 409);
            if ($c['id_solicitud']) {
                $disponible = proyecto_query($db, 'SELECT COALESCE((SELECT SUM(cantidad) FROM detalle_solicitud WHERE id_solicitud = :sid AND id_material = :mid), 0) - COALESCE((SELECT SUM(d.cantidad) FROM detalle_compra d JOIN compra c USING (id_compra) WHERE c.id_solicitud = :sid2 AND d.id_material = :mid2 AND d.id_detalle_compra <> :did), 0) >= CAST(:cantidad AS numeric)', ['sid' => $c['id_solicitud'], 'mid' => $mid, 'sid2' => $c['id_solicitud'], 'mid2' => $mid, 'did' => $did ?? 0, 'cantidad' => $cantidad])->fetchColumn();
                if (!$disponible) adquisicion_error('La cantidad supera lo solicitado o ya fue incluida en otra compra.', 409);
            }
            $v = ['id_material' => $mid, 'cantidad' => $cantidad, 'precio_unitario' => proyecto_decimal($d, 'precio_unitario', 12, false), 'observaciones' => proyecto_texto($d, 'observaciones', 250)];
            $r = $did ? proyecto_guardar($db, 'detalle_compra', $v, 'id_detalle_compra = :id', ['id' => $did]) : adquisicion_insertar($db, 'detalle_compra', $v + ['id_compra' => $cid]);
        } else {
            if ($c['estado'] !== 'RECIBIDA') adquisicion_error('La compra debe estar recibida para registrar factura.', 409);
            if (!proyecto_query($db, 'SELECT 1 FROM detalle_compra WHERE id_compra = :id LIMIT 1', ['id' => $cid])->fetchColumn()) adquisicion_error('La compra no tiene materiales.', 409);
            $v = ['numero_factura' => proyecto_texto($d, 'numero_factura', 60, true), 'fecha_factura' => proyecto_fecha($d, 'fecha_factura'), 'monto_total' => proyecto_decimal($d, 'monto_total', 12, true), 'estado' => proyecto_estado($db, $d, 'factura'), 'id_compra' => $cid, 'registrada_por' => $usuario];
            if (proyecto_query($db, 'SELECT 1 FROM factura WHERE id_compra = :id AND numero_factura = :numero', ['id' => $cid, 'numero' => $v['numero_factura']])->fetchColumn()) adquisicion_error('Esta factura ya está registrada en la compra.', 409);
            $subido = adquisicion_archivo('facturas');
            $r = adquisicion_insertar($db, 'factura', $v + ['ruta_archivo' => $subido]);
        }
    } else {
        $fid = proyecto_id($d['id_factura'] ?? null);
        $f = adquisicion_fila($db, $sqlFactura . ' AND f.id_factura = :fid', ['pid' => $pid, 'fid' => $fid]);
        if ($accion === 'facturas_obtener') {
            $r = $f; $r['proyecto'] = $proyecto;
            $r['pagos'] = proyecto_query($db, 'SELECT p.*, u.nombre_usuario AS registrador FROM pago p JOIN usuario u ON u.id_usuario = p.registrado_por WHERE p.id_factura = :id ORDER BY p.id_pago DESC', ['id' => $fid])->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($accion === 'archivo') {
            $ruta = $f['ruta_archivo'];
            if (isset($d['id_pago'])) $ruta = adquisicion_fila($db, 'SELECT ruta_comprobante FROM pago WHERE id_pago = :id AND id_factura = :fid', ['id' => proyecto_id($d['id_pago']), 'fid' => $fid])['ruta_comprobante'];
            adquisicion_descargar($ruta);
        } elseif ($accion === 'pagos_crear') {
            // tipo_pago es VARCHAR sin CHECK/ENUM: sugerencias reales y entrada libre.
            $v = ['tipo_pago' => proyecto_texto($d, 'tipo_pago', 30, true), 'numero_operacion' => proyecto_texto($d, 'numero_operacion', 100), 'fecha_pago' => proyecto_fecha($d, 'fecha_pago'), 'monto' => proyecto_decimal($d, 'monto', 12, true), 'observaciones' => proyecto_texto($d, 'observaciones', 100000), 'id_factura' => $fid, 'registrado_por' => $usuario];
            if ($v['numero_operacion'] && proyecto_query($db, 'SELECT 1 FROM pago WHERE id_factura = :id AND numero_operacion = :numero AND tipo_pago = :tipo', ['id' => $fid, 'numero' => $v['numero_operacion'], 'tipo' => $v['tipo_pago']])->fetchColumn()) adquisicion_error('Este número de operación ya está registrado para la factura.', 409);
            $subido = adquisicion_archivo('pagos');
            $r = adquisicion_insertar($db, 'pago', $v + ['ruta_comprobante' => $subido]);
        }
    }
    if ($escribir) $db->commit();
    responder(['ok' => true, 'datos' => $r], str_ends_with($accion, '_crear') ? 201 : 200);
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    if ($subido) @unlink(__DIR__ . '/../' . $subido);
    $codigo = $e instanceof PDOException ? (string) $e->getCode() : '';
    adquisicion_error(match ($codigo) { '23503' => 'El registro relacionado no existe o ya tiene relaciones posteriores.', '23505' => 'Ya existe un registro con esos datos.', default => 'No se pudo completar la operación. Intenta nuevamente.' }, in_array($codigo, ['23503', '23505'], true) ? 409 : 500);
}
