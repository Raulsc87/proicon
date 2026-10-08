# Autorización por roles y permisos — PROICON

Implementación y pruebas locales del 7 de octubre de 2026.

Actualización posterior: las contraseñas ya se migraron a hashes; ver [Contraseñas seguras](contrasenas_seguras.md). Este informe conserva los resultados de la implementación original de autorización. La suite de permisos ahora prepara sesiones de prueba sin leer contraseñas; la verificación de login corresponde a la suite de contraseñas. No ejecutar la suite completa de autorización cuando se requiera evitar incluso cambios temporales de permisos.

## Funcionamiento

`api/autorizacion.php` centraliza la conexión PDO, la identidad y las comprobaciones:

- `requerir_sesion()` toma exclusivamente `id_usuario` de la sesión PHP, comprueba que el usuario siga existiendo y esté ACTIVO, y devuelve su identidad actual. Sin sesión válida responde 401 JSON.
- `usuario_tiene_permiso($codigo)` consulta los permisos reales de `usuario → rol → rol_permiso → permiso`.
- `requerir_permiso($codigo)` exige sesión y el código exacto. Si falta, termina la petición con HTTP 403 y `{"ok":false,"mensaje":"No tiene permiso para realizar esta operación."}` antes de consultar datos del módulo, subir archivos o modificar registros.
- `requerir_alguno([...])` protege los catálogos compartidos. Sus respuestas filtran las listas ajenas al acceso del usuario; se conservan los selectores mínimos necesarios para gestionar el módulo autorizado.

La identidad y los permisos se reutilizan sólo dentro de la petición actual. Cada nueva petición vuelve a comprobar PostgreSQL: revocar permisos no requiere cerrar y abrir sesión. No se aceptan roles ni permisos enviados por JavaScript, ni los nombres de rol guardados previamente en la sesión como fuente de autorización. Los fallos de conexión/autorización devuelven un mensaje genérico, sin SQL ni credenciales.

`api/sesion.php` conserva `activa` y `usuario.nombre/rol`, añade `ok`, `usuario.id`, `usuario.id_usuario` y `usuario.permisos`. El login existente y la comparación de contraseñas no se modificaron.

ADMINISTRADOR usa sus relaciones reales en `rol_permiso`, sin un atajo que conceda permisos por el nombre de rol enviado por el cliente. La inspección confirmó que tiene los 26 permisos existentes, incluidos VER_USUARIOS y GESTIONAR_USUARIOS. No se crea una pantalla de usuarios.

## Endpoints protegidos

| Familia | Lectura | Escritura |
|---|---|---|
| `clientes_*` | VER_CLIENTES | GESTIONAR_CLIENTES |
| `proveedores_*` | VER_PROVEEDORES | GESTIONAR_PROVEEDORES |
| `materiales_*`, incluido `unidades` | VER_MATERIALES | GESTIONAR_MATERIALES |
| `proyectos_listar/obtener/crear/editar/estado` | VER_PROYECTOS | GESTIONAR_PROYECTOS |
| `proyectos_empleados_*` | VER_EMPLEADOS_PROYECTO | GESTIONAR_EMPLEADOS_PROYECTO |
| `proyectos_presupuestos_*`, `proyectos_detalles_*` | VER_PRESUPUESTOS | GESTIONAR_PRESUPUESTOS |
| `avances.php`: listar/obtener/crear/editar/estado | VER_ACTIVIDADES | GESTIONAR_ACTIVIDADES |
| `avances.php`: fotos_listar/imagen/foto_subir/foto_editar/foto_eliminar | VER_FOTOGRAFIAS | GESTIONAR_FOTOGRAFIAS |
| `adquisiciones.php`: solicitudes y sus detalles | VER_SOLICITUDES | GESTIONAR_SOLICITUDES |
| `adquisiciones.php`: compras y sus detalles/estado | VER_COMPRAS | GESTIONAR_COMPRAS |
| `adquisiciones.php`: facturas y su archivo | VER_FACTURAS | GESTIONAR_FACTURAS |
| `adquisiciones.php`: pagos y comprobantes | VER_PAGOS | GESTIONAR_PAGOS |

También se protegen `proyectos_catalogos.php` y `adquisiciones.php?accion=catalogos`. Los endpoints de lectura requieren VER y los de escritura GESTIONAR; ninguno se infiere automáticamente del otro.

Los datos anidados respetan permisos: una solicitud no devuelve compras sin VER_COMPRAS; una compra no devuelve facturas sin VER_FACTURAS; ni las facturas ni sus listas devuelven pagos, saldo o total pagado sin VER_PAGOS. La descarga de un comprobante exige VER_PAGOS aunque se conozca la URL.

Los flujos siguen siendo los existentes: el detalle general requiere VER_PROYECTOS y cada sección comprueba su permiso adicional; los pagos se consultan dentro de una factura. Las vistas generales reutilizan el listado de proyectos. Los roles existentes que acceden a esas rutas cuentan con VER_PROYECTOS. No se añaden pantallas independientes para módulos relacionados.

## Interfaz

`assets/js/principal.js` expone `Autorizacion.lista`, `puede()` y `marcar()`. Antes de iniciar los módulos carga `api/sesion.php`, adapta menú/tarjetas y bloquea URL directas con “Acceso no autorizado” y enlace al panel. Al volver desde la caché de navegación se recarga para volver a validar permisos.

Los módulos esperan esa comprobación, no solicitan las secciones prohibidas y ocultan las acciones de gestión cuando sólo está permitido consultar. La ocultación utiliza una clase independiente de los estados disabled/hidden propios de los formularios para que la carga de datos no vuelva a mostrar botones prohibidos. Actividades y fotografías tienen permisos independientes. El servidor sigue siendo la barrera de seguridad incluso alterando el DOM o JavaScript.

## Pruebas

- Login real y sesión de los tres usuarios existentes: permisos comparados con sus relaciones reales en PostgreSQL, sin mostrar ni cambiar sus contraseñas.
- Matriz de 55 operaciones: sin sesión → 401; retirando individualmente el permiso necesario → 403; con permiso → lectura permitida o validación normal de datos. Las escrituras reales se verificaron mediante las suites de regresión.
- Rol ADMINISTRADOR contrastado contra todo el catálogo de permisos. No hay actualmente un usuario asociado a ese rol; no se migró ninguno.
- Se simuló un rol ADMINISTRADOR y permisos falsos en la sesión/entrada: el servidor mantuvo la denegación según PostgreSQL.
- Se simuló en memoria una configuración que termina con `die`: la respuesta conserva JSON genérico sin publicar el detalle interno. No se modificó la configuración local.
- Catálogos sin permisos → 403; factura consultable sin filtrar pagos ni importes derivados de pagos.
- Chrome a 1440 y 390 px: tarjetas, menú, proveedores en modo consulta, URL directa prohibida, secciones independientes y pagos ocultos. Sin excepciones JavaScript.
- Fotografías sin VER_ACTIVIDADES: consultar y editar/cancelar funcionan con GESTIONAR_FOTOGRAFIAS; tener GESTIONAR_ACTIVIDADES no muestra Editar/Eliminar fotografías cuando falta su permiso específico.
- Regresión: clientes, proveedores, materiales, proyectos, asignaciones, presupuestos y detalles, actividades, solicitudes, compras, facturas, pagos, fotografías y vistas generales. Incluye altas, ediciones, cambios de estado, archivos, eliminación controlada de fotos, flujo completo y navegación móvil/escritorio.
- `php -l`: 56 archivos PHP, excluyendo configuración local. `node --check`: 17 archivos JavaScript. `git diff --check` sin errores.

Comando completo: `php tests/pruebas_autorizacion.php`, con el servidor iniciado mediante `scripts/iniciar_proicon.ps1`.

La suite cambia temporalmente `rol_permiso` del rol del primer usuario activo para comprobar restricciones y ejecutar las regresiones; debe ejecutarse en el entorno local de pruebas, sin uso concurrente. Restaura las relaciones originales en `finally`. No cambia `usuario.id_rol` ni las contraseñas. Las suites crean y limpian sus propios registros/archivos temporales. La comparación final de las 23 tablas públicas confirma que todos los registros originales, usuarios, roles y permisos quedaron iguales. Las secuencias pueden avanzar por los registros temporales; no se reinician.

## Hallazgos y límites

Los roles originales INGENIERO y ASISTENTE siguen existiendo y conservan sus permisos. Actualmente no tienen gestión de clientes, proveedores, materiales, presupuestos, compras, facturas o pagos; tampoco VER_PAGOS. Esas acciones ahora quedan ocultas o denegadas conforme a la configuración real. No se les concedieron permisos permanentes para conservar accesos que no les corresponden.

Se corrigió la exposición de información anidada en respuestas compartidas y se comprobó que un permiso de gestión de actividades no habilite los botones de fotografías. No se detectaron fallos de integridad en las pruebas. La administración de usuarios sigue pendiente; la migración de contraseñas se realizó posteriormente y está documentada por separado.

La configuración PDO local puede terminar la petición al fallar. La utilidad de autorización intercepta esa terminación para evitar que su mensaje llegue al cliente, además de capturar excepciones normales.

## Archivos

Creados:

- `api/autorizacion.php`
- `tools/inspeccionar_autorizacion.php`
- `tests/pruebas_autorizacion.php`
- `tests/pruebas_interfaz_autorizacion.js`
- `docs/autorizacion_roles.md`

Modificados:

- `api/sesion.php`
- `api/mantenimiento.php`
- `api/proyectos_base.php`
- `api/adquisiciones.php`
- `api/avances.php`
- `assets/css/estilos.css`
- `assets/js/principal.js`
- `assets/js/mantenimiento.js`
- `assets/js/proyectos_comun.js`
- `assets/js/proyectos.js`
- `assets/js/proyecto_detalle.js`
- `assets/js/resumenes_proyectos.js`
- `assets/js/adquisiciones.js`
- `assets/js/avances.js`

Sin cambios de esquema, tablas nuevas, migración de usuarios, contraseñas, commit ni push.
