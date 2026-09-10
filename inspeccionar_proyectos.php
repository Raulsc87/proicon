<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
try {
    require __DIR__ . '/config/database.local.php';
    ob_end_clean();
    foreach (['proyecto' => ['estado', 'estado_avance'], 'proyecto_empleado' => ['estado'], 'presupuesto' => ['estado']] as $tabla => $campos) {
        foreach ($campos as $campo) {
            $q = $conexion->prepare("SELECT DISTINCT $campo FROM $tabla ORDER BY $campo");
            $q->execute();
            echo "$tabla.$campo: " . json_encode($q->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
        }
    }
    $q = $conexion->prepare("SELECT conrelid::regclass AS tabla, pg_get_constraintdef(oid) AS relacion FROM pg_constraint WHERE contype = 'f' AND confrelid = to_regclass(:tabla)");
    $q->execute(['tabla' => 'proyecto']);
    echo json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR, "No se pudo inspeccionar PostgreSQL.\n");
    exit(1);
}
