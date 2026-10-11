<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.use_cookies','0'); session_cache_limiter('');
require_once __DIR__.'/../api/autorizacion.php';
$cookie='';$sesiones=[];$empleado=null;$fallo=false;
function verificar(bool $ok,string $mensaje): void { if (!$ok) throw new RuntimeException($mensaje); echo "OK: $mensaje\n"; }
function sesion_usuario(int $id): string {
    global $sesiones;
    $s=bin2hex(random_bytes(24));session_id($s);session_start();$_SESSION=['id_usuario'=>$id];session_write_close();$sesiones[]=$s;return $s;
}
function http_usuario(string $ruta,?array $datos=null): array {
    global $cookie,$sesiones;
    $op=['method'=>$datos===null?'GET':'POST','ignore_errors'=>true,'timeout'=>20,'header'=>"Content-Type: application/json\r\nCookie: PHPSESSID=$cookie\r\n"];
    if($datos!==null)$op['content']=json_encode($datos);
    $texto=file_get_contents('http://localhost:8000/'.$ruta,false,stream_context_create(['http'=>$op]));
    preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
    foreach($http_response_header as $h)if(preg_match('/^Set-Cookie: PHPSESSID=([a-zA-Z0-9,-]+)/i',$h,$c)){$cookie=$c[1];$sesiones[]=$cookie;}
    return [(int)($m[1]??0),json_decode($texto,true),$texto];
}
function huellas(PDO $db): array {
    $r=[];foreach($db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN) as $t){
        if(!preg_match('/^[a-z_]+$/D',$t))throw new RuntimeException('Tabla inesperada');
        $r[$t]=$db->query("SELECT md5(row_to_json(t)::text) AS r FROM $t t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN);
    }return $r;
}
try {
    $db=autorizacion_conexion();$original=huellas($db);
    $admin=$db->query("SELECT u.id_usuario,u.nombre_usuario,u.id_rol FROM usuario u JOIN rol r USING(id_rol) WHERE r.nombre='ADMINISTRADOR' AND u.estado='ACTIVO' ORDER BY u.id_usuario LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $secretaria=(int)$db->query("SELECT u.id_usuario FROM usuario u JOIN rol r USING(id_rol) WHERE r.nombre='SECRETARIA' AND u.estado='ACTIVO' LIMIT 1")->fetchColumn();
    $rol=(int)$db->query("SELECT id_rol FROM rol WHERE nombre='SECRETARIA'")->fetchColumn();
    $cookie=$sesionAdmin=sesion_usuario((int)$admin['id_usuario']);
    [$s]=http_usuario('usuarios.html');verificar($s===200,'ADMINISTRADOR accede a usuarios.html');
    [$s,$r,$texto]=http_usuario('api/usuarios_listar.php');verificar($s===200&&!str_contains($texto,'contrasena'),'listado sin contraseñas ni hashes');
    [$s,$r]=http_usuario('api/usuarios_roles.php');verificar($s===200&&count($r['datos'])===5&&!in_array('INGENIERO',array_column($r['datos'],'nombre'),true),'cinco roles vigentes; legacy excluidos');
    [$s]=http_usuario('api/usuarios_estado.php',['id'=>$admin['id_usuario'],'estado'=>'INACTIVO']);verificar($s===409,'autodesactivación rechazada');
    $admins=(int)$db->query("SELECT count(*) FROM usuario u JOIN rol r USING(id_rol) WHERE r.nombre='ADMINISTRADOR' AND u.estado='ACTIVO'")->fetchColumn();
    if($admins===1){[$s,$r]=http_usuario('api/usuarios_editar.php',['id'=>$admin['id_usuario'],'nombre_usuario'=>$admin['nombre_usuario'],'id_rol'=>$rol,'estado'=>'ACTIVO']);verificar($s===409&&str_contains($r['mensaje'],'último administrador'),'cambio de rol del último administrador rechazado');}
    $marca='prueba_usuarios_'.bin2hex(random_bytes(6));$clave=bin2hex(random_bytes(12));
    // Sólo el empleado temporal creado por esta prueba, sin vincular registros originales.
    $q=$db->prepare("INSERT INTO empleado(nombres,apellidos,estado) VALUES (:n,'Prueba temporal','ACTIVO') RETURNING id_empleado");$q->execute(['n'=>$marca]);$empleado=(int)$q->fetchColumn();
    [$s,$r]=http_usuario('api/usuarios_empleados.php');verificar($s===200&&in_array($empleado,array_column($r['datos'],'id_empleado')),'empleado sin cuenta disponible');
    $datos=['id_empleado'=>$empleado,'nombre_usuario'=>$marca,'id_rol'=>$rol,'estado'=>'ACTIVO','contrasena'=>$clave,'confirmacion'=>$clave];
    [$s]=http_usuario('api/usuarios_crear.php',array_replace($datos,['confirmacion'=>'no_coincide']));verificar($s===400,'confirmación de contraseña validada');
    [$s,$r,$texto]=http_usuario('api/usuarios_crear.php',$datos);verificar($s===201&&!str_contains($texto,$clave),'cuenta temporal creada sin revelar contraseña');$id=$r['id'];
    $q=$db->prepare('SELECT contrasena FROM usuario WHERE id_usuario=:id');$q->execute(['id'=>$id]);$hash=$q->fetchColumn();verificar($hash!==$clave&&password_verify($clave,$hash)&&password_get_info($hash)['algo']!==null,'contraseña almacenada como hash válido');
    [$s]=http_usuario('api/usuarios_crear.php',$datos);verificar($s===409,'nombre duplicado rechazado');
    [$s]=http_usuario('api/usuarios_crear.php',array_replace($datos,['nombre_usuario'=>$marca.'_2']));verificar($s===409,'segunda cuenta del mismo empleado rechazada');
    [$s,$r]=http_usuario('api/usuarios_empleados.php');verificar(!in_array($empleado,array_column($r['datos'],'id_empleado')),'empleado con cuenta excluido del selector');
    $cookie='';[$s]=http_usuario('api/login.php',['usuario'=>$marca,'contrasena'=>$clave]);verificar($s===200,'login real de cuenta temporal');
    [$s,$r]=http_usuario('api/sesion.php');verificar($s===200&&$r['usuario']['rol']==='SECRETARIA'&&!in_array('VER_USUARIOS',$r['usuario']['permisos'],true),'permisos reales del rol asignado');
    [$s]=http_usuario('api/usuarios_listar.php');verificar($s===403,'cuenta temporal sin permiso recibe 403');
    $cookie=$sesionAdmin;
    [$s]=http_usuario('api/usuarios_editar.php',['id'=>$id,'nombre_usuario'=>$marca.'_edit','id_rol'=>$rol,'estado'=>'ACTIVO']);verificar($s===200,'editar usuario');
    $q->execute(['id'=>$id]);verificar($q->fetchColumn()===$hash,'editar conserva el hash exactamente');
    [$s]=http_usuario('api/usuarios_editar.php',['id'=>$id,'id_empleado'=>999999,'nombre_usuario'=>$marca.'_edit','id_rol'=>$rol,'estado'=>'ACTIVO']);verificar($s===400,'cambio manual de empleado rechazado');
    [$s,$r]=http_usuario('api/usuarios_obtener.php?id='.$id);verificar($s===200&&$r['datos']['nombre_usuario']===$marca.'_edit'&&!isset($r['datos']['contrasena']),'consulta sin hash');
    [$s,$r]=http_usuario('api/usuarios_listar.php?buscar='.urlencode($marca));verificar($s===200&&count($r['datos'])===1,'búsqueda de usuario/empleado');
    $nueva=bin2hex(random_bytes(12));[$s]=http_usuario('api/usuarios_contrasena.php',['id'=>$id,'contrasena'=>$nueva,'confirmacion'=>$nueva]);verificar($s===200,'cambio de contraseña');
    $q->execute(['id'=>$id]);$hashNuevo=$q->fetchColumn();verificar($hashNuevo!==$hash&&password_verify($nueva,$hashNuevo),'nueva contraseña hasheada');
    $cookie='';[$s]=http_usuario('api/login.php',['usuario'=>$marca.'_edit','contrasena'=>$clave]);verificar($s===401,'contraseña anterior rechazada');
    [$s]=http_usuario('api/login.php',['usuario'=>$marca.'_edit','contrasena'=>$nueva]);verificar($s===200,'nueva contraseña permite login');$sesionTemporal=$cookie;
    $cookie=$sesionAdmin;[$s]=http_usuario('api/usuarios_estado.php',['id'=>$id,'estado'=>'INACTIVO']);verificar($s===200,'desactivar usuario temporal');
    $cookie=$sesionTemporal;[$s]=http_usuario('api/proyectos_listar.php');verificar($s===401,'sesión existente de usuario inactivo pierde acceso');
    $cookie='';[$s]=http_usuario('api/login.php',['usuario'=>$marca.'_edit','contrasena'=>$nueva]);verificar($s===403,'usuario INACTIVO no inicia sesión');
    $cookie=$sesionAdmin;[$s]=http_usuario('api/usuarios_estado.php',['id'=>$id,'estado'=>'ACTIVO']);verificar($s===200,'reactivar usuario');
    $cookie=sesion_usuario($secretaria);
    foreach(['usuarios.html','api/usuarios_listar.php','api/usuarios_obtener.php?id='.$id,'api/usuarios_empleados.php','api/usuarios_roles.php'] as $ruta){[$s]=http_usuario($ruta);verificar($s===403,'SECRETARIA 403: '.$ruta);}
    foreach(['crear','editar','estado','contrasena'] as $a){[$s]=http_usuario('api/usuarios_'.$a.'.php',['id'=>$id]);verificar($s===403,'SECRETARIA 403: '.$a);}
    $cookie='';[$s]=http_usuario('api/usuarios_listar.php');verificar($s===401,'endpoint sin sesión rechazado');
}catch(Throwable $e){echo 'FALLO: '.($e instanceof PDOException?'Error de base de datos durante prueba.':$e->getMessage()).PHP_EOL;$fallo=true;}
finally {
    if(isset($db)&&$empleado!==null){
        $db->beginTransaction();$q=$db->prepare('DELETE FROM usuario WHERE id_empleado=:id');$q->execute(['id'=>$empleado]);
        $q=$db->prepare('DELETE FROM empleado WHERE id_empleado=:id AND nombres=:marca');$q->execute(['id'=>$empleado,'marca'=>$marca]);$db->commit();
    }
    foreach(array_unique($sesiones) as $s){session_id($s);session_start();$_SESSION=[];session_destroy();}
    if(isset($original)) { $igual=huellas($db)===$original;echo ($igual?'OK: ':'FALLO: ')."comparación de todas las tablas; originales intactos y temporales eliminados\n";if(!$igual)$fallo=true; }
}
exit($fallo?1:0);
