<?php
// Sólo lectura de PostgreSQL y sesiones efímeras de usuarios existentes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$sid=trim(stream_get_contents(STDIN));
if(!preg_match('/^[a-f0-9]{48}$/D',$sid))exit(1);
session_id($sid);session_start();
if(($argv[1]??'')==='cerrar'){$_SESSION=[];session_destroy();exit;}
require_once __DIR__.'/../api/autorizacion.php';
try {
    $db=autorizacion_conexion();$db->beginTransaction();$db->exec('SET TRANSACTION READ ONLY');
    $q=$db->prepare("SELECT u.id_usuario,r.nombre AS rol FROM usuario u JOIN rol r USING(id_rol) WHERE r.nombre=:rol AND u.estado='ACTIVO' ORDER BY u.id_usuario LIMIT 1");
    $q->execute(['rol'=>$argv[1]??'ADMINISTRADOR']);$u=$q->fetch(PDO::FETCH_ASSOC);
    if(!$u)throw new RuntimeException('Rol sin usuario');
    $_SESSION=['id_usuario'=>$u['id_usuario']];session_write_close();
    $r=$db->query('SELECT s.id_proyecto,s.id_solicitud,c.id_compra,f.id_factura FROM solicitud_material s JOIN compra c USING(id_solicitud) JOIN factura f USING(id_compra) ORDER BY s.id_solicitud LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $r['rol']=$u['rol'];$r['tablas']=[];
    foreach($db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN) as $tabla){
        if(!preg_match('/^[a-z_]+$/D',$tabla))throw new RuntimeException('Tabla inesperada');
        $r['tablas'][$tabla]=$db->query("SELECT md5(row_to_json(t)::text) AS r FROM $tabla t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN);
    }
    $r['hashes_reconocidos']=true;
    foreach($db->query('SELECT contrasena FROM usuario')->fetchAll(PDO::FETCH_COLUMN) as $hash) if(password_get_info($hash)['algo']===null)$r['hashes_reconocidos']=false;
    $db->commit();echo json_encode($r);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"No se pudo preparar prueba visual de solo lectura.\n");exit(1);}
