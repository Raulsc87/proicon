<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../api/indicadores_proyecto.php';
function caso(string $nombre,?string $inicio,?string $fin,float $real,string $hoy,?float $esperado,string $color): void {
    $r=proyecto_semaforo($inicio,$fin,$real,new DateTimeImmutable($hoy,new DateTimeZone('America/Guatemala')));
    if($r['avance_esperado']!==$esperado || $r['semaforo']!==$color || !is_finite($r['avance_real'])) throw new RuntimeException($nombre.': '.json_encode($r));
    echo "OK: $nombre\n";
}
try {
    caso('sin actividades', '2026-01-01','2026-04-11',0,'2026-02-20',50.0,'ROJO');
    caso('sin fecha fin','2026-01-01',null,50,'2026-02-20',null,'SIN_DATOS');
    caso('sin fecha inicio',null,'2026-04-11',50,'2026-02-20',null,'SIN_DATOS');
    caso('fecha inválida','2026-02-30','2026-04-11',50,'2026-02-20',null,'SIN_DATOS');
    caso('intervalo de cero días','2026-01-01','2026-01-01',0,'2026-01-01',null,'SIN_DATOS');
    caso('fechas invertidas','2026-04-11','2026-01-01',0,'2026-02-20',null,'SIN_DATOS');
    caso('antes de iniciar','2026-01-01','2026-04-11',0,'2025-12-01',0.0,'VERDE');
    caso('vencido con 99%','2026-01-01','2026-04-11',99,'2026-04-12',100.0,'ROJO');
    caso('100% terminado','2026-01-01','2026-04-11',100,'2026-04-12',100.0,'VERDE');
    caso('adelantado','2026-01-01','2026-04-11',60,'2026-02-20',50.0,'VERDE');
    caso('igual al esperado','2026-01-01','2026-04-11',50,'2026-02-20',50.0,'VERDE');
    caso('9.9 puntos','2026-01-01','2026-04-11',40.1,'2026-02-20',50.0,'VERDE');
    caso('exactamente 10 puntos','2026-01-01','2026-04-11',40,'2026-02-20',50.0,'AMARILLO');
    caso('24.9 puntos','2026-01-01','2026-04-11',25.1,'2026-02-20',50.0,'AMARILLO');
    caso('exactamente 25 puntos','2026-01-01','2026-04-11',25,'2026-02-20',50.0,'ROJO');
    caso('día final aún no vencido','2026-01-01','2026-04-11',99,'2026-04-11',100.0,'VERDE');
}catch(Throwable $e){fwrite(STDERR,'FALLO: '.$e->getMessage().PHP_EOL);exit(1);}
