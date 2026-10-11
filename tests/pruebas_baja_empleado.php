<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../api/autorizacion.php';
require_once __DIR__.'/../api/empleados_guardar.php';
function comprobar(bool $ok,string $mensaje): void { if (!$ok) throw new RuntimeException($mensaje); echo "OK: $mensaje\n"; }
function huellas_baja(PDO $db): array {
    $r=[];
    foreach($db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if(!preg_match('/^[a-z_]+$/D',$t))throw new RuntimeException('Tabla inesperada');
        $r[$t]=$db->query("SELECT md5(row_to_json(t)::text) AS r FROM $t t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN);
    }return $r;
}
$fallo=false;
try {
    $db=autorizacion_conexion();$antes=huellas_baja($db);$db->beginTransaction();
    // IDs negativos aleatorios: sólo fixtures dentro de la transacción, sin avanzar secuencias.
    $id=-random_int(100000,2000000000);
    $q=$db->prepare("INSERT INTO empleado(id_empleado,nombres,apellidos,estado) VALUES (:id,'Temporal','Prueba baja','ACTIVO')");$q->execute(['id'=>$id]);
    $proyectos=$db->query('SELECT id_proyecto FROM proyecto ORDER BY id_proyecto LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
    comprobar(count($proyectos)>=2,'proyectos existentes disponibles sin modificarlos');
    $q=$db->prepare("INSERT INTO proyecto_empleado(id_proyecto,id_empleado,funcion_en_proyecto,fecha_asignacion,estado) VALUES (:p,:e,'Electricista','2026-10-01',:estado)");
    foreach($proyectos as $i=>$p)$q->execute(['p'=>$p,'e'=>$id,'estado'=>$i===2?'INACTIVO':'ACTIVO']);
    $q=$db->prepare("INSERT INTO usuario(id_usuario,nombre_usuario,contrasena,estado,id_empleado,id_rol) SELECT :id,:nombre,:hash,'ACTIVO',:empleado,id_rol FROM rol WHERE nombre='ADMINISTRADOR' LIMIT 1");
    $q->execute(['id'=>$id,'nombre'=>'baja_temporal_'.abs($id),'hash'=>password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),'empleado'=>$id]);
    $q=$db->prepare('SELECT * FROM proyecto_empleado WHERE id_empleado=:id ORDER BY id_proyecto');$q->execute(['id'=>$id]);$historial=$q->fetchAll(PDO::FETCH_ASSOC);
    $u=$db->prepare('SELECT md5(row_to_json(u)::text) FROM usuario u WHERE id_empleado=:id');$u->execute(['id'=>$id]);$usuario=$u->fetchColumn();
    $estado=$db->prepare('SELECT estado FROM empleado WHERE id_empleado=:id');
    $db->exec('SAVEPOINT fixture');
    foreach(['estado','editar'] as $accion) {
        $sql="UPDATE empleado SET estado=:estado WHERE id_empleado=:id RETURNING id_empleado,estado";
        if ($accion==='editar') $sql="UPDATE empleado SET nombres='Temporal editado',estado=:estado WHERE id_empleado=:id RETURNING id_empleado,estado";
        $r=empleados_guardar($db,$sql,['id'=>$id,'estado'=>'INACTIVO'],$accion);
        comprobar($r['datos']['estado']==='INACTIVO',"baja por $accion: empleado INACTIVO");
        comprobar(str_contains($r['advertencia']??'','cuenta de usuario activa'),"baja por $accion: advertencia de acceso independiente");
        $q->execute(['id'=>$id]);$bajas=$q->fetchAll(PDO::FETCH_ASSOC);
        $esperado=array_map(static function($e){$e['estado']='INACTIVO';return $e;},$historial);
        comprobar($bajas===$esperado,"baja por $accion: todas las asignaciones inactivas; función, fecha e historial intactos");
        $u->execute(['id'=>$id]);comprobar($u->fetchColumn()===$usuario,'usuario y contraseña intactos');
        $disponible=$db->prepare("SELECT 1 FROM empleado WHERE id_empleado=:id AND estado='ACTIVO'");$disponible->execute(['id'=>$id]);comprobar(!$disponible->fetchColumn(),'empleado de baja no está entre disponibles activos');
        $r=empleados_guardar($db,$sql,['id'=>$id,'estado'=>'ACTIVO'],$accion);
        $q->execute(['id'=>$id]);comprobar($r['datos']['estado']==='ACTIVO'&&$q->fetchAll(PDO::FETCH_ASSOC)===$bajas,'reactivar empleado no reactiva asignaciones');
        $db->exec('ROLLBACK TO SAVEPOINT fixture');
        $estado->execute(['id'=>$id]);$q->execute(['id'=>$id]);
        comprobar($estado->fetchColumn()==='ACTIVO'&&$q->fetchAll(PDO::FETCH_ASSOC)===$historial,'rollback restaura conjuntamente empleado y asignaciones');
    }
} catch(Throwable $e) { $fallo=true;echo 'FALLO: '.($e instanceof PDOException?'Error de base de datos durante prueba.':$e->getMessage()).PHP_EOL; }
finally {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    if(isset($antes)) { $iguales=huellas_baja($db)===$antes;echo ($iguales?'OK: ':'FALLO: ')."todas las tablas intactas; fixtures revertidos sin DELETE\n";if(!$iguales)$fallo=true; }
}
exit($fallo?1:0);
