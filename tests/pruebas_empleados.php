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
    $usuarios=$db->query("SELECT r.nombre,u.id_usuario FROM usuario u JOIN rol r USING(id_rol) WHERE u.estado='ACTIVO'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cookie=$sesionAdmin=sesion_usuario((int)$usuarios['ADMINISTRADOR']);
    [$s]=http_usuario('empleados.html');verificar($s===200,'ADMINISTRADOR accede a empleados');
    [$s,$r]=http_usuario('api/empleados_listar.php');verificar($s===200&&$r['gestionar'],'ADMINISTRADOR consulta y gestiona');
    $marca='test_emp_'.bin2hex(random_bytes(5));
    $datos=['nombres'=>'  '.$marca.'  ','apellidos'=>'Temporal   prueba','telefono'=>'5555-0000','correo'=>$marca.'@example.test','estado'=>'ACTIVO'];
    foreach([['nombres'=>'   '],['apellidos'=>''],['correo'=>'incorrecto'],['telefono'=>str_repeat('1',21)],['estado'=>'OTRO']] as $cambio){[$s]=http_usuario('api/empleados_crear.php',array_replace($datos,$cambio));verificar($s===400,'validación '.array_key_first($cambio));}
    [$s,$r]=http_usuario('api/empleados_crear.php',$datos);verificar($s===201,'crear empleado temporal');$empleado=(int)$r['datos']['id_empleado'];
    verificar($r['datos']['nombres']===$marca&&$r['datos']['apellidos']==='Temporal prueba','espacios normalizados');
    verificar(huellas($db)['usuario']===$original['usuario'],'crear empleado no crea cuenta');
    [$s,$r]=http_usuario('api/empleados_obtener.php?id='.$empleado);verificar($s===200&&$r['datos']['id_empleado']==$empleado,'consultar empleado');
    foreach([$marca,'Temporal',$marca.'@example.test'] as $buscar){[$s,$r]=http_usuario('api/empleados_listar.php?buscar='.urlencode($buscar));verificar($s===200&&in_array($empleado,array_column($r['datos'],'id_empleado')),'buscar por nombre, apellido o correo');}
    $datos['nombres']=$marca;$datos['apellidos']='Editado';$datos['id']=$empleado;
    [$s]=http_usuario('api/empleados_editar.php',$datos);verificar($s===200,'ADMINISTRADOR edita');
    $cookie=sesion_usuario((int)$usuarios['SECRETARIA']);
    [$s]=http_usuario('empleados.html');verificar($s===200,'SECRETARIA accede a página');
    [$s,$r]=http_usuario('api/empleados_listar.php');verificar($s===200&&$r['gestionar'],'SECRETARIA autorizada para gestionar');
    [$s]=http_usuario('api/empleados_crear.php',array_replace($datos,['nombres'=>'']));verificar($s===400,'SECRETARIA llega a validación de creación');
    $datos['apellidos']='Secretaria';[$s]=http_usuario('api/empleados_editar.php',$datos);verificar($s===200,'SECRETARIA edita');
    foreach(['INACTIVO','ACTIVO'] as $estado){[$s,$r]=http_usuario('api/empleados_estado.php',['id'=>$empleado,'estado'=>$estado]);verificar($s===200&&$r['datos']['estado']===$estado,'SECRETARIA cambia estado '.$estado);}
    $cookie=sesion_usuario((int)$usuarios['ENCARGADO_OBRA']);
    [$s]=http_usuario('empleados.html');verificar($s===200,'ENCARGADO_OBRA accede a consulta');
    [$s,$r]=http_usuario('api/empleados_listar.php');verificar($s===200&&!$r['gestionar'],'ENCARGADO_OBRA sólo consulta');
    foreach(['crear','editar','estado'] as $a){[$s]=http_usuario('api/empleados_'.$a.'.php',$datos);verificar($s===403,'ENCARGADO_OBRA 403 '.$a);}
    [$s,$r]=http_usuario('api/proyectos_catalogos.php');verificar($s===200&&in_array($empleado,array_column($r['datos']['empleados'],'id_empleado')),'catálogo de proyecto incluye empleado ACTIVO');
    $cookie=$sesionAdmin;[$s,$r]=http_usuario('api/usuarios_empleados.php');verificar($s===200&&in_array($empleado,array_column($r['datos'],'id_empleado')),'Nuevo usuario ofrece al empleado sin cuenta');
    $rol=(int)$db->query("SELECT id_rol FROM rol WHERE nombre='COMPRAS'")->fetchColumn();$clave=bin2hex(random_bytes(12));
    $cuenta=['id_empleado'=>$empleado,'nombre_usuario'=>$marca,'id_rol'=>$rol,'estado'=>'ACTIVO','contrasena'=>$clave,'confirmacion'=>$clave];
    [$s]=http_usuario('api/usuarios_crear.php',$cuenta);verificar($s===201,'crear cuenta explícitamente desde empleado disponible');
    [$s]=http_usuario('api/usuarios_crear.php',array_replace($cuenta,['nombre_usuario'=>$marca.'_2']));verificar($s===409,'regla de una cuenta por empleado conservada');
    $q=$db->prepare('SELECT md5(row_to_json(u)::text) FROM usuario u WHERE id_empleado=:id');$q->execute(['id'=>$empleado]);$cuentaAntes=$q->fetchColumn();
    [$s]=http_usuario('api/empleados_estado.php',['id'=>$empleado,'estado'=>'INACTIVO']);verificar($s===200,'inactivar empleado con cuenta');
    $q->execute(['id'=>$empleado]);verificar($q->fetchColumn()===$cuentaAntes,'estado de empleado no cambia cuenta ni contraseña');
    $cookie='';[$s]=http_usuario('api/login.php',['usuario'=>$marca,'contrasena'=>$clave]);verificar($s===200,'login depende de usuario, no del estado laboral');
    foreach(['empleados.html','EMPLEADOS.HTML','api/empleados_listar.php','api/empleados_obtener.php?id='.$empleado] as $ruta){[$s]=http_usuario($ruta);verificar($s===403,'COMPRAS sin permiso: '.$ruta);}
    foreach(['crear','editar','estado'] as $a){[$s]=http_usuario('api/empleados_'.$a.'.php',$datos);verificar($s===403,'COMPRAS 403 '.$a);}
    [$s]=http_usuario('api/logout.php',[]);verificar($s===200,'logout');[$s,$r]=http_usuario('api/sesion.php');verificar($s===200&&!$r['activa'],'sesión finalizada');
    [$s]=http_usuario('api/empleados_listar.php');verificar($s===401,'sin sesión endpoint protegido');
    $cookie=$sesionAdmin;[$s]=http_usuario('api/empleados_estado.php',['id'=>$empleado,'estado'=>'ACTIVO']);verificar($s===200,'ADMINISTRADOR reactiva');
    $ahora=huellas($db);verificar($ahora['proyecto_empleado']===$original['proyecto_empleado'],'asignaciones e historial intactos');
    verificar($ahora['rol']===$original['rol']&&$ahora['permiso']===$original['permiso']&&$ahora['rol_permiso']===$original['rol_permiso'],'roles y permisos sin cambios');
}catch(Throwable $e){echo 'FALLO: '.($e instanceof PDOException?'Error de base de datos durante prueba.':$e->getMessage()).PHP_EOL;$fallo=true;}
finally {
    if(isset($db)&&$empleado!==null){
        $db->beginTransaction();$q=$db->prepare('DELETE FROM usuario WHERE id_empleado=:id');$q->execute(['id'=>$empleado]);
        $q=$db->prepare('DELETE FROM empleado WHERE id_empleado=:id AND nombres=:marca');$q->execute(['id'=>$empleado,'marca'=>$marca]);$db->commit();
    }
    foreach(array_unique($sesiones) as $s){session_id($s);session_start();$_SESSION=[];session_destroy();}
    if(isset($original)){$igual=huellas($db)===$original;echo ($igual?'OK: ':'FALLO: ')."todas las tablas restauradas, originales intactos y temporales eliminados\n";if(!$igual)$fallo=true;}
}
exit($fallo?1:0);
