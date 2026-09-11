<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/database.local.php';

$sql = "SELECT current_database() AS base, COUNT(*) AS usuarios FROM usuario";
$resultado = $conexion->query($sql);
$fila = $resultado->fetch(PDO::FETCH_ASSOC);

echo "<br>";
echo "Base de datos: " . $fila["base"] . "<br>";
echo "Usuarios registrados: " . $fila["usuarios"];
