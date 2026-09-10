<?php

session_start();

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../config/database.local.php";

$datos = json_decode(file_get_contents("php://input"), true);

$usuario = trim($datos["usuario"] ?? "");
$contrasena = $datos["contrasena"] ?? "";

if ($usuario === "" || $contrasena === "") {

    http_response_code(400);

    echo json_encode([
        "ok" => false,
        "mensaje" => "Completa todos los campos."
    ]);

    exit;
}

$sql = "
    SELECT
        u.id_usuario,
        u.nombre_usuario,
        u.contrasena,
        u.estado,
        e.nombres,
        e.apellidos,
        r.nombre AS rol
    FROM usuario u
    INNER JOIN empleado e
        ON u.id_empleado = e.id_empleado
    INNER JOIN rol r
        ON u.id_rol = r.id_rol
    WHERE u.nombre_usuario = :usuario
    LIMIT 1
";

$consulta = $conexion->prepare($sql);

$consulta->execute([
    ":usuario" => $usuario
]);

$usuarioEncontrado = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$usuarioEncontrado) {

    http_response_code(401);

    echo json_encode([
        "ok" => false,
        "mensaje" => "Usuario o contraseña incorrectos."
    ]);

    exit;
}

if ($usuarioEncontrado["estado"] !== "ACTIVO") {

    http_response_code(403);

    echo json_encode([
        "ok" => false,
        "mensaje" => "Este usuario está inactivo."
    ]);

    exit;
}

if (!hash_equals(
    $usuarioEncontrado["contrasena"],
    $contrasena
)) {

    http_response_code(401);

    echo json_encode([
        "ok" => false,
        "mensaje" => "Usuario o contraseña incorrectos."
    ]);

    exit;
}

$_SESSION["id_usuario"] = $usuarioEncontrado["id_usuario"];
$_SESSION["nombre_usuario"] = $usuarioEncontrado["nombre_usuario"];
$_SESSION["nombre"] =
    $usuarioEncontrado["nombres"] . " " .
    $usuarioEncontrado["apellidos"];

$_SESSION["rol"] = $usuarioEncontrado["rol"];

echo json_encode([
    "ok" => true,
    "mensaje" => "Inicio de sesión correcto.",
    "usuario" => [
        "nombre" => $_SESSION["nombre"],
        "rol" => $_SESSION["rol"]
    ]
]);