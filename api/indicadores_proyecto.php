<?php
// Misma agregación que utiliza el módulo de actividades, incluida su precisión.
function actividades_resumen_sql(): string {
    return "COUNT(*) AS total, COUNT(*) FILTER (WHERE estado = 'FINALIZADO') AS finalizadas, ROUND(100.0 * COUNT(*) FILTER (WHERE estado = 'FINALIZADO') / NULLIF(COUNT(*), 0), 1) AS porcentaje";
}
function proyecto_semaforo(?string $inicio, ?string $fin, float $real, DateTimeImmutable $hoy): array {
    $real=max(0,min(100,$real));
    $resultado=['avance_real'=>$real,'avance_esperado'=>null,'atraso'=>null,'semaforo'=>'SIN_DATOS','motivo'=>'Sin información suficiente: faltan fechas válidas.'];
    $fechas=[];
    foreach ([$inicio,$fin] as $valor) {
        if ($valor===null) return $resultado;
        $fecha=DateTimeImmutable::createFromFormat('!Y-m-d',$valor,new DateTimeZone('America/Guatemala'));
        if (!$fecha || $fecha->format('Y-m-d')!==$valor) return $resultado;
        $fechas[]=$fecha;
    }
    [$desde,$hasta]=$fechas;
    $dias=(int)$desde->diff($hasta)->format('%r%a');
    if ($dias<=0) { $resultado['motivo']='Sin información suficiente: el fin debe ser posterior al inicio.'; return $resultado; }
    $hoy=$hoy->setTimezone(new DateTimeZone('America/Guatemala'))->setTime(0,0);
    $transcurridos=(int)$desde->diff($hoy)->format('%r%a');
    $esperado=max(0,min(100,100*$transcurridos/$dias));
    $atraso=round($esperado-$real,8);
    $vencido=$hoy>$hasta && $real<100;
    $color=$vencido || $atraso>=25 ? 'ROJO' : ($atraso>=10 ? 'AMARILLO' : 'VERDE');
    return ['avance_real'=>$real,'avance_esperado'=>round($esperado,2),'atraso'=>round($atraso,2),'semaforo'=>$color,
        'motivo'=>$vencido?'Fecha final vencida y actividades pendientes.':($color==='VERDE'?'Al ritmo previsto o con menos de 10 puntos de atraso.':($color==='AMARILLO'?'Entre 10 y menos de 25 puntos de atraso.':'25 o más puntos de atraso.'))];
}
