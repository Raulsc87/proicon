<?php
function autorizacion_error(int $estado, string $mensaje): never {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    http_response_code($estado);
    echo json_encode(['ok' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE); exit;
}
function autorizacion_conexion(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    // La configuración local puede terminar con die(): no dejar que su mensaje se publique.
    $completo = false; $nivel = ob_get_level();
    ob_start();
    register_shutdown_function(static function () use (&$completo, $nivel): void {
        if (!$completo) {
            while (ob_get_level() > $nivel) ob_end_clean();
            autorizacion_error(503, 'No se pudo verificar el acceso. Intenta nuevamente.');
        }
    });
    try {
        require __DIR__ . '/../config/database.local.php';
        $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db = $conexion;
    } catch (Throwable $e) { $completo = true; ob_end_clean(); autorizacion_error(503, 'No se pudo verificar el acceso. Intenta nuevamente.'); }
    $completo = true; ob_end_clean(); return $db;
}
function autorizacion_usuario(): ?array {
    static $cargado = false, $usuario = null;
    if ($cargado) return $usuario;
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $id = $_SESSION['id_usuario'] ?? null; session_write_close(); $cargado = true;
    if (!is_scalar($id) || !filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) return null;
    try {
        $db = autorizacion_conexion();
        $q = $db->prepare("SELECT u.id_usuario,e.nombres || ' ' || e.apellidos AS nombre,r.nombre AS rol FROM usuario u JOIN empleado e USING(id_empleado) JOIN rol r USING(id_rol) WHERE u.id_usuario=:id AND u.estado='ACTIVO'");
        $q->execute(['id' => $id]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $q = $db->prepare('SELECT DISTINCT p.codigo FROM usuario u JOIN rol r USING(id_rol) JOIN rol_permiso rp USING(id_rol) JOIN permiso p USING(id_permiso) WHERE u.id_usuario=:id ORDER BY p.codigo');
        $q->execute(['id' => $id]);
        return $usuario = ['id' => (int)$r['id_usuario'], 'id_usuario' => (int)$r['id_usuario'], 'nombre' => $r['nombre'], 'rol' => $r['rol'], 'permisos' => $q->fetchAll(PDO::FETCH_COLUMN)];
    } catch (Throwable $e) { autorizacion_error(503, 'No se pudo verificar el acceso. Intenta nuevamente.'); }
}
function requerir_sesion(): array {
    $usuario = autorizacion_usuario();
    if (!$usuario) autorizacion_error(401, 'La sesión ha terminado. Inicia sesión nuevamente.');
    return $usuario;
}
function usuario_tiene_permiso(string $codigo): bool { return in_array($codigo, autorizacion_usuario()['permisos'] ?? [], true); }
function requerir_permiso(string $codigo): void {
    requerir_sesion();
    if (!usuario_tiene_permiso($codigo)) autorizacion_error(403, 'No tiene permiso para realizar esta operación.');
}
function requerir_alguno(array $codigos): void {
    requerir_sesion();
    foreach ($codigos as $codigo) if (usuario_tiene_permiso($codigo)) return;
    autorizacion_error(403, 'No tiene permiso para realizar esta operación.');
}
