# Dashboard y semáforo automático — primera versión

## Archivos

Creados:

- `api/dashboard.php`: endpoint GET protegido por sesión, respuesta JSON única para el panel.
- `api/indicadores_proyecto.php`: agregación compartida de actividades y cálculo reutilizable del semáforo.
- `assets/js/dashboard.js`, `assets/css/dashboard.css`: indicadores y presentación adaptable.
- `tests/pruebas_indicadores.php`, `tests/pruebas_interfaz_dashboard.js`.
- Este informe.

Modificados:

- `principal.html`: indicadores, progreso general, riesgos y tabla de proyectos; conserva las seis tarjetas y rutas existentes.
- `api/avances.php`: reutiliza la misma expresión SQL de su resumen de actividades desde el nuevo helper. El SQL resultante, respuesta, precisión y comportamiento de las barras individuales se conservan.

## Indicadores y reglas de selección

- Proyectos activos: `proyecto.estado = 'EN_PROCESO'`, el estado real de actividad encontrado en la base. No se inventan otros estados activos.
- Clientes: cantidad total de registros de cliente.
- Empleados activos: cantidad con `empleado.estado = 'ACTIVO'`.
- Presupuestado: `SUM(detalle_presupuesto.cantidad * precio_unitario)` de la versión APROBADO más alta de cada proyecto. Incluye proyectos de todos los estados. Borradores, pendientes, rechazados y aprobados anteriores no se suman.
- Avance general: promedio simple de avances reales de proyectos EN_PROCESO; cero si no hay proyectos activos, con explicación visible.
- Tabla: hasta 10 proyectos; prioriza rojo y amarillo. Incluye enlace al listado completo.
- Atención: hasta cinco proyectos EN_PROCESO, primero rojos y luego amarillos; sección oculta cuando no hay riesgos.

La inspección encontró varias versiones APROBADO de un mismo proyecto y ninguna columna de presupuesto vigente. La mayor versión aprobada es una regla explícita de esta primera versión; no cambia el estado de versiones anteriores. Tampoco hay restricción única de versión por proyecto: si dos presupuestos empatan en la versión aprobada máxima, ese proyecto se excluye del monto y se muestra cuántos fueron excluidos, sin elegir un ID arbitrario. Los importes se calculan en NUMERIC y se transportan como texto para conservar los decimales monetarios.

## Fórmulas

Avance real = `100 × actividades FINALIZADO / total de actividades`. Reutiliza la agregación del módulo de actividades, redondeada a un decimal; en el dashboard un proyecto sin actividades tiene 0%. No se utiliza el porcentaje individual de cada actividad ni se pondera por costo.

Avance esperado = `100 × días transcurridos desde fecha_inicio / días entre fecha_inicio y fecha_fin_estimada`, limitado a 0–100. Se usan fechas de calendario y el día actual en America/Guatemala. Antes de iniciar es 0%; después del fin es 100%.

Si faltan fechas, son inválidas, están invertidas o inicio y fin coinciden, el esperado es null y el semáforo neutral muestra «Sin información suficiente». No hay división por cero. Una actividad finalizada al 100% también permanece neutral si faltan fechas, pues no existe una comparación de calendario válida.

Atraso = esperado − real, en puntos porcentuales:

- VERDE: atraso menor de 10, incluido avance igual o superior al esperado.
- AMARILLO: atraso mayor o igual a 10 y menor de 25.
- ROJO: atraso mayor o igual a 25.
- Prevalece ROJO si el día actual es posterior a fecha_fin_estimada y avance real es menor de 100%.

El día final no se considera vencido hasta el día siguiente. Los límites se evalúan antes del redondeo de presentación del esperado; se normaliza únicamente la imprecisión de coma flotante a ocho decimales. El esperado y atraso se muestran con hasta dos decimales.

## estado_avance y alcance

No se lee ni actualiza `proyecto.estado_avance` para determinar el color automático. El dashboard usa exclusivamente `proyecto_semaforo()`, sin selector manual, triggers ni escrituras. Cada carga o clic en Actualizar indicadores recalcula el resultado.

El campo y la interfaz manual de los módulos anteriores se conservan por compatibilidad, dado el requisito de no alterar su lógica. Son un registro previo, no la fuente del semáforo automático del panel; la distinción aparece también en la tabla del dashboard. Esta versión no migra ni sustituye esos controles históricos en otras pantallas.

## Seguridad y consultas

Una sola petición a `api/dashboard.php` obtiene los indicadores. La lectura usa una transacción REPEATABLE READ READ ONLY y PDO. Sin sesión devuelve 401; métodos distintos de GET devuelven 405. Los errores internos se sustituyen por un mensaje genérico.

Los indicadores agregados están disponibles para sesiones activas, conforme al alcance solicitado. El detalle de proyectos y los riesgos sólo se devuelven con VER_PROYECTOS. Los enlaces también verifican ese permiso; las tarjetas originales conservan sus controles de autorización. No se agregaron acciones de pagos ni gestión de otros módulos.

## Pruebas y resultados

- `php tests/pruebas_indicadores.php`: 16 casos correctos: sin actividades, fechas faltantes/incorrectas/invertidas/iguales, antes de iniciar, vencido al 99%, 100% terminado, adelantado, igual al esperado, 9.9/10/24.9/25 puntos de atraso y día final.
- `node tests/pruebas_interfaz_dashboard.js`: ADMINISTRADOR, SECRETARIA y ENCARGADO_OBRA a 1440, 1024, 768 y 390 px; indicadores contrastados con los endpoints existentes de clientes, empleados, proyectos, presupuestos y actividades; seis tarjetas navegables para cada rol; permisos de Usuarios conservados.
- Logout, rechazo de login inválido y dashboard sin sesión 401 correctos. El ingreso con contraseñas válidas existentes no se volvió a ejecutar porque no se proporcionaron credenciales; las pruebas usan sesiones efímeras de los usuarios reales, eliminadas al finalizar.
- Comparación de huellas de todas las tablas antes/después: datos intactos. No se ejecutaron INSERT, UPDATE ni DELETE de prueba.
- Sin excepciones JavaScript ni desbordamiento horizontal de página. Capturas revisadas en escritorio y móvil.
- Regresión: `pruebas_interfaz_empleados.js`, `pruebas_interfaz_usuarios.js`, `pruebas_interfaz_adquisiciones.js --edicion`, incluyendo clientes, proyectos, empleados asignados, presupuestos, actividades, edición y Cancelar.
- `php -l`: 84 archivos; `node --check`: 26 archivos; `git diff --check`: correcto.

Limitaciones: avance por conteo de actividades (no ponderado), definición actual de activo limitada a EN_PROCESO y selección presupuestaria explícita por versión aprobada máxima. Apache no forma parte de estas pruebas; se usó el servidor local del proyecto. Sin cambios de esquema, datos, roles ni permisos; sin commit ni push.
