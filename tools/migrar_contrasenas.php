<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/autorizacion.php';
$db = null;
try {
    $db = autorizacion_conexion();
    $db->beginTransaction();
    $db->exec(file_get_contents(__DIR__ . '/../sql/migraciones/001_contrasenas_hash.sql'));
    $usuarios = $db->query('SELECT id_usuario, contrasena FROM public.usuario ORDER BY id_usuario FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $actualizar = $db->prepare('UPDATE public.usuario SET contrasena=:hash WHERE id_usuario=:id');
    $migradas = 0;
    foreach ($usuarios as $usuario) {
        if (password_get_info($usuario['contrasena'])['algo'] !== null) continue;
        // Bcrypt no debe truncar silenciosamente una contraseña existente.
        if (PASSWORD_DEFAULT === PASSWORD_BCRYPT && strlen($usuario['contrasena']) > 72) throw new RuntimeException('Longitud no compatible');
        $hash = password_hash($usuario['contrasena'], PASSWORD_DEFAULT);
        $actualizar->execute(['hash' => $hash, 'id' => $usuario['id_usuario']]);
        $migradas++;
    }
    $revisados = count($usuarios);
    unset($usuarios, $usuario, $hash);
    $db->commit();
    echo "Usuarios revisados: $revisados\nContraseñas migradas: $migradas\n";
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "No se completó la migración. Los cambios de esta ejecución se revirtieron. Revisa conexión y compatibilidad de contraseñas.\n");
    exit(1);
}
