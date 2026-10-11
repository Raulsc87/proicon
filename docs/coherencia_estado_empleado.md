# Estado laboral y asignaciones

La transición ACTIVO → INACTIVO desde Dar de baja o Editar ahora actualiza al empleado y todas sus asignaciones ACTIVO dentro de una misma transacción. Primero bloquea la fila del empleado con SELECT FOR UPDATE. Cualquier fallo revierte la operación completa. Las asignaciones ya inactivas y sus demás campos se conservan.

Reactivar sólo cambia empleado.estado; las asignaciones antiguas quedan INACTIVO y requieren gestión manual. No se modifica usuario.estado. Si la persona tiene una cuenta ACTIVA, la respuesta incluye una advertencia informativa que muestra la interfaz. Ambas confirmaciones explican el efecto de la baja.

El listado de asignaciones mantiene el campo estado existente para la asignación y añade estado_global_empleado desde empleado.estado. En la tabla se muestran «Estado empleado» y «Estado asignación». El selector sigue excluyendo empleados inactivos al crear nuevas asignaciones y conserva la persona histórica al editar.

## Archivos

- Nuevo `api/empleados_guardar.php`: rutina interna compartida por creación/edición/cambio de estado, ejecutada dentro de la transacción del endpoint.
- Modificados `api/empleados_comun.php`, `api/proyectos_base.php`, `assets/js/empleados.js`, `assets/js/proyecto_detalle.js` y `proyecto_detalle.html`.
- Nueva prueba `tests/pruebas_baja_empleado.php`; ampliada `tests/pruebas_mejoras_empleados.js` para advertencia y columnas de estado.
- Este informe sustituye el comportamiento anterior documentado que mantenía activas las asignaciones al dar de baja.

## Pruebas

`php tests/pruebas_baja_empleado.php`: SQL real con empleado, cuenta y asignaciones temporales dentro de una transacción. Verifica ambos caminos de baja, todas las asignaciones activas inactivadas, historial/función/fecha conservados, advertencia de cuenta activa, cuenta y hash intactos, ausencia del conjunto de empleados activos y reactivación sin alterar las asignaciones. Comprueba también rollback conjunto.

La prueba ejecuta la misma rutina de persistencia que usan los endpoints. No hace commits de fixtures ni DELETE; usa IDs temporales negativos para no avanzar secuencias y revierte todo con ROLLBACK. Las huellas de todas las tablas antes/después coinciden.

Pruebas de navegador: aviso informativo, dos estados en tabla, historial visible, inactivos fuera de nuevas opciones, edición y selectores a 1440/390 px. Las escrituras de la prueba de navegador siguen simuladas; la persistencia se verifica con la prueba PHP transaccional anterior.

Sin cambios estructurales en PostgreSQL, sin eliminaciones, sin cambios de roles/permisos ni cuentas originales. Sin commit ni push.
