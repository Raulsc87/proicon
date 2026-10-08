<?php
// No crea usuarios. Las claves anteriores sólo permanecen en memoria durante la migración/prueba.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.use_cookies', '0'); session_cache_limiter('');
require_once __DIR__ . '/../api/autorizacion.php';
$cookie = ''; $sesiones = []; $fallo = false;
function clave_ok(bool $valor, string $mensaje): void {
    if (!$valor) throw new RuntimeException($mensaje);
    echo "OK: $mensaje\n";
}
function clave_http(string $ruta, ?array $datos = null): array {
    global $cookie, $sesiones;
    $op = ['method' => $datos === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 20,
        'header' => "Content-Type: application/json\r\n" . ($cookie !== '' ? "Cookie: PHPSESSID=$cookie\r\n" : '')];
    if ($datos !== null) $op['content'] = json_encode($datos);
    $texto = file_get_contents('http://localhost:8000/' . $ruta, false, stream_context_create(['http' => $op]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    foreach ($http_response_header as $h) if (preg_match('/^Set-Cookie: PHPSESSID=([a-zA-Z0-9,-]+)/i', $h, $c)) { $cookie = $c[1]; $sesiones[] = $cookie; }
    return [(int)($m[1] ?? 0), json_decode($texto, true)];
}
function clave_migrar(): void {
    $p = proc_open(['php', __DIR__ . '/../tools/migrar_contrasenas.php'], [['pipe','r'],['pipe','w'],['redirect',1]], $pipes);
    if (!is_resource($p)) throw new RuntimeException('No se pudo ejecutar migración CLI');
    fclose($pipes[0]); $salida = stream_get_contents($pipes[1]); fclose($pipes[1]);
    clave_ok(proc_close($p) === 0, 'utilidad CLI ejecutada');
    // La salida de la utilidad sólo contiene recuentos; nunca secretos.
    echo $salida;
}
try {
    $db = autorizacion_conexion();
    [$s] = clave_http('api/sesion.php'); clave_ok($s === 200, 'servidor local disponible antes de migrar');
    $usuarios = $db->query('SELECT id_usuario,nombre_usuario,contrasena,id_rol FROM usuario ORDER BY id_usuario')->fetchAll(PDO::FETCH_ASSOC);
    $claves = in_array('--credenciales-stdin', $argv, true) ? json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) : [];
    foreach ($usuarios as $u) {
        if (password_get_info($u['contrasena'])['algo'] === null) $claves[$u['nombre_usuario']] = $u['contrasena'];
        if (!isset($claves[$u['nombre_usuario']]) || !is_string($claves[$u['nombre_usuario']])) throw new RuntimeException('Para repetir el login tras migrar, suministra las claves por entrada estándar con --credenciales-stdin. No se recuperan de los hashes.');
    }
    $originales = [];
    foreach ($db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN) as $tabla) {
        if (!preg_match('/^[a-z_]+$/D', $tabla)) throw new RuntimeException('Nombre de tabla inesperado');
        $exp = $tabla === 'usuario' ? "(to_jsonb(t)-'contrasena')::text" : 'row_to_json(t)::text';
        $originales[$tabla] = $db->query("SELECT md5($exp) AS r FROM $tabla t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN);
    }
    $sqlColumnas = "SELECT column_name,data_type,character_maximum_length,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='usuario' ORDER BY ordinal_position";
    $columnas = $db->query($sqlColumnas)->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('--solo-verificar', $argv, true)) clave_migrar();
    $despues = $db->query('SELECT id_usuario,contrasena FROM usuario ORDER BY id_usuario')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($usuarios as $u) {
        $hash = $despues[$u['id_usuario']]; $clave = $claves[$u['nombre_usuario']];
        clave_ok(password_get_info($hash)['algo'] !== null && $hash !== $clave && password_verify($clave, $hash), 'hash reconocido y clave conservada: ' . $u['nombre_usuario']);
    }
    if (!in_array('--solo-verificar', $argv, true)) clave_migrar();
    clave_ok($db->query('SELECT id_usuario,contrasena FROM usuario ORDER BY id_usuario')->fetchAll(PDO::FETCH_KEY_PAIR) === $despues, 'segunda ejecución conserva los hashes exactamente');
    foreach ($usuarios as $u) {
        $cookie = '';
        [$s,$r] = clave_http('api/login.php', ['usuario'=>$u['nombre_usuario'],'contrasena'=>$claves[$u['nombre_usuario']]]);
        clave_ok($s===200 && $r['ok'], 'login con contraseña actual: '.$u['nombre_usuario']);
        [$s,$r] = clave_http('api/sesion.php');
        $q=$db->prepare('SELECT p.codigo FROM rol_permiso rp JOIN permiso p USING(id_permiso) WHERE rp.id_rol=:id ORDER BY p.codigo');$q->execute(['id'=>$u['id_rol']]);
        $permisos=$q->fetchAll(PDO::FETCH_COLUMN);
        clave_ok($s===200 && $r['activa'] && $r['usuario']['id_usuario']==$u['id_usuario'] && $r['usuario']['permisos']===$permisos, 'sesión y permisos reales: '.$u['nombre_usuario']);
        [$s]=clave_http('api/proyectos_listar.php');clave_ok($s===(in_array('VER_PROYECTOS',$permisos,true)?200:403),'lectura respeta los permisos actuales');
        [$s]=clave_http('api/proveedores_crear.php',[]);clave_ok($s===(in_array('GESTIONAR_PROVEEDORES',$permisos,true)?400:403),'gestión respeta los permisos actuales sin crear registros');
        [$s,$r]=clave_http('api/logout.php',[]);clave_ok($s===200 && $r['ok'],'cerrar sesión');
        [$s,$r]=clave_http('api/sesion.php');clave_ok($s===200 && !$r['activa'],'sesión cerrada');
        [$s]=clave_http('api/proyectos_listar.php');clave_ok($s===401,'endpoint protegido después de cerrar sesión');
    }
    $cookie='';
    [$s,$incorrecta]=clave_http('api/login.php',['usuario'=>'pxicara','contrasena'=>bin2hex(random_bytes(32))]);clave_ok($s===401,'contraseña incorrecta rechazada');
    [$s,$inexistente]=clave_http('api/login.php',['usuario'=>'NO_EXISTE_'.bin2hex(random_bytes(10)),'contrasena'=>bin2hex(random_bytes(16))]);
    clave_ok($s===401 && $incorrecta===$inexistente && $inexistente['mensaje']==='Usuario o contraseña incorrectos.','usuario inexistente devuelve el mismo error genérico');
    [$s]=clave_http('api/login.php',['usuario'=>$usuarios[0]['nombre_usuario'],'contrasena'=>$despues[$usuarios[0]['id_usuario']]]);clave_ok($s===401,'hash almacenado no sirve como contraseña');
    [$s]=clave_http('tools/migrar_contrasenas.php');clave_ok($s===404,'migración bloqueada desde navegador');
    foreach($columnas as &$columna) if($columna['column_name']==='contrasena')$columna['character_maximum_length']=255;
    unset($columna);
    clave_ok($db->query($sqlColumnas)->fetchAll(PDO::FETCH_ASSOC)===$columnas,'único cambio de columnas: contrasena VARCHAR(255)');
    foreach($originales as $tabla=>$filas) {
        $exp=$tabla==='usuario'?"(to_jsonb(t)-'contrasena')::text":'row_to_json(t)::text';
        clave_ok($db->query("SELECT md5($exp) AS r FROM $tabla t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN)===$filas, $tabla.' sin cambios ajenos a la migración');
    }
} catch(Throwable $e) {
    echo 'FALLO: '.($e instanceof PDOException || $e instanceof JsonException ? 'No se pudo completar la verificación.' : $e->getMessage()).PHP_EOL; $fallo=true;
} finally {
    unset($claves,$usuarios,$u,$clave,$hash,$despues);
    foreach(array_unique($sesiones) as $sid) {session_id($sid);session_start();$_SESSION=[];session_destroy();}
}
exit($fallo?1:0);
