<?php

session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$activa = isset($_SESSION["id_usuario"]);

echo json_encode([
    "activa" => $activa,
    "usuario" => $activa ? [
        "nombre" => $_SESSION["nombre"],
        "rol" => $_SESSION["rol"]
    ] : null
]);