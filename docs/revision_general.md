# Revisión del prototipo PROICON — 10 de septiembre de 2026

## Correcciones

- Fotografías: alta, edición y eliminación reutilizan el cargador de la galería. Se renueva la URL de imagen tras cada cambio y se recuperan las páginas que estaban visibles. El formulario pertenece ahora a Evidencia fotográfica, se limpia y cierra al guardar, y el resultado se anuncia en esa sección. Si falla la lectura después de una escritura exitosa, se informa que el cambio se guardó y se permite actualizar sin repetir la escritura.
- Formularios de mantenimiento y proyectos: limpieza al guardar/cancelar; limpieza también al cambiar el estado de un mantenimiento. Se conservan el foco, desplazamiento y botones de edición existentes.
- Importes en tablas de proyectos: espacio inseparable después de Q y celdas monetarias sin salto de línea. Se mantiene el total destacado y el scroll interno del detalle de presupuesto.
- Solicitudes: se rechaza una fecha necesaria anterior a la fecha de solicitud.
- Login: exige POST, rechaza JSON y tipos inválidos, evita respuestas con detalles de conexión, impide caché y renueva el identificador de sesión tras autenticarse.
- Registro antiguo: guardaba contraseñas en localStorage y anunciaba cuentas que no creaba en PostgreSQL. Se retiró esa persistencia y se muestra un aviso de registro no disponible con enlace al login. No se creó un módulo de usuarios ni se alteraron credenciales existentes.
- El diagnóstico de conexión solo puede ejecutarse por CLI.

## Pruebas realizadas

Servidor iniciado mediante `scripts/iniciar_proicon.ps1`.

| Prueba | Resultado |
|---|---|
| `php tests/pruebas_mantenimiento.php clientes` | Listar, crear, consultar, buscar, editar, activar/desactivar, validaciones y limpieza correctos. |
| Mismo comando para `proveedores` y `materiales` | Correctos; unidades y FK verificadas en materiales. |
| `php tests/pruebas_proyectos.php` | Proyectos, asignaciones ACTIVO/INACTIVO, presupuestos BORRADOR/PENDIENTE/APROBADO/RECHAZADO, detalles, cálculos y validaciones correctos. |
| `php -d extension=fileinfo tests/pruebas_adquisiciones.php` | Login → proyecto temporal con cliente → presupuesto → actividad → solicitud → compras → factura → pagos → fotografía del mismo proyecto mediante bitácora. Saldo final cero y cierre de sesión correctos. También cubre entradas malformadas del login y fechas invertidas. |
| `php -d extension=fileinfo tests/pruebas_avances.php --navegador` | Actividades, estados, validación de imágenes, metadatos, reemplazo, eliminación de archivo exclusivo, conservación de archivo compartido, bitácora/proyecto/usuario intactos y limpieza correctos. |
| Navegador de fotografías a 1440 y 390 px | Envíos reales: subir, consultar, cambiar descripción, reemplazar y eliminar sin navegar ni recargar. Se verificó el píxel azul de la imagen reemplazada, además de su contenido descargado. |
| `node tests/pruebas_interfaz_adquisiciones.js --edicion` | Editar, foco, desplazamiento y Cancelar en mantenimientos, proyectos, asignaciones, presupuestos y actividades; navegación y tablas de proyecto/solicitud/compra/factura en escritorio y móvil. Foto faltante con mensaje. Sin excepciones JavaScript. |
| `php -l` | 52 archivos PHP del proyecto y pruebas sin errores; configuración local excluida de la revisión de archivos. |
| `node --check` | 14 archivos JavaScript sin errores. |
| `git diff --check` | Sin errores. |

Las suites se ejecutaron secuencialmente para no interferir con sus comparaciones de datos. Se eliminaron registros y archivos temporales respetando las FK; las comparaciones finales confirmaron que los registros originales permanecieron iguales. Las secuencias PostgreSQL pueden avanzar al insertar y eliminar pruebas; no se reiniciaron.

## Seguridad, datos y límites

Los endpoints de datos probados exigen sesión y rechazan operaciones desde otro proyecto cuando corresponde. Las consultas con entrada externa revisadas usan parámetros PDO. Se mantuvieron las validaciones de tamaño, extensión, MIME, nombres aleatorios y rutas seguras de adjuntos. La configuración local no está seguida por Git; la búsqueda de literales de credenciales en archivos de código versionados solo encontró una entrada inválida de prueba. No se añade persistencia de contraseñas en JavaScript.

No se encontraron errores de FK en el flujo. La inspección actual no encontró FK entrantes a fotografia_avance. Se conservan las cuatro rutas antiguas `fotos/` sin archivo físico; su ausencia sigue siendo una limitación de los archivos disponibles, no se borraron sus registros.

Pendientes manuales: aceptación visual con datos representativos y uso cotidiano en los navegadores del equipo; validación en el servidor de despliegue de las protecciones de archivos. Las pruebas automatizadas usaron Chrome y el servidor PHP local. Los archivos antiguos deben recuperarse desde una copia si se desea ver sus imágenes.

El login todavía compara la contraseña con el valor almacenado del prototipo. Migrar a hashes requiere una tarea separada coordinada con las credenciales actuales. El antiguo registro pudo dejar datos en localStorage de navegadores usados anteriormente; no se alteró ese almacenamiento preexistente. No se añadieron operaciones CRUD que los módulos no ofrecían, como edición de pagos o eliminación de facturas.

Recomendación: realizar la aceptación manual del flujo probado y planificar el tratamiento de credenciales antes de continuar con módulos nuevos.

## Archivos modificados

- `api/adquisiciones.php`
- `api/login.php`
- `assets/css/proyectos.css`
- `assets/js/avances.js`
- `assets/js/mantenimiento.js`
- `assets/js/proyectos_comun.js`
- `assets/js/registro.js`
- `registro.html`
- `tests/prueba_conexion.php`
- `tests/pruebas_adquisiciones.php`
- `tests/pruebas_avances.php`
- `tests/pruebas_interfaz_avances.js`
- `docs/revision_general.md` (este informe)

Sin cambios de esquema, dependencias, credenciales originales, Dashboard, semáforo automático ni permisos avanzados. Sin commit ni push.
