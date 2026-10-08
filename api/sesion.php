<?php

require_once __DIR__ . '/autorizacion.php';

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$usuario = autorizacion_usuario();
$activa = $usuario !== null;

echo json_encode([
    "ok" => true,
    "activa" => $activa,
    "usuario" => $usuario
]);
