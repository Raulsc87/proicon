<?php
// Identificadores SQL definidos únicamente por los endpoints del servidor.
function responder(array $datos, int $estado = 200): never {
    http_response_code($estado);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function mantenimiento(array $config, string $accion): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    session_start();
    if (!isset($_SESSION['id_usuario'])) {
        responder(['ok' => false, 'mensaje' => 'La sesión ha terminado. Inicia sesión nuevamente.'], 401);
    }
    session_write_close();
    $metodo = in_array($accion, ['listar', 'obtener', 'unidades'], true) ? 'GET' : 'POST';
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
        header('Allow: ' . $metodo);
        responder(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
    }
    // Evita que una inclusión con salida accidental exponga detalles de conexión.
    ob_start();
    try {
        require __DIR__ . '/../config/database.local.php';
        ob_end_clean();
        $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tabla = $config['tabla'];
        $idCampo = $config['id'];
        $campos = $config['campos'];
        $columnas = implode(', ', array_merge([$idCampo], array_keys($campos)));
        if ($accion === 'listar') {
            $busqueda = $_GET['buscar'] ?? '';
            if (!is_string($busqueda) || strlen($busqueda) > 500) {
                responder(['ok' => false, 'mensaje' => 'Búsqueda inválida.'], 400);
            }
            $condiciones = [];
            $parametros = [];
            foreach ($config['buscar'] as $i => $campo) {
                $condiciones[] = "$campo ILIKE :buscar$i";
                $parametros["buscar$i"] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($busqueda)) . '%';
            }
            $where = implode(" ESCAPE '!' OR ", $condiciones) . " ESCAPE '!'";
            $q = $conexion->prepare("SELECT $columnas FROM $tabla WHERE $where ORDER BY $idCampo DESC");
            $q->execute($parametros);
            responder(['ok' => true, 'datos' => $q->fetchAll(PDO::FETCH_ASSOC)]);
        }
        if ($accion === 'unidades') {
            $q = $conexion->prepare('SELECT id_unidad, nombre, abreviatura FROM unidad_medida ORDER BY nombre');
            $q->execute();
            responder(['ok' => true, 'datos' => $q->fetchAll(PDO::FETCH_ASSOC)]);
        }
        $datos = [];
        if ($metodo === 'POST') {
            try {
                $datos = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                responder(['ok' => false, 'mensaje' => 'El cuerpo JSON no es válido.'], 400);
            }
            if (!is_array($datos) || array_is_list($datos)) {
                responder(['ok' => false, 'mensaje' => 'Datos inválidos.'], 400);
            }
        }
        $id = null;
        if ($accion !== 'crear') {
            $id = filter_var($metodo === 'GET' ? ($_GET['id'] ?? null) : ($datos['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) responder(['ok' => false, 'mensaje' => 'Identificador inválido.'], 400);
        }
        if ($accion === 'obtener') {
            $q = $conexion->prepare("SELECT $columnas FROM $tabla WHERE $idCampo = :id");
            $q->execute(['id' => $id]);
            $registro = $q->fetch(PDO::FETCH_ASSOC);
            if (!$registro) responder(['ok' => false, 'mensaje' => 'Registro no encontrado.'], 404);
            responder(['ok' => true, 'datos' => $registro]);
        }
        $valores = [];
        $validar = $accion === 'estado' ? ['estado' => $campos['estado']] : $campos;
        foreach ($validar as $campo => $regla) {
            $valor = $datos[$campo] ?? '';
            if (!is_string($valor) && !is_int($valor)) responder(['ok' => false, 'mensaje' => "Valor inválido para $campo."], 400);
            $valor = trim((string) $valor);
            if (($regla['requerido'] ?? false) && $valor === '') responder(['ok' => false, 'mensaje' => "El campo $campo es obligatorio."], 400);
            if (isset($regla['max']) && preg_match_all('/./us', $valor) > $regla['max']) responder(['ok' => false, 'mensaje' => "El campo $campo supera el máximo de {$regla['max']} caracteres."], 400);
            if ($campo === 'correo' && $valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) responder(['ok' => false, 'mensaje' => 'El correo no es válido.'], 400);
            if ($campo === 'estado' && !in_array($valor, ['ACTIVO', 'INACTIVO'], true)) responder(['ok' => false, 'mensaje' => 'Estado inválido.'], 400);
            if (($regla['tipo'] ?? '') === 'entero' && !filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) responder(['ok' => false, 'mensaje' => "Selecciona un valor válido para $campo."], 400);
            $valores[$campo] = $valor === '' ? null : $valor;
        }
        if ($accion === 'crear') {
            $nombres = implode(', ', array_keys($valores));
            $marcadores = ':' . implode(', :', array_keys($valores));
            $q = $conexion->prepare("INSERT INTO $tabla ($nombres) VALUES ($marcadores) RETURNING $columnas");
        } else {
            $asignaciones = implode(', ', array_map(fn($campo) => "$campo = :$campo", array_keys($valores)));
            $q = $conexion->prepare("UPDATE $tabla SET $asignaciones WHERE $idCampo = :id RETURNING $columnas");
            $valores['id'] = $id;
        }
        $q->execute($valores);
        $registro = $q->fetch(PDO::FETCH_ASSOC);
        if (!$registro) responder(['ok' => false, 'mensaje' => 'Registro no encontrado.'], 404);
        responder(['ok' => true, 'mensaje' => 'Cambios guardados correctamente.', 'datos' => $registro], $accion === 'crear' ? 201 : 200);
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        $codigo = $e instanceof PDOException ? (string) $e->getCode() : '';
        $mensaje = match ($codigo) {
            '23505' => 'Ya existe un registro con esos datos.',
            '23503' => 'El registro relacionado no existe o ya no está disponible.',
            default => 'No se pudo completar la operación. Intenta nuevamente.'
        };
        responder(['ok' => false, 'mensaje' => $mensaje], in_array($codigo, ['23505', '23503'], true) ? 409 : 500);
    }
}
