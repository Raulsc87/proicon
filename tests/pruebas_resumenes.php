<?php
// Fixture de lectura y sesión efímera: no inserta ni modifica registros.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$sid = trim(stream_get_contents(STDIN));
if (!preg_match('/^[a-f0-9]{48}$/D', $sid)) exit(1);
session_id($sid); session_start();
if (($argv[1] ?? '') === 'cerrar') { $_SESSION = []; session_destroy(); exit; }
ob_start();
try {
    require __DIR__ . '/../config/database.local.php'; ob_end_clean();
    $conexion->beginTransaction();
    $conexion->exec('SET TRANSACTION READ ONLY');
    $uid = $conexion->query('SELECT id_usuario FROM usuario ORDER BY id_usuario LIMIT 1')->fetchColumn();
    if (!$uid) throw new RuntimeException('Sin usuario de prueba');
    $_SESSION = ['id_usuario' => $uid, 'nombre' => 'Verificación de consultas', 'rol' => 'PRUEBA']; session_write_close();
    $datos = [];
    $datos['presupuestos'] = $conexion->query('SELECT p.id_presupuesto, p.id_proyecto, p.version, p.fecha_registro, p.estado, COALESCE(SUM(d.cantidad * d.precio_unitario), 0) AS total FROM presupuesto p LEFT JOIN detalle_presupuesto d USING (id_presupuesto) GROUP BY p.id_presupuesto ORDER BY p.id_proyecto DESC, p.version DESC, p.id_presupuesto DESC')->fetchAll(PDO::FETCH_ASSOC);
    $datos['empleados'] = $conexion->query("SELECT pe.*, e.nombres || ' ' || e.apellidos AS nombre FROM proyecto_empleado pe JOIN empleado e USING (id_empleado) JOIN proyecto p USING (id_proyecto) ORDER BY pe.id_proyecto DESC, e.nombres, e.apellidos")->fetchAll(PDO::FETCH_ASSOC);
    $datos['avance'] = $conexion->query("SELECT p.id_proyecto, p.estado, COUNT(a.id_actividad) AS total, COUNT(a.id_actividad) FILTER (WHERE a.estado='FINALIZADO') AS finalizadas FROM proyecto p LEFT JOIN actividad a USING (id_proyecto) GROUP BY p.id_proyecto ORDER BY p.id_proyecto DESC")->fetchAll(PDO::FETCH_ASSOC);
    $conexion->commit();
    echo json_encode($datos, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    if (isset($conexion) && $conexion->inTransaction()) $conexion->rollBack();
    fwrite(STDERR, "No se pudo preparar la consulta de prueba.\n"); exit(1);
}
