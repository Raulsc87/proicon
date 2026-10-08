<?php
// Pruebas locales: permisos de un rol se cambian de forma reversible; nunca usuarios ni contraseñas.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.use_cookies', '0'); session_cache_limiter('');
$db = null; $rol = null; $originales = []; $sesion = ''; $sesiones = []; $fallo = false; $permisosOriginales = null;
function au_ok(bool $valor, string $texto): void { if (!$valor) throw new RuntimeException($texto); echo "OK: $texto\n"; }
function au_q(string $sql, array $params = []): PDOStatement { global $db; $q=$db->prepare($sql); $q->execute($params); return $q; }
function au_http(string $ruta, ?array $datos = null, bool $auth = true): array {
    global $sesion, $sesiones;
    $opts=['method'=>$datos===null?'GET':'POST','ignore_errors'=>true,'timeout'=>25,'header'=>"Content-Type: application/json\r\n".($auth&&$sesion!==''?"Cookie: PHPSESSID=$sesion\r\n":'')];
    if ($datos!==null) $opts['content']=json_encode($datos);
    $texto=file_get_contents('http://localhost:8000/'.$ruta,false,stream_context_create(['http'=>$opts]));
    preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
    if($auth) foreach($http_response_header as $h) if(preg_match('/^Set-Cookie: PHPSESSID=([a-zA-Z0-9,-]+)/i',$h,$c)) { $sesion=$c[1]; $sesiones[]=$sesion; }
    return [(int)($m[1]??0),json_decode($texto,true)];
}
function au_permisos(array $ids): void {
    global $db,$rol;
    $db->beginTransaction();
    try {
        au_q('DELETE FROM rol_permiso WHERE id_rol=:rol',['rol'=>$rol]);
        foreach($ids as $id) au_q('INSERT INTO rol_permiso(id_rol,id_permiso) VALUES(:rol,:id)',['rol'=>$rol,'id'=>$id]);
        $db->commit();
    } catch(Throwable $e) { $db->rollBack(); throw $e; }
}
function au_ejecutar(array $comando): void {
    $p=proc_open($comando,[0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$pipes);
    if(!is_resource($p)) throw new RuntimeException('No se pudo iniciar prueba');
    fclose($pipes[0]); while(!feof($pipes[1])) echo fread($pipes[1],8192); fclose($pipes[1]);
    au_ok(proc_close($p)===0,'regresión '.basename($comando[1]));
}
try {
    // Simula en memoria una configuración que termina con die, sin tocar el archivo local.
    $fuente = file_get_contents(__DIR__.'/../api/autorizacion.php');
    $fuente = str_replace("require __DIR__ . '/../config/database.local.php';", "die('DETALLE_INTERNO_SIMULADO');", $fuente) . "\nautorizacion_conexion();";
    $simulado = proc_open(['php'], [['pipe','r'],['pipe','w'],['redirect',1]], $pipes);
    if (!is_resource($simulado)) throw new RuntimeException('No se pudo simular error de conexión');
    fwrite($pipes[0], $fuente); fclose($pipes[0]); $salida = stream_get_contents($pipes[1]); fclose($pipes[1]);
    au_ok(proc_close($simulado) === 0 && (json_decode($salida,true)['ok'] ?? null) === false && !str_contains($salida,'DETALLE_INTERNO_SIMULADO'), 'fallo de configuración devuelve JSON sin detalles internos');
    ob_start(); require __DIR__.'/../config/database.local.php'; ob_end_clean(); $db=$conexion;
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $tablas=au_q("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    foreach($tablas as $tabla) { if(!preg_match('/^[a-z_]+$/D',$tabla)) throw new RuntimeException('Tabla inesperada'); $originales[$tabla]=au_q("SELECT md5(row_to_json(t)::text) AS r FROM $tabla t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN); }
    $usuarios=au_q("SELECT id_usuario,nombre_usuario,id_rol FROM usuario WHERE estado='ACTIVO' ORDER BY id_usuario")->fetchAll(PDO::FETCH_ASSOC);
    au_ok(count($usuarios)>0,'usuarios existentes disponibles');
    $inicial='';
    foreach($usuarios as $u) {
        // La suite de permisos no lee contraseñas; el login se prueba por separado.
        $sesion=bin2hex(random_bytes(24)); $sesiones[]=$sesion;
        session_id($sesion); session_start(); $_SESSION=['id_usuario'=>$u['id_usuario']]; session_write_close();
        [$s,$r]=au_http('api/sesion.php');
        $esperados=au_q('SELECT p.codigo FROM rol_permiso rp JOIN permiso p USING(id_permiso) WHERE rp.id_rol=:id ORDER BY p.codigo',['id'=>$u['id_rol']])->fetchAll(PDO::FETCH_COLUMN);
        au_ok($s===200 && $r['activa'] && $r['usuario']['id_usuario']==$u['id_usuario'] && $r['usuario']['permisos']===$esperados,'sesión devuelve permisos reales '.$u['id_usuario']);
        if($inicial==='') { $inicial=$sesion; $rol=$u['id_rol']; }
    }
    unset($usuarios,$u);
    $sesion=$inicial;
    $permisosOriginales=au_q('SELECT id_permiso FROM rol_permiso WHERE id_rol=:id ORDER BY id_permiso',['id'=>$rol])->fetchAll(PDO::FETCH_COLUMN);
    $catalogo=au_q('SELECT codigo,id_permiso FROM permiso ORDER BY codigo')->fetchAll(PDO::FETCH_KEY_PAIR);
    $admin=au_q("SELECT p.codigo FROM rol r JOIN rol_permiso rp USING(id_rol) JOIN permiso p USING(id_permiso) WHERE r.nombre='ADMINISTRADOR' ORDER BY p.codigo")->fetchAll(PDO::FETCH_COLUMN);
    au_ok($admin===array_keys($catalogo),'ADMINISTRADOR tiene todos los permisos registrados');
    // Alterar los datos de rol guardados en sesión no concede permisos.
    session_id($sesion);session_start();$_SESSION['rol']='ADMINISTRADOR';$_SESSION['permisos']=array_keys($catalogo);session_write_close();
    [$s]=au_http('api/proveedores_crear.php',['permisos'=>array_keys($catalogo),'rol'=>'ADMINISTRADOR']);
    $esperado = in_array($catalogo['GESTIONAR_PROVEEDORES'], $permisosOriginales) ? 400 : 403;
    au_ok($s===$esperado,'usa permisos reales ante rol/permisos falsos de sesión y cliente');
    $pid=au_q('SELECT id_proyecto FROM proyecto ORDER BY id_proyecto LIMIT 1')->fetchColumn();
    $bid=au_q('SELECT id_presupuesto FROM presupuesto WHERE id_proyecto=:id ORDER BY id_presupuesto LIMIT 1',['id'=>$pid])->fetchColumn();
    $aid=au_q('SELECT id_actividad FROM actividad WHERE id_proyecto=:id ORDER BY id_actividad LIMIT 1',['id'=>$pid])->fetchColumn();
    $cadena=au_q('SELECT s.id_proyecto,s.id_solicitud,c.id_compra,f.id_factura FROM solicitud_material s JOIN compra c USING(id_solicitud) JOIN factura f USING(id_compra) ORDER BY s.id_solicitud LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    // Esta variante comprueba sólo la independencia de fotos/actividades sin repetir regresiones.
    foreach ([['GESTIONAR_FOTOGRAFIAS','--fotos'],['GESTIONAR_ACTIVIDADES','--fotos-lectura']] as [$gestion,$argumento]) {
        au_permisos(array_values(array_intersect_key($catalogo,array_flip(['VER_PROYECTOS','VER_FOTOGRAFIAS',$gestion]))));
        au_ejecutar(['node',__DIR__.'/pruebas_interfaz_autorizacion.js',$argumento]);
    }
    if (!in_array('--solo-fotos', $argv, true)) {
    $casos=[];
    foreach(['clientes'=>'cliente','proveedores'=>'proveedor','materiales'=>'material'] as $plural=>$tabla) {
        $id=au_q("SELECT id_$tabla FROM $tabla ORDER BY id_$tabla LIMIT 1")->fetchColumn();
        foreach(['listar','obtener','crear','editar','estado'] as $accion) $casos[]=[(in_array($accion,['listar','obtener'],true)?'VER_':'GESTIONAR_').strtoupper($plural),'api/'.$plural.'_'.$accion.'.php?id='.$id,in_array($accion,['listar','obtener'],true)?null:[]];
    }
    $casos[]=['VER_MATERIALES','api/materiales_unidades.php',null];
    foreach(['listar','obtener','crear','editar','estado','empleados_listar','empleados_crear','empleados_editar','presupuestos_listar','presupuestos_obtener','presupuestos_crear','presupuestos_editar','detalles_crear','detalles_editar'] as $accion) {
        $lectura=in_array($accion,['listar','obtener','empleados_listar','presupuestos_listar','presupuestos_obtener'],true);
        $modulo=str_starts_with($accion,'empleados_')?'EMPLEADOS_PROYECTO':((str_starts_with($accion,'presupuestos_')||str_starts_with($accion,'detalles_'))?'PRESUPUESTOS':'PROYECTOS');
        $casos[]=[($lectura?'VER_':'GESTIONAR_').$modulo,'api/proyectos_'.$accion.'.php?'.http_build_query(['id'=>$pid,'id_proyecto'=>$pid,'id_presupuesto'=>$bid]),$lectura?null:[]];
    }
    foreach(['listar','obtener','fotos_listar','imagen','crear','editar','estado','foto_subir','foto_editar','foto_eliminar'] as $accion) {
        $lectura=in_array($accion,['listar','obtener','fotos_listar','imagen'],true);
        $casos[]=[($lectura?'VER_':'GESTIONAR_').((str_starts_with($accion,'foto')||$accion==='imagen')?'FOTOGRAFIAS':'ACTIVIDADES'),'api/avances.php?'.http_build_query(['accion'=>$accion,'id_proyecto'=>$pid,'id_actividad'=>$aid,'id_fotografia'=>2147483647]),$lectura?null:[]];
    }
    foreach(['solicitudes_listar','solicitudes_obtener','compras_obtener','facturas_obtener','archivo','solicitudes_crear','solicitudes_revisar','solicitud_detalle_guardar','solicitud_detalle_quitar','compras_crear','compra_detalle_guardar','compras_estado','facturas_crear','pagos_crear'] as $accion) {
        $lectura=in_array($accion,['solicitudes_listar','solicitudes_obtener','compras_obtener','facturas_obtener','archivo'],true);
        $modulo=str_starts_with($accion,'solicitud')?'SOLICITUDES':(str_starts_with($accion,'compra')?'COMPRAS':(str_starts_with($accion,'pago')?'PAGOS':'FACTURAS'));
        $casos[]=[($lectura?'VER_':'GESTIONAR_').$modulo,'api/adquisiciones.php?'.http_build_query(['accion'=>$accion]+$cadena),$lectura?null:[]];
    }
    $casos[]=['VER_PAGOS','api/adquisiciones.php?'.http_build_query(['accion'=>'archivo','id_pago'=>2147483647]+$cadena),null];
    foreach($casos as [$permiso,$ruta,$datos]) { [$s]=au_http($ruta,$datos,false); au_ok($s===401,'401 sin sesión: '.$permiso); }
    foreach(array_unique(array_column($casos,0)) as $permiso) {
        au_permisos(array_values(array_diff_key($catalogo,[$permiso=>true])));
        foreach($casos as [$p,$ruta,$datos]) if($p===$permiso) {
            [$s,$r]=au_http($ruta,$datos);au_ok($s===403 && $r['ok']===false && isset($r['mensaje']),'403 sin '.$permiso.': '.basename(explode('?',$ruta)[0]));
        }
    }
    au_permisos([]);
    foreach(['api/proyectos_catalogos.php','api/adquisiciones.php?accion=catalogos&id_proyecto='.$pid] as $ruta) { [$s]=au_http($ruta);au_ok($s===403,'catálogo no permite acceso sin permisos'); }
    au_permisos(array_values(array_intersect_key($catalogo,array_flip(['VER_PROYECTOS','VER_PROVEEDORES','VER_FACTURAS']))));
    [$s,$r]=au_http('api/adquisiciones.php?'.http_build_query(['accion'=>'facturas_obtener']+$cadena));
    au_ok($s===200 && $r['datos']['pagos']===[] && !isset($r['datos']['saldo'],$r['datos']['total_pagado']),'factura sin permiso de pagos no filtra movimientos ni saldo');
    au_ejecutar(['node',__DIR__.'/pruebas_interfaz_autorizacion.js']);
    au_permisos(array_values($catalogo));
    foreach($casos as [$permiso,$ruta,$datos]) {
        [$s]=au_http($ruta,$datos);
        au_ok($datos===null?in_array($s,[200,404],true):in_array($s,[400,404],true),'acceso permitido y validación conservada: '.$permiso);
    }
    foreach(['clientes','proveedores','materiales'] as $modulo) au_ejecutar(['php',__DIR__.'/pruebas_mantenimiento.php',$modulo]);
    au_ejecutar(['php',__DIR__.'/pruebas_proyectos.php']);
    au_ejecutar(['php','-d','extension=fileinfo',__DIR__.'/pruebas_adquisiciones.php']);
    au_ejecutar(['php','-d','extension=fileinfo',__DIR__.'/pruebas_avances.php','--navegador']);
    au_ejecutar(['node',__DIR__.'/pruebas_interfaz_adquisiciones.js','--edicion']);
    au_ejecutar(['node',__DIR__.'/pruebas_interfaz_resumenes.js']);
    }
} catch(Throwable $e) {
    if(ob_get_level()) ob_end_clean();
    echo 'FALLO: '.($e instanceof PDOException?'Error de base de datos durante prueba.':$e->getMessage()).PHP_EOL;$fallo=true;
} finally {
    if($permisosOriginales!==null) try { au_permisos($permisosOriginales); echo "Permisos originales restaurados.\n"; } catch(Throwable $e) { echo "FALLO: restauración de permisos pendiente.\n";$fallo=true; }
    foreach(array_unique($sesiones) as $sid) { session_id($sid);session_start();$_SESSION=[];session_destroy(); }
    if($db instanceof PDO) foreach($originales as $tabla=>$filas) try { au_ok(au_q("SELECT md5(row_to_json(t)::text) AS r FROM $tabla t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN)===$filas,"$tabla conserva todos los originales"); } catch(Throwable $e) { echo "FALLO: comparación $tabla.\n";$fallo=true; }
}
exit($fallo?1:0);
