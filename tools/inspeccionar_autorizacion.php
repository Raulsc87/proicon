<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
try {
    require __DIR__ . '/../config/database.local.php'; ob_end_clean();
    foreach ([
        "SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('usuario','rol','permiso','rol_permiso') ORDER BY table_name,ordinal_position",
        "SELECT conrelid::regclass AS tabla,pg_get_constraintdef(oid) AS restriccion FROM pg_constraint WHERE conrelid IN ('usuario'::regclass,'rol'::regclass,'permiso'::regclass,'rol_permiso'::regclass) ORDER BY tabla",
        'SELECT r.id_rol,r.nombre,ARRAY_AGG(p.codigo ORDER BY p.codigo) FILTER (WHERE p.codigo IS NOT NULL) AS permisos FROM rol r LEFT JOIN rol_permiso rp USING(id_rol) LEFT JOIN permiso p USING(id_permiso) GROUP BY r.id_rol ORDER BY r.id_rol',
        'SELECT u.id_usuario,u.estado,r.nombre AS rol FROM usuario u JOIN rol r USING(id_rol) ORDER BY u.id_usuario'
    ] as $sql) echo json_encode($conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) { if (ob_get_level()) ob_end_clean(); fwrite(STDERR,"No se pudo inspeccionar autorización.\n"); exit(1); }
