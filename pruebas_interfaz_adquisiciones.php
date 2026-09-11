<?php
// Sesión efímera sólo CLI para comprobar las vistas sin tocar datos originales.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$sid = trim(stream_get_contents(STDIN));
if (!preg_match('/^[a-f0-9]{48}$/D', $sid)) exit(1);
session_id($sid); session_start();
if (($argv[1] ?? '') === 'cerrar') { $_SESSION = []; session_destroy(); exit; }
ob_start();
try {
    require __DIR__ . '/config/database.local.php'; ob_end_clean();
    $q = $conexion->prepare('SELECT id_usuario FROM usuario ORDER BY id_usuario LIMIT 1'); $q->execute();
    $_SESSION = ['id_usuario' => $q->fetchColumn(), 'nombre' => 'Verificación de interfaz', 'rol' => 'PRUEBA']; session_write_close();
    $q = $conexion->prepare('SELECT s.id_proyecto, s.id_solicitud, c.id_compra, f.id_factura FROM solicitud_material s JOIN compra c USING (id_solicitud) JOIN factura f USING (id_compra) ORDER BY s.id_solicitud, c.id_compra, f.id_factura LIMIT 1'); $q->execute();
    echo json_encode($q->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) { if (ob_get_level()) ob_end_clean(); fwrite(STDERR, "No se pudo preparar la prueba de interfaz.\n"); exit(1); }
