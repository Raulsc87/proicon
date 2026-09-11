<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.use_cookies', '0'); session_cache_limiter('');
$marca = 'AV_' . bin2hex(random_bytes(10)); $sesion = bin2hex(random_bytes(24));
$db = null; $pid = null; $uid = null; $originales = []; $rutas = []; $fallo = false;
function va_q(PDO $db, string $sql, array $p = []): PDOStatement { $q = $db->prepare($sql); $q->execute($p); return $q; }
function va_ok(bool $ok, string $texto): void { if (!$ok) throw new RuntimeException($texto); echo "OK: $texto\n"; }
function va_http(string $ruta, ?string $body = null, string $tipo = 'application/json', bool $auth = true): array {
    global $sesion;
    $op = ['method' => $body === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 20, 'header' => "Content-Type: $tipo\r\n" . ($auth ? "Cookie: PHPSESSID=$sesion\r\n" : '')]; if ($body !== null) $op['content'] = $body;
    $texto = file_get_contents('http://localhost:8000/' . $ruta, false, stream_context_create(['http' => $op]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), $texto];
}
function va_api(string $a, ?array $d = null, array $get = [], bool $auth = true, ?array $file = null): array {
    $body = $d === null ? null : json_encode($d); $tipo = 'application/json';
    if ($file !== null) {
        $b = 'Boundary' . bin2hex(random_bytes(12)); $body = '';
        foreach ($d as $k => $v) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        if ($file) $body .= "--$b\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"{$file[0]}\"\r\nContent-Type: {$file[1]}\r\n\r\n{$file[2]}\r\n";
        $body .= "--$b--\r\n"; $tipo = 'multipart/form-data; boundary=' . $b;
    }
    [$s, $t] = va_http('api/avances.php?' . http_build_query(['accion' => $a] + $get), $body, $tipo, $auth);
    return [$s, json_decode($t, true, 512, JSON_THROW_ON_ERROR)];
}
try {
    ob_start(); require __DIR__ . '/../config/database.local.php'; ob_end_clean(); $db = $conexion; $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (['actividad', 'fotografia_avance', 'bitacora', 'proyecto', 'usuario', 'cliente', 'proveedor', 'material', 'presupuesto', 'detalle_presupuesto', 'proyecto_empleado', 'solicitud_material', 'detalle_solicitud', 'compra', 'detalle_compra', 'factura', 'pago'] as $t) $originales[$t] = va_q($db, "SELECT md5(row_to_json(t)::text) AS r FROM $t t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN);
    $u = va_q($db, 'SELECT id_empleado, id_rol FROM usuario ORDER BY id_usuario LIMIT 1')->fetch(PDO::FETCH_ASSOC); $clave = bin2hex(random_bytes(24));
    $uid = va_q($db, "INSERT INTO usuario (nombre_usuario, contrasena, estado, id_empleado, id_rol) VALUES (:nombre, :clave, 'ACTIVO', :eid, :rid) RETURNING id_usuario", ['nombre' => $marca, 'clave' => $clave, 'eid' => $u['id_empleado'], 'rid' => $u['id_rol']])->fetchColumn();
    session_id($sesion); session_start(); session_write_close();
    [$s, $t] = va_http('api/login.php', json_encode(['usuario' => $marca, 'contrasena' => $clave])); va_ok($s === 200 && json_decode($t, true)['ok'], 'login real con usuario temporal'); unset($clave);
    [$s, $t] = va_http('api/sesion.php'); va_ok($s === 200 && json_decode($t, true)['activa'], 'sesión activa');
    $base = va_q($db, 'SELECT * FROM proyecto ORDER BY id_proyecto LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $pid = va_q($db, 'INSERT INTO proyecto (codigo,nombre,estado,estado_avance,id_cliente) VALUES (:codigo,:nombre,:estado,:avance,:cliente) RETURNING id_proyecto', ['codigo' => $marca, 'nombre' => $marca, 'estado' => $base['estado'], 'avance' => $base['estado_avance'], 'cliente' => $base['id_cliente']])->fetchColumn(); $ctx = ['id_proyecto' => $pid];
    foreach (['listar','obtener','fotos_listar','imagen','crear','editar','estado','foto_subir','foto_editar','foto_eliminar'] as $a) { [$s] = va_api($a, in_array($a,['crear','editar','estado','foto_subir','foto_editar','foto_eliminar'],true) ? $ctx : null, $ctx, false); va_ok($s === 401, "$a exige sesión"); }
    [$s,$r] = va_api('listar',null,['id_proyecto'=>$base['id_proyecto']]); va_ok($s===200 && count($r['datos']['actividades'])>0,'listar actividades reales'); $cat=$r['datos'];
    [$s,$r] = va_api('listar',null,$ctx); va_ok($s===200 && $r['datos']['resumen']['porcentaje']===null,'proyecto sin actividades no divide por cero');
    $a=$ctx+['nombre'=>$marca,'descripcion'=>'Actividad temporal','estado'=>'EN_PROCESO','situacion_tiempo'=>$cat['situaciones'][0],'porcentaje_avance'=>'25.50','fecha_inicio'=>'2026-09-01','fecha_fin_prevista'=>'2026-10-01','fecha_fin_real'=>'','id_empleado_responsable'=>$cat['empleados'][0]['id_empleado']];
    [$s] = va_api('crear',array_replace($a,['estado'=>'INVENTADO'])); va_ok($s===400,'rechaza estado inventado');
    [$s] = va_api('crear',array_replace($a,['porcentaje_avance'=>'100.01'])); va_ok($s===400,'rechaza porcentaje superior a 100');
    [$s] = va_api('crear',array_replace($a,['fecha_inicio'=>'2026-02-30'])); va_ok($s===400,'rechaza fecha inválida');
    [$s] = va_api('crear',array_replace($a,['id_empleado_responsable'=>2147483647])); va_ok($s===409,'rechaza responsable inexistente');
    [$s,$r] = va_api('crear',$a); va_ok($s===201,'crear actividad temporal'); $aid=$r['datos']['id_actividad'];
    [$s,$r] = va_api('obtener',null,$ctx+['id_actividad'=>$aid]); va_ok($s===200 && $r['datos']['responsable']!==null,'consultar actividad y responsable real');
    [$s,$r] = va_api('editar',array_replace($a,['id_actividad'=>$aid,'nombre'=>$marca.'_EDITADA','porcentaje_avance'=>'50.00','id_empleado_responsable'=>''])); va_ok($s===200 && $r['datos']['id_empleado_responsable']===null && $r['datos']['nombre']===$marca.'_EDITADA','editar actividad y responsable opcional');
    [$s,$r] = va_api('crear',array_replace($a,['nombre'=>$marca.'_2','estado'=>'FINALIZADO','porcentaje_avance'=>'100','fecha_fin_real'=>'2026-09-10'])); va_ok($s===201,'crear segunda actividad finalizada');
    [$s,$r] = va_api('listar',null,$ctx); va_ok($s===200 && (int)$r['datos']['resumen']['total']===2 && (float)$r['datos']['resumen']['porcentaje']===50.0,'una de dos finalizadas: avance 50%');
    [$s] = va_api('estado',$ctx+['id_actividad'=>$aid,'estado'=>'FINALIZADO']); va_ok($s===200,'cambiar estado a FINALIZADO');
    [$s,$r] = va_api('listar',null,$ctx); va_ok((float)$r['datos']['resumen']['porcentaje']===100.0 && $r['datos']['estado_avance']===$base['estado_avance'],'avance 100% sin cambiar semáforo del proyecto');
    [$s] = va_api('estado',$ctx+['id_actividad'=>$aid,'estado'=>'EN_PROCESO']); va_ok($s===200,'volver a estado EN_PROCESO');
    [$s] = va_api('editar',array_replace($a,['id_proyecto'=>$base['id_proyecto'],'id_actividad'=>$aid])); va_ok($s===404,'rechaza edición desde otro proyecto');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='); $foto=$ctx+['descripcion'=>$marca,'id_bitacora'=>2147483647,'creada_por'=>2147483647];
    [$s]=va_api('foto_subir',$foto,[],true,[]); va_ok($s===400,'exige archivo de imagen');
    [$s]=va_api('foto_subir',$foto,[],true,['ejecutable.php','image/png',$png]); va_ok($s===400,'rechaza extensión ejecutable');
    [$s]=va_api('foto_subir',$foto,[],true,['falsa.png','image/png','<?php echo 1;']); va_ok($s===400,'rechaza MIME falso');
    [$s]=va_api('foto_subir',$foto,[],true,['grande.png','image/png',$png.str_repeat('x',2*1024*1024)]); va_ok($s===400,'rechaza imagen mayor de 2 MB');
    [$s,$r]=va_api('foto_subir',$foto,[],true,['../../evidencia.png','image/png',$png]); va_ok($s===201,'subir PNG temporal'); $fid=$r['datos']['id_fotografia'];$bid=$r['datos']['id_bitacora'];$rutas[]=$r['datos']['ruta_archivo'];
    va_ok((bool)preg_match('~^uploads/avances/[a-f0-9]{48}\.png$~D',end($rutas)),'ruta segura y nombre aleatorio');
    $b=va_q($db,'SELECT id_proyecto,creada_por FROM bitacora WHERE id_bitacora=:id',['id'=>$bid])->fetch(PDO::FETCH_ASSOC); va_ok((int)$b['id_proyecto']===(int)$pid && (int)$b['creada_por']===(int)$uid,'relación fotografía-bitácora-proyecto y usuario de sesión');
    [$s,$r]=va_api('fotos_listar',null,$ctx); va_ok($s===200 && count($r['datos']['fotos'])===1 && $r['datos']['fotos'][0]['registrada_por']===$marca,'listar evidencia y usuario');
    [$s,$t]=va_http('api/avances.php?'.http_build_query(['accion'=>'imagen','id_fotografia'=>$fid]+$ctx)); va_ok($s===200 && $t===$png,'consultar imagen conserva contenido');
    [$s]=va_http(end($rutas)); va_ok($s===404,'bloquea acceso directo a uploads');
    [$s]=va_api('imagen',null,['id_proyecto'=>$base['id_proyecto'],'id_fotografia'=>$fid]); va_ok($s===404,'imagen no accesible desde otro proyecto');
    // Paginación con filas temporales que referencian el mismo archivo de prueba.
    for($i=0;$i<12;$i++) va_q($db,'INSERT INTO fotografia_avance (ruta_archivo,descripcion,fecha_carga,id_bitacora) VALUES (:ruta,:descripcion,CURRENT_DATE,:bid)',['ruta'=>end($rutas),'descripcion'=>$marca,'bid'=>$bid]);
    [$s,$r]=va_api('fotos_listar',null,$ctx); va_ok(count($r['datos']['fotos'])===12 && $r['datos']['siguiente']!==null,'primera página limitada a 12 fotografías');
    [$s,$r]=va_api('fotos_listar',null,$ctx+['antes'=>$r['datos']['siguiente']]); va_ok(count($r['datos']['fotos'])===1 && $r['datos']['siguiente']===null,'segunda página sin duplicar fotografías');
    foreach(['clientes_listar','proveedores_listar','materiales_listar','proyectos_listar'] as $endpoint){[$s,$t]=va_http('api/'.$endpoint.'.php');va_ok($s===200 && json_decode($t,true)['ok'],"regresión $endpoint");}
    [$s,$t]=va_http('api/proyectos_presupuestos_listar.php?id_proyecto='.$base['id_proyecto']);va_ok($s===200,'regresión presupuestos');
    $origen=va_q($db,'SELECT s.id_solicitud,c.id_compra,f.id_factura,s.id_proyecto FROM solicitud_material s JOIN compra c USING(id_solicitud) JOIN factura f USING(id_compra) LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    foreach(['solicitudes_obtener'=>'id_solicitud','compras_obtener'=>'id_compra','facturas_obtener'=>'id_factura'] as $acc=>$campo){[$s,$t]=va_http('api/adquisiciones.php?'.http_build_query(['accion'=>$acc,'id_proyecto'=>$origen['id_proyecto'],$campo=>$origen[$campo]]));va_ok($s===200 && json_decode($t,true)['ok'],"regresión $acc con detalles");}
    if(in_array('--navegador',$argv,true)){
        $proceso=proc_open(['node',__DIR__.'/pruebas_interfaz_avances.js'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if(!is_resource($proceso))throw new RuntimeException('No se pudo iniciar prueba de navegador');
        fwrite($pipes[0],json_encode(['sesion'=>$sesion,'proyecto'=>$pid,'fotografia'=>$fid]));fclose($pipes[0]);
        echo stream_get_contents($pipes[1]);fclose($pipes[1]);echo stream_get_contents($pipes[2]);fclose($pipes[2]);
        va_ok(proc_close($proceso)===0,'prueba de navegador');
    }
    $bitacoraAntes=va_q($db,'SELECT * FROM bitacora WHERE id_bitacora=:id',['id'=>$bid])->fetch(PDO::FETCH_ASSOC);
    $proyectoAntes=va_q($db,'SELECT * FROM proyecto WHERE id_proyecto=:id',['id'=>$pid])->fetch(PDO::FETCH_ASSOC);
    $rutaAnterior=$rutas[0];
    $edit=$ctx+['id_fotografia'=>$fid,'descripcion'=>$marca.'_EDITADA','fecha_carga'=>'2026-09-09'];
    [$s,$r]=va_api('foto_editar',$edit,[],true,[]);
    va_ok($s===200 && $r['datos']['descripcion']===$edit['descripcion'] && $r['datos']['fecha_carga']==='2026-09-09' && $r['datos']['ruta_archivo']===$rutaAnterior,'editar metadatos sin archivo conserva imagen');
    [$s]=va_api('foto_editar',array_replace($edit,['id_proyecto'=>$base['id_proyecto']]),[],true,[]);va_ok($s===404,'no edita fotografía de otro proyecto');
    [$s]=va_api('foto_eliminar',['id_proyecto'=>$base['id_proyecto'],'id_fotografia'=>$fid]);va_ok($s===404,'no elimina fotografía de otro proyecto');
    [$s]=va_api('foto_editar',$edit,[],true,['mal.php','image/png',$png]);va_ok($s===400,'reemplazo rechaza extensión ejecutable');
    va_ok(is_file(__DIR__.'/../'.$rutaAnterior),'reemplazo inválido conserva archivo anterior');
    // Distinto contenido válido: PNG permite datos posteriores al final de la imagen.
    $pngNuevo=$png."\nPROICON_REEMPLAZO_TEMPORAL";
    [$s,$r]=va_api('foto_editar',$edit,[],true,['nueva.png','image/png',$pngNuevo]);
    va_ok($s===200 && $r['datos']['ruta_archivo']!==$rutaAnterior,'reemplazar imagen genera ruta nueva');$rutaNueva=$r['datos']['ruta_archivo'];$rutas[]=$rutaNueva;
    va_ok(is_file(__DIR__.'/../'.$rutaAnterior),'archivo compartido con otras fotografías se conserva');
    [$s,$t]=va_http('api/avances.php?'.http_build_query(['accion'=>'imagen','id_fotografia'=>$fid]+$ctx));va_ok($s===200 && $t===$pngNuevo,'consulta devuelve la imagen reemplazada');
    [$s,$r]=va_api('foto_editar',$edit,[],true,['otra.png','image/png',$png]);
    va_ok($s===200 && !file_exists(__DIR__.'/../'.$rutaNueva),'reemplazar archivo exclusivo elimina el anterior');$rutaFinal=$r['datos']['ruta_archivo'];$rutas[]=$rutaFinal;
    [$s,$r]=va_api('foto_eliminar',$ctx+['id_fotografia'=>$fid]);va_ok($s===200 && $r['datos']['eliminada'],'eliminar fotografía temporal');
    va_ok(!va_q($db,'SELECT 1 FROM fotografia_avance WHERE id_fotografia=:id',['id'=>$fid])->fetchColumn() && !file_exists(__DIR__.'/../'.$rutaFinal),'registro y archivo exclusivo desaparecen');
    va_ok(va_q($db,'SELECT * FROM bitacora WHERE id_bitacora=:id',['id'=>$bid])->fetch(PDO::FETCH_ASSOC)===$bitacoraAntes,'edición y eliminación conservan bitácora intacta');
    va_ok(va_q($db,'SELECT * FROM proyecto WHERE id_proyecto=:id',['id'=>$pid])->fetch(PDO::FETCH_ASSOC)===$proyectoAntes,'proyecto intacto tras eliminar foto');
    va_ok((bool)va_q($db,'SELECT 1 FROM usuario WHERE id_usuario=:id',['id'=>$uid])->fetchColumn(),'usuario conservado');
    $legacy=va_q($db,'INSERT INTO fotografia_avance (ruta_archivo,descripcion,fecha_carga,id_bitacora) VALUES (:ruta,:descripcion,CURRENT_DATE,:bid) RETURNING id_fotografia',['ruta'=>'fotos/'.$marca.'.jpg','descripcion'=>$marca,'bid'=>$bid])->fetchColumn();
    [$s]=va_api('foto_eliminar',$ctx+['id_fotografia'=>$legacy]);va_ok($s===200,'elimina sólo fila temporal con ruta legacy faltante');
    [$s]=va_api('foto_eliminar',$ctx+['id_fotografia'=>$fid]);va_ok($s===404,'segunda eliminación devuelve no encontrado');
    [$s]=va_api('crear',null,$ctx);va_ok($s===405,'rechaza método incorrecto');
    [$s]=va_api('listar',null,['id_proyecto'=>'invalido']);va_ok($s===400,'rechaza proyecto inválido');
}catch(Throwable $e){if(ob_get_level())ob_end_clean();echo 'FALLO: '.($e instanceof PDOException?'Error de base de datos en prueba.':$e->getMessage()).PHP_EOL;$fallo=true;}
finally{
    if($db instanceof PDO){try{
        $db->beginTransaction();
        $ids=va_q($db,'SELECT id_proyecto FROM proyecto WHERE codigo=:marca AND nombre=:nombre',['marca'=>$marca,'nombre'=>$marca])->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id){
            $rutas=array_merge($rutas,va_q($db,'SELECT f.ruta_archivo FROM fotografia_avance f JOIN bitacora b USING(id_bitacora) WHERE b.id_proyecto=:id',['id'=>$id])->fetchAll(PDO::FETCH_COLUMN));
            foreach(['DELETE FROM fotografia_avance WHERE id_bitacora IN(SELECT id_bitacora FROM bitacora WHERE id_proyecto=:id)','DELETE FROM bitacora WHERE id_proyecto=:id','DELETE FROM actividad WHERE id_proyecto=:id','DELETE FROM proyecto WHERE id_proyecto=:id'] as $sql)echo 'Limpieza: '.va_q($db,$sql,['id'=>$id])->rowCount()." registros temporales.\n";
        }
        echo 'Limpieza usuario: '.va_q($db,'DELETE FROM usuario WHERE id_usuario=:id AND nombre_usuario=:nombre',['id'=>$uid,'nombre'=>$marca])->rowCount().PHP_EOL;
        $db->commit();
        foreach(array_unique($rutas) as $ruta){
            if(!preg_match('~^uploads/avances/[a-f0-9]{48}\.(?:png|jpe?g|webp)$~D',$ruta))throw new RuntimeException('Ruta inválida');
            $archivo=realpath(__DIR__.'/../'.$ruta);$carpeta=realpath(__DIR__.'/../uploads/avances');
            if($archivo && $carpeta && dirname($archivo)===$carpeta)va_ok(unlink($archivo),'imagen temporal eliminada');
        }
        foreach($originales as $t=>$filas)va_ok(va_q($db,"SELECT md5(row_to_json(t)::text) AS r FROM $t t ORDER BY r")->fetchAll(PDO::FETCH_COLUMN)===$filas,"$t conserva originales");
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();echo "FALLO: limpieza o comprobación final incompleta.\n";$fallo=true;}}
    session_id($sesion);session_start();$_SESSION=[];session_destroy();
}
exit($fallo?1:0);
