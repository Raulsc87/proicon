<?php
require_once __DIR__.'/autorizacion.php';
require_once __DIR__.'/indicadores_proyecto.php';
requerir_sesion();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET');autorizacion_error(405,'Método no permitido.'); }
try {
    $db=autorizacion_conexion();$db->beginTransaction();
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $hoy=new DateTimeImmutable('today',new DateTimeZone('America/Guatemala'));
    $sql="SELECT p.id_proyecto,p.codigo,p.nombre,p.estado,p.fecha_inicio,p.fecha_fin_estimada,
        COALESCE(a.total,0) AS total,COALESCE(a.finalizadas,0) AS finalizadas,COALESCE(a.porcentaje,0) AS porcentaje
        FROM proyecto p LEFT JOIN (SELECT id_proyecto,".actividades_resumen_sql()." FROM actividad GROUP BY id_proyecto) a USING(id_proyecto)";
    $q=$db->prepare($sql);$q->execute();$proyectos=[];$activos=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $p=array_merge($p,proyecto_semaforo($p['fecha_inicio'],$p['fecha_fin_estimada'],(float)$p['porcentaje'],$hoy));
        unset($p['porcentaje']);$p['total']=(int)$p['total'];$p['finalizadas']=(int)$p['finalizadas'];
        $proyectos[]=$p;if($p['estado']==='EN_PROCESO')$activos[]=$p;
    }
    // Una sola versión APROBADO por proyecto. Empates de versión se excluyen como ambiguos.
    $q=$db->prepare("WITH maximas AS (
        SELECT id_proyecto,MAX(version) AS version FROM presupuesto WHERE estado='APROBADO' GROUP BY id_proyecto
    ), candidatos AS (
        SELECT p.id_proyecto,MIN(p.id_presupuesto) AS id_presupuesto,COUNT(*) AS cantidad
        FROM presupuesto p JOIN maximas m USING(id_proyecto,version) WHERE p.estado='APROBADO' GROUP BY p.id_proyecto
    ) SELECT COALESCE((SELECT ROUND(SUM(d.cantidad*d.precio_unitario),2) FROM detalle_presupuesto d
        JOIN candidatos c USING(id_presupuesto) WHERE c.cantidad=1),0)::text AS presupuestado,
        (SELECT COUNT(*) FROM candidatos WHERE cantidad>1) AS ambiguos,
        (SELECT COUNT(*) FROM candidatos WHERE cantidad=1) AS incluidos,
        (SELECT COUNT(*) FROM cliente) AS clientes,
        (SELECT COUNT(*) FROM empleado WHERE estado='ACTIVO') AS empleados_activos");
    $q->execute();$resumen=$q->fetch(PDO::FETCH_ASSOC);$db->commit();
    $orden=['ROJO'=>0,'AMARILLO'=>1,'SIN_DATOS'=>2,'VERDE'=>3];
    usort($proyectos,static fn($a,$b)=>($orden[$a['semaforo']]<=>$orden[$b['semaforo']]) ?: strcmp($a['nombre'],$b['nombre']) ?: ($a['id_proyecto']<=>$b['id_proyecto']));
    $riesgos=array_values(array_filter($proyectos,static fn($p)=>$p['estado']==='EN_PROCESO' && in_array($p['semaforo'],['ROJO','AMARILLO'],true)));
    $puedeVer=usuario_tiene_permiso('VER_PROYECTOS');
    echo json_encode(['ok'=>true,'datos'=>[
        'fecha_calculo'=>$hoy->format('Y-m-d'),'zona_horaria'=>'America/Guatemala',
        'indicadores'=>['proyectos_activos'=>count($activos),'clientes'=>(int)$resumen['clientes'],'empleados_activos'=>(int)$resumen['empleados_activos'],
            'presupuestado'=>$resumen['presupuestado'],'presupuestos_incluidos'=>(int)$resumen['incluidos'],'presupuestos_ambiguos'=>(int)$resumen['ambiguos'],
            'avance_general'=>count($activos)?round(array_sum(array_column($activos,'avance_real'))/count($activos),2):0],
        'proyectos'=>$puedeVer?array_slice($proyectos,0,10):[], 'total_proyectos'=>$puedeVer?count($proyectos):0,
        'riesgos'=>$puedeVer?array_slice($riesgos,0,5):[], 'total_riesgos'=>$puedeVer?count($riesgos):0,
        'puede_ver_proyectos'=>$puedeVer,
        'regla_presupuesto'=>'Versión APROBADO más alta por proyecto; excluye empates de versión.',
        'regla_activos'=>'Proyectos en estado EN_PROCESO.'
    ]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    autorizacion_error(500,'No se pudieron cargar los indicadores. Intenta nuevamente.');
}
