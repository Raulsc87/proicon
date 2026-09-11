<?php
require_once __DIR__ . '/proyectos_base.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start(); $usuario = $_SESSION['id_usuario'] ?? null; session_write_close();
if (!$usuario) responder(['ok' => false, 'mensaje' => 'Inicia sesión nuevamente.'], 401);

function avance_error(string $mensaje, int $codigo = 400): never { responder(['ok' => false, 'mensaje' => $mensaje], $codigo); }
function avance_fila(PDO $db, string $sql, array $params): array {
    $r = proyecto_query($db, $sql, $params)->fetch(PDO::FETCH_ASSOC);
    if (!$r) avance_error('Registro no encontrado en este proyecto.', 404);
    return $r;
}
function avance_mimes(): array {
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'] + (defined('IMAGETYPE_WEBP') ? ['webp' => 'image/webp'] : []);
}
function avance_imagen(string $archivo, string $extension): string {
    if (!class_exists('finfo')) avance_error('El servidor necesita la extensión nativa fileinfo para validar imágenes.', 503);
    $permitidos = avance_mimes();
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo);
    $info = @getimagesize($archivo);
    if (!isset($permitidos[$extension]) || $mime !== $permitidos[$extension] || !$info || ($info['mime'] ?? '') !== $mime) avance_error('El archivo no es una imagen JPG, PNG o WEBP válida.');
    return $mime;
}
function avance_subir(bool $opcional = false): ?string {
    $f = $_FILES['archivo'] ?? null;
    if ($opcional && (!$f || ($f['error'] ?? null) === UPLOAD_ERR_NO_FILE)) return null;
    if (!$f || !is_array($f) || ($f['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null) || !is_string($f['name'] ?? null) || !is_uploaded_file($f['tmp_name'])) avance_error('Selecciona una imagen válida de hasta 2 MB.');
    if (filesize($f['tmp_name']) > 2 * 1024 * 1024) avance_error('La imagen supera los 2 MB.');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    avance_imagen($f['tmp_name'], $ext);
    $carpeta = __DIR__ . '/../uploads/avances';
    if (!is_dir($carpeta) && !mkdir($carpeta, 0700, true) && !is_dir($carpeta)) throw new RuntimeException('storage');
    $ruta = 'uploads/avances/' . bin2hex(random_bytes(24)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $ruta)) throw new RuntimeException('upload');
    return $ruta;
}
function avance_limpiar_anterior(PDO $db, string $ruta): ?string {
    // Sólo después del COMMIT. Un fallo de limpieza nunca revierte ni borra la imagen nueva.
    if (!preg_match('~^uploads/avances/[a-zA-Z0-9_.-]+\.(?:jpe?g|png|webp)$~iD', $ruta)) return null;
    try {
        if (proyecto_query($db, "SELECT 1 FROM fotografia_avance WHERE lower(replace(ruta_archivo, chr(92), '/')) = lower(:ruta) LIMIT 1", ['ruta' => $ruta])->fetchColumn()) return null;
        $raiz = realpath(__DIR__ . '/..');
        $candidato = $raiz . '/' . $ruta;
        if (!file_exists($candidato)) return null;
        $archivo = realpath($candidato);
        $directorio = realpath($raiz . '/uploads/avances');
        $esperado = str_replace('\\', '/', $raiz) . '/uploads/avances';
        if (!$archivo || !$directorio || is_link($candidato) || str_replace('\\', '/', $directorio) !== $esperado || dirname($archivo) !== $directorio || !is_file($archivo)) return 'El registro se guardó; el archivo anterior se conservó porque su ubicación no es segura.';
        if (!@unlink($archivo)) return 'El registro se guardó, pero no se pudo eliminar el archivo anterior. Revisa los permisos de uploads/avances.';
    } catch (Throwable $e) { return 'El registro se guardó, pero no se pudo comprobar la limpieza del archivo anterior.'; }
    return null;
}

$lecturas = ['listar', 'obtener', 'fotos_listar', 'imagen'];
$escrituras = ['crear', 'editar', 'estado', 'foto_subir', 'foto_editar', 'foto_eliminar'];
$accion = $_GET['accion'] ?? '';
if (!is_string($accion) || !in_array($accion, array_merge($lecturas, $escrituras), true)) avance_error('Operación no encontrada.', 404);
$escribir = in_array($accion, $escrituras, true);
if ($_SERVER['REQUEST_METHOD'] !== ($escribir ? 'POST' : 'GET')) { header('Allow: ' . ($escribir ? 'POST' : 'GET')); avance_error('Método no permitido.', 405); }
$db = null; $subida = null;
ob_start();
try {
    require __DIR__ . '/../config/database.local.php'; ob_end_clean();
    $db = $conexion; $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $d = $_GET;
    if ($escribir) {
        if (in_array($accion, ['foto_subir', 'foto_editar'], true)) $d = $_POST;
        else {
            try { $d = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { avance_error('JSON inválido.'); }
            if (!is_array($d) || array_is_list($d)) avance_error('Datos inválidos.');
        }
    }
    $pid = proyecto_id($d['id_proyecto'] ?? null);
    $p = avance_fila($db, 'SELECT id_proyecto, estado_avance FROM proyecto WHERE id_proyecto = :id', ['id' => $pid]);
    $sql = "SELECT a.*, e.nombres || ' ' || e.apellidos AS responsable FROM actividad a LEFT JOIN empleado e ON e.id_empleado = a.id_empleado_responsable WHERE a.id_proyecto = :pid";
    if ($accion === 'listar') {
        // Una instantánea coherente para la lista y su avance calculado.
        $db->beginTransaction();
        proyecto_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $r = ['actividades' => proyecto_query($db, $sql . ' ORDER BY a.id_actividad', ['pid' => $pid])->fetchAll(PDO::FETCH_ASSOC),
            'resumen' => proyecto_query($db, "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE estado = 'FINALIZADO') AS finalizadas, ROUND(100.0 * COUNT(*) FILTER (WHERE estado = 'FINALIZADO') / NULLIF(COUNT(*), 0), 1) AS porcentaje FROM actividad WHERE id_proyecto = :id", ['id' => $pid])->fetch(PDO::FETCH_ASSOC),
            'estado_avance' => $p['estado_avance'],
            'estados' => proyecto_estados($db, 'actividad'),
            'situaciones' => proyecto_estados($db, 'actividad', 'situacion_tiempo'),
            'empleados' => proyecto_query($db, "SELECT id_empleado, nombres || ' ' || apellidos AS nombre FROM empleado ORDER BY nombres, apellidos")->fetchAll(PDO::FETCH_ASSOC),
            'formatos_foto' => array_keys(avance_mimes())];
        $db->commit();
    } elseif ($accion === 'obtener') {
        $r = avance_fila($db, $sql . ' AND a.id_actividad = :id', ['pid' => $pid, 'id' => proyecto_id($d['id_actividad'] ?? null)]);
    } elseif (in_array($accion, ['crear', 'editar', 'estado'], true)) {
        $v = ['estado' => proyecto_estado($db, $d, 'actividad')];
        if ($accion !== 'estado') {
            $v += ['nombre' => proyecto_texto($d, 'nombre', 150, true), 'descripcion' => proyecto_texto($d, 'descripcion', 100000), 'observaciones' => proyecto_texto($d, 'observaciones', 100000), 'situacion_tiempo' => proyecto_estado($db, $d, 'actividad', 'situacion_tiempo'), 'porcentaje_avance' => proyecto_decimal($d, 'porcentaje_avance', 3, false)];
            if ((float) $v['porcentaje_avance'] > 100) avance_error('El porcentaje debe estar entre 0 y 100.');
            foreach (['fecha_inicio', 'fecha_fin_prevista', 'fecha_fin_real'] as $campo) $v[$campo] = proyecto_fecha($d, $campo, false);
            foreach (['fecha_fin_prevista', 'fecha_fin_real'] as $campo) if ($v['fecha_inicio'] && $v[$campo] && $v[$campo] < $v['fecha_inicio']) avance_error('La fecha final no puede preceder al inicio.');
            $v['id_empleado_responsable'] = ($d['id_empleado_responsable'] ?? '') === '' || ($d['id_empleado_responsable'] ?? null) === null ? null : proyecto_id($d['id_empleado_responsable']);
        }
        $r = $accion === 'crear' ? proyecto_guardar($db, 'actividad', $v + ['id_proyecto' => $pid]) : proyecto_guardar($db, 'actividad', $v, 'id_actividad = :id AND id_proyecto = :pid', ['id' => proyecto_id($d['id_actividad'] ?? null), 'pid' => $pid]);
    } elseif ($accion === 'fotos_listar') {
        $antes = isset($d['antes']) ? proyecto_id($d['antes']) : 2147483647;
        $fotos = proyecto_query($db, 'SELECT f.id_fotografia, f.descripcion, f.fecha_carga, u.nombre_usuario AS registrada_por FROM fotografia_avance f JOIN bitacora b USING (id_bitacora) JOIN usuario u ON u.id_usuario = b.creada_por WHERE b.id_proyecto = :pid AND f.id_fotografia < :antes ORDER BY f.id_fotografia DESC LIMIT 13', ['pid' => $pid, 'antes' => $antes])->fetchAll(PDO::FETCH_ASSOC);
        $hayMas = count($fotos) > 12; $fotos = array_slice($fotos, 0, 12);
        $r = ['fotos' => $fotos, 'siguiente' => $hayMas ? end($fotos)['id_fotografia'] : null];
    } elseif (in_array($accion, ['foto_editar', 'foto_eliminar'], true)) {
        $fid = proyecto_id($d['id_fotografia'] ?? null);
        $db->beginTransaction();
        $foto = avance_fila($db, 'SELECT f.* FROM fotografia_avance f JOIN bitacora b USING (id_bitacora) WHERE b.id_proyecto = :pid AND f.id_fotografia = :id FOR UPDATE OF f', ['pid' => $pid, 'id' => $fid]);
        if ($accion === 'foto_eliminar') {
            proyecto_query($db, 'DELETE FROM fotografia_avance WHERE id_fotografia = :id', ['id' => $fid]);
            $r = ['id_fotografia' => $fid, 'eliminada' => true];
        } else {
            $v = ['descripcion' => proyecto_texto($d, 'descripcion', 250), 'fecha_carga' => array_key_exists('fecha_carga', $d) ? proyecto_fecha($d, 'fecha_carga') : $foto['fecha_carga']];
            $ruta = avance_subir(true);
            if ($ruta !== null) { $subida = __DIR__ . '/../' . $ruta; $v['ruta_archivo'] = $ruta; }
            $r = proyecto_guardar($db, 'fotografia_avance', $v, 'id_fotografia = :id', ['id' => $fid]);
        }
        $db->commit(); $subida = null;
        if ($accion === 'foto_eliminar' || isset($v['ruta_archivo'])) $r['aviso'] = avance_limpiar_anterior($db, $foto['ruta_archivo']);
    } elseif ($accion === 'imagen') {
        $f = avance_fila($db, 'SELECT f.ruta_archivo FROM fotografia_avance f JOIN bitacora b USING (id_bitacora) WHERE b.id_proyecto = :pid AND f.id_fotografia = :id', ['pid' => $pid, 'id' => proyecto_id($d['id_fotografia'] ?? null)]);
        $ruta = $f['ruta_archivo'];
        if (!preg_match('~^(?:uploads/avances|fotos)/[a-zA-Z0-9_.-]+\.(?:jpe?g|png|webp)$~iD', $ruta)) avance_error('Imagen no disponible.', 404);
        $raiz = realpath(__DIR__ . '/..'); $archivo = realpath($raiz . '/' . $ruta);
        $carpeta = realpath($raiz . '/' . dirname($ruta));
        if (!$archivo || !$carpeta || !is_file($archivo) || dirname($archivo) !== $carpeta || !str_starts_with(str_replace('\\', '/', $archivo), str_replace('\\', '/', $raiz) . '/')) avance_error('La imagen registrada no está disponible en el servidor.', 404);
        $mime = avance_imagen($archivo, strtolower(pathinfo($archivo, PATHINFO_EXTENSION)));
        header('Content-Type: ' . $mime); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="' . basename($archivo) . '"'); header('Content-Length: ' . filesize($archivo));
        readfile($archivo); exit;
    } else {
        $descripcion = proyecto_texto($d, 'descripcion', 250);
        $ruta = avance_subir();
        $subida = __DIR__ . '/../' . $ruta;
        $db->beginTransaction();
        $fecha = proyecto_query($db, 'SELECT CURRENT_DATE')->fetchColumn();
        // fotografia_avance exige una bitácora; crea sólo el registro mínimo de esta evidencia.
        $b = proyecto_guardar($db, 'bitacora', ['fecha' => $fecha, 'descripcion' => $descripcion ?? 'Evidencia fotográfica de avance', 'observaciones' => null, 'id_proyecto' => $pid, 'creada_por' => $usuario]);
        $r = proyecto_guardar($db, 'fotografia_avance', ['ruta_archivo' => $ruta, 'descripcion' => $descripcion, 'fecha_carga' => $fecha, 'id_bitacora' => $b['id_bitacora']]);
        $db->commit();
    }
    responder(['ok' => true, 'datos' => $r], in_array($accion, ['crear', 'foto_subir'], true) ? 201 : 200);
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    if ($subida) @unlink($subida);
    $codigo = $e instanceof PDOException ? (string) $e->getCode() : '';
    avance_error(match ($codigo) { '23503' => $accion === 'foto_eliminar' ? 'No se puede eliminar esta fotografía: otra tabla la referencia mediante una clave foránea. No se modificó el registro ni su archivo.' : 'El empleado o proyecto relacionado ya no está disponible.', '23505' => 'Ya existe un registro con esos datos.', default => 'No se pudo completar la operación. Intenta nuevamente.' }, in_array($codigo, ['23503', '23505'], true) ? 409 : 500);
}
