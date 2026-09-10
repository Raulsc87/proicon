<?php
// Prueba de integración local. Nunca se puede ejecutar mediante HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.use_cookies', '0');
session_cache_limiter('');
$modulo = $argv[1] ?? 'clientes';
if (!in_array($modulo, ['clientes', 'proveedores', 'materiales'], true)) exit(1);
$config = require __DIR__ . '/api/' . $modulo . '_config.php';
$tabla = $config['tabla'];
$idCampo = $config['id'];
$marca = 'PRUEBA_PROICON_' . bin2hex(random_bytes(8));
$id = null;
$conexion = null;
$sesion = bin2hex(random_bytes(24));
session_id($sesion);
session_start();
$_SESSION['id_usuario'] = 1;
$_SESSION['nombre'] = 'Prueba automática local';
$_SESSION['rol'] = 'PRUEBA';
session_write_close();

function comprobar(bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
    echo "OK: $mensaje\n";
}
function http(string $accion, ?array $datos = null, string $consulta = '', bool $autenticado = true): array {
    global $modulo, $sesion;
    $opciones = ['method' => $datos === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 15,
        'header' => "Content-Type: application/json\r\n" . ($autenticado ? "Cookie: PHPSESSID=$sesion\r\n" : '')];
    if ($datos !== null) $opciones['content'] = json_encode($datos);
    $contenido = file_get_contents('http://localhost:8000/api/' . $modulo . '_' . $accion . '.php' . $consulta, false, stream_context_create(['http' => $opciones]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $coincidencia);
    return [(int) ($coincidencia[1] ?? 0), json_decode($contenido, true, 512, JSON_THROW_ON_ERROR)];
}
try {
    ob_start();
    require __DIR__ . '/config/database.local.php';
    ob_end_clean();
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $q = $conexion->prepare("SELECT * FROM $tabla ORDER BY $idCampo");
    $q->execute();
    $originales = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach (['listar', 'obtener', 'crear', 'editar', 'estado'] as $accion) {
        if ($accion === 'estado' && !isset($config['campos']['estado'])) continue;
        [$codigo] = http($accion, in_array($accion, ['crear', 'editar', 'estado'], true) ? ['id' => 1] : null, '', false);
        comprobar($codigo === 401, "$accion rechaza peticiones sin sesión");
    }
    [$codigo, $resultado] = http('listar');
    comprobar($codigo === 200 && count($resultado['datos']) === count($originales), 'lista los registros existentes');
    $datos = [];
    foreach ($config['campos'] as $campo => $regla) {
        $datos[$campo] = match ($campo) {
            'nombre' => $marca,
            'estado' => 'ACTIVO',
            'correo' => 'prueba@example.invalid',
            'nit' => 'TEST-' . bin2hex(random_bytes(4)),
            default => ($regla['tipo'] ?? '') === 'entero' ? '1' : 'Prueba'
        };
    }
    if (isset($datos['id_unidad'])) {
        [$codigo] = http('unidades', null, '', false);
        comprobar($codigo === 401, 'unidades rechaza peticiones sin sesión');
        [$codigo, $unidades] = http('unidades');
        comprobar($codigo === 200 && count($unidades['datos']) > 0, 'carga unidades reales');
        $datos['id_unidad'] = (string) $unidades['datos'][0]['id_unidad'];
        $q = $conexion->prepare('SELECT COALESCE(MAX(id_unidad), 0) + 1 FROM unidad_medida');
        $q->execute();
        [$codigo, $resultado] = http('crear', array_replace($datos, ['id_unidad' => (string) $q->fetchColumn()]));
        comprobar($codigo === 409 && !str_contains(json_encode($resultado), 'SQLSTATE'), 'rechaza unidad inexistente sin exponer SQL');
    }
    [$codigo] = http('crear', array_replace($datos, ['nombre' => str_repeat('a', $config['campos']['nombre']['max'] + 1)]));
    comprobar($codigo === 400, 'rechaza nombre demasiado largo');
    [$codigo] = http('crear', array_replace($datos, ['nombre' => '  ']));
    comprobar($codigo === 400, 'rechaza nombre vacío');
    if (isset($datos['correo'])) {
        [$codigo] = http('crear', array_replace($datos, ['correo' => 'correo-invalido']));
        comprobar($codigo === 400, 'rechaza correo inválido');
    }
    [$codigo, $resultado] = http('crear', $datos);
    comprobar($codigo === 201 && isset($resultado['datos'][$idCampo]), 'crea registro temporal');
    $id = $resultado['datos'][$idCampo];
    [$codigo, $resultado] = http('obtener', null, '?id=' . $id);
    comprobar($codigo === 200 && $resultado['datos']['nombre'] === $marca, 'consulta registro creado');
    [$codigo, $resultado] = http('listar', null, '?buscar=' . urlencode($marca));
    comprobar($codigo === 200 && count($resultado['datos']) === 1, 'busca por nombre');
    if (isset($datos['codigo'])) {
        [$codigo, $resultado] = http('listar', null, '?buscar=' . urlencode($datos['codigo']));
        comprobar($codigo === 200 && in_array($id, array_column($resultado['datos'], $idCampo)), 'busca por código');
    }
    if (isset($datos['nit'])) {
        [$codigo, $resultado] = http('listar', null, '?buscar=' . urlencode($datos['nit']));
        comprobar($codigo === 200 && count($resultado['datos']) === 1, 'busca por NIT');
    }
    $datos['nombre'] = $marca . '_EDITADO';
    [$codigo, $resultado] = http('editar', array_merge($datos, ['id' => $id]));
    comprobar($codigo === 200 && $resultado['datos']['nombre'] === $datos['nombre'], 'edita registro');
    [$codigo, $resultado] = http('obtener', null, '?id=' . $id);
    comprobar($codigo === 200 && $resultado['datos']['nombre'] === $datos['nombre'], 'persiste la edición');
    if (isset($datos['estado'])) {
        foreach (['INACTIVO', 'ACTIVO'] as $estado) {
            [$codigo, $resultado] = http('estado', ['id' => $id, 'estado' => $estado]);
            comprobar($codigo === 200 && $resultado['datos']['estado'] === $estado, 'cambia estado a ' . $estado);
            [$codigo, $resultado] = http('obtener', null, '?id=' . $id);
            comprobar($codigo === 200 && $resultado['datos']['estado'] === $estado, 'persiste estado ' . $estado);
        }
        [$codigo] = http('estado', ['id' => $id, 'estado' => 'OTRO']);
        comprobar($codigo === 400, 'rechaza estado inválido');
    }
    [$codigo] = http('obtener', null, '?id=incorrecto');
    comprobar($codigo === 400, 'rechaza identificador inválido');
    [$codigo] = http('crear');
    comprobar($codigo === 405, 'rechaza método incorrecto');
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    echo 'FALLO: ' . ($e instanceof PDOException ? 'Error de base de datos durante la prueba.' : $e->getMessage()) . "\n";
    $fallo = true;
} finally {
    if ($conexion instanceof PDO) {
        // Sólo borra el registro con la marca aleatoria de esta ejecución.
        $q = $conexion->prepare("DELETE FROM $tabla WHERE nombre IN (:nombre, :editado)");
        $q->execute(['nombre' => $marca, 'editado' => $marca . '_EDITADO']);
        echo 'Limpieza: ' . $q->rowCount() . " registro temporal eliminado.\n";
        $q = $conexion->prepare("SELECT * FROM $tabla ORDER BY $idCampo");
        $q->execute();
        comprobar($q->fetchAll(PDO::FETCH_ASSOC) === $originales, 'los registros originales no cambiaron');
    }
    session_id($sesion);
    session_start();
    $_SESSION = [];
    session_destroy();
}
exit(isset($fallo) ? 1 : 0);
