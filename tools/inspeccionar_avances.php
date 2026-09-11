<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
try {
    require __DIR__ . '/../config/database.local.php'; ob_end_clean();
    foreach (['actividad' => ['estado', 'situacion_tiempo'], 'proyecto' => ['estado', 'estado_avance']] as $tabla => $campos) {
        foreach ($campos as $campo) {
            $q = $conexion->prepare("SELECT DISTINCT $campo FROM $tabla ORDER BY $campo"); $q->execute();
            echo "$tabla.$campo: " . json_encode($q->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
        }
    }
    $q = $conexion->prepare('SELECT estado, COUNT(*) AS cantidad, MIN(porcentaje_avance) AS minimo, MAX(porcentaje_avance) AS maximo FROM actividad GROUP BY estado ORDER BY estado'); $q->execute();
    echo json_encode($q->fetchAll(PDO::FETCH_ASSOC)) . PHP_EOL;
    $q = $conexion->prepare("SELECT numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'actividad' AND column_name = 'porcentaje_avance'"); $q->execute();
    echo json_encode($q->fetchAll(PDO::FETCH_ASSOC)) . PHP_EOL;
    $q = $conexion->prepare('SELECT ruta_archivo FROM fotografia_avance ORDER BY id_fotografia LIMIT 5'); $q->execute();
    echo 'Rutas: ' . json_encode($q->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
    $q = $conexion->prepare("SELECT conrelid::regclass AS tabla, conname, pg_get_constraintdef(oid) AS relacion FROM pg_constraint WHERE contype = 'f' AND confrelid = to_regclass(:tabla)");
    $q->execute(['tabla' => 'fotografia_avance']);
    echo 'FK hacia fotografia_avance: ' . json_encode($q->fetchAll(PDO::FETCH_ASSOC)) . PHP_EOL;
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean(); fwrite(STDERR, "No se pudo inspeccionar avances.\n"); exit(1);
}
