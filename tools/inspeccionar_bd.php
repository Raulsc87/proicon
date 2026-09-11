<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
try {
    require __DIR__ . '/../config/database.local.php';
    ob_end_clean();
    $q = $conexion->prepare("SELECT table_name, column_name, data_type, character_maximum_length, is_nullable, column_default, is_identity FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :tabla ORDER BY ordinal_position");
    $q->execute(['tabla' => $argv[1] ?? 'cliente']);
    echo json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
    $q = $conexion->prepare("SELECT conname, pg_get_constraintdef(oid) AS definicion FROM pg_constraint WHERE conrelid = to_regclass(:tabla)");
    $q->execute(['tabla' => $argv[1] ?? 'cliente']);
    echo json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR, "No se pudo inspeccionar la base de datos.\n");
    exit(1);
}
