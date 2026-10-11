<?php
// Rutina interna; la transacción la controla el llamador.
function empleados_guardar(PDO $db, string $sql, array $params, string $accion): array {
    if (!$db->inTransaction()) throw new LogicException('Se requiere una transacción.');
    $anterior = null;
    if ($accion !== 'crear') {
        $q=$db->prepare('SELECT estado FROM empleado WHERE id_empleado=:id FOR UPDATE');
        $q->execute(['id'=>$params['id']]); $anterior=$q->fetchColumn();
        if ($anterior===false) return ['datos'=>false,'advertencia'=>null];
    }
    $q=$db->prepare($sql);$q->execute($params);$r=$q->fetch(PDO::FETCH_ASSOC);
    $advertencia=null;
    if ($r && $anterior==='ACTIVO' && $r['estado']==='INACTIVO') {
        $q=$db->prepare("UPDATE proyecto_empleado SET estado='INACTIVO' WHERE id_empleado=:id AND estado='ACTIVO'");
        $q->execute(['id'=>$r['id_empleado']]);
        $q=$db->prepare("SELECT 1 FROM usuario WHERE id_empleado=:id AND estado='ACTIVO' LIMIT 1");
        $q->execute(['id'=>$r['id_empleado']]);
        if ($q->fetchColumn()) $advertencia='Este empleado tiene una cuenta de usuario activa. Revise Administración de Usuarios si también desea retirar su acceso al sistema.';
    }
    return ['datos'=>$r,'advertencia'=>$advertencia];
}
