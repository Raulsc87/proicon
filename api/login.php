<?php

session_start();

header("Content-Type: application/json; charset=utf-8");

header("Cache-Control: no-store");
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); http_response_code(405);
    echo json_encode(['ok' => false, 'mensaje' => 'Método no permitido.']); exit;
}

$datos = json_decode(file_get_contents("php://input"), true);
if (!is_array($datos) || !is_string($datos['usuario'] ?? null) || !is_string($datos['contrasena'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Envía usuario y contraseña como texto.']); exit;
}

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

try {
    ob_start();
    try { require __DIR__ . '/../config/database.local.php'; } finally { ob_end_clean(); }
    $consulta = $conexion->prepare($sql);

    $consulta->execute([":usuario" => $usuario]);

    $usuarioEncontrado = $consulta->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo iniciar sesión. Intenta nuevamente.']); exit;
}

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

session_regenerate_id(true);
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
