<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
try {
    require __DIR__ . '/../config/database.local.php';
    ob_end_clean();
    foreach (['solicitud_material', 'detalle_solicitud', 'compra', 'detalle_compra', 'factura', 'pago'] as $tabla) {
        $q = $conexion->prepare("SELECT column_name, data_type, character_maximum_length AS longitud, numeric_precision, numeric_scale, is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :tabla ORDER BY ordinal_position");
        $q->execute(['tabla' => $tabla]);
        echo "$tabla: " . json_encode($q->fetchAll(PDO::FETCH_ASSOC)) . PHP_EOL;
        $q = $conexion->prepare("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = to_regclass(:tabla) AND contype IN ('p','f','u','c')");
        $q->execute(['tabla' => $tabla]);
        echo json_encode($q->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
        if (in_array($tabla, ['solicitud_material', 'compra', 'factura', 'pago'], true)) {
            $campo = $tabla === 'pago' ? 'tipo_pago' : 'estado';
            $q = $conexion->prepare("SELECT DISTINCT $campo FROM $tabla ORDER BY $campo"); $q->execute();
            echo "$campo: " . json_encode($q->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR, "No se pudo inspeccionar la base de datos.\n"); exit(1);
}
