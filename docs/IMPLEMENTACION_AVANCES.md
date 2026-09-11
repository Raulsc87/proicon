# Actividades, avance de obra y fotografías

Implementación local verificada el 10 de septiembre de 2026.

## Actualización: edición y eliminación de fotografías

Cada foto ofrece Consultar, Editar y Eliminar. Editar permite cambiar descripción y fecha de carga, con imagen de reemplazo opcional; sin archivo nuevo conserva la ruta. La bitácora asociada no cambia. Se agregaron las acciones POST multipart `foto_editar` y POST JSON `foto_eliminar` a api/avances.php. Ambas verifican sesión y proyecto.

Eliminar requiere confirmar «¿Desea eliminar esta fotografía de avance?». Se elimina sólo la fila de fotografia_avance. Tras confirmar la transacción se borra el archivo exclusivamente si su ruta real está dentro de uploads/avances y ninguna otra fotografía lo referencia. Los archivos legacy y enlaces simbólicos no se borran. Un fallo de limpieza se informa; una FK que impida el DELETE devuelve 409, revierte la operación y conserva el archivo. La inspección actual no encontró FK entrantes a fotografia_avance.

Pruebas de esta actualización: carga PNG, consulta, edición sin reemplazo, reemplazo y consulta de nuevos bytes, rechazo de extensión ejecutable, conservación de archivos compartidos, borrado de archivo exclusivo, borrado de fila legacy temporal faltante y comprobación de bitácora/proyecto/usuario intactos. Chrome verificó Editar, foco, Guardar cambios y cancelación de la confirmación a 1440 y 390 px. Pasaron php -l, node --check y la integración; los originales de 17 tablas permanecieron intactos. Se limpiaron 19 filas temporales (incluidas dos eliminadas durante el ciclo) y tres archivos de imagen (dos eliminados durante reemplazo/eliminación). No se alteró el esquema.

## Estructura real inspeccionada

### actividad

| Columna | Tipo | NULL |
|---|---|---|
| id_actividad | integer, PK autogenerada | No |
| nombre | varchar(150) | No |
| descripcion | text | Sí |
| fecha_inicio | date | Sí |
| fecha_fin_prevista | date | Sí |
| fecha_fin_real | date | Sí |
| porcentaje_avance | numeric(5,2) | No |
| situacion_tiempo | varchar(30) | No |
| estado | varchar(30) | No |
| observaciones | text | Sí |
| id_proyecto | integer, FK proyecto | No |
| id_empleado_responsable | integer, FK empleado | Sí |

SELECT DISTINCT encontró EN_PROCESO y FINALIZADO. situacion_tiempo contiene AMARILLO, ROJO y VERDE. La actividad FINALIZADO existente tiene porcentaje_avance 100.00; las tres EN_PROCESO tienen porcentajes entre 35.00 y 72.00. No se añadieron estados ni relaciones.

### fotografia_avance

| Columna | Tipo | NULL |
|---|---|---|
| id_fotografia | integer, PK autogenerada | No |
| ruta_archivo | varchar(255) | No |
| descripcion | varchar(250) | Sí |
| fecha_carga | date | No |
| id_bitacora | integer, FK bitacora | No |

La foto no tiene proyecto ni usuario propios. Se verificó la relación fotografia_avance → bitacora → proyecto y bitacora.creada_por → usuario.
bitacora tiene id_bitacora autogenerado, fecha, descripcion, observaciones nullable, id_proyecto y creada_por. Cada carga crea una entrada mínima nueva para su fotografía, con fecha de PostgreSQL y usuario de sesión. No modifica bitácoras originales ni incorpora un mantenimiento de bitácora avanzada.

proyecto conserva su PK, FK a cliente y estado_avance. Sus estados actuales son EN_PROCESO y, para estado_avance, AMARILLO/ROJO/VERDE.

## Archivos

Creados:

- api/avances.php
- assets/js/avances.js
- assets/css/avances.css
- tools/inspeccionar_avances.php
- tests/pruebas_avances.php
- tests/pruebas_interfaz_avances.js
- docs/IMPLEMENTACION_AVANCES.md

Modificados:

- proyecto_detalle.html: carga los recursos del módulo.
- scripts/servidor_local.php: protege también las rutas antiguas fotos/ para que las imágenes se consulten a través del endpoint autenticado.

No se modificaron los módulos anteriores, config/database.local.php, estilos globales, tablas, columnas, restricciones ni credenciales. No se hizo commit ni push.

## Funcionalidad y cálculo

La pantalla conserva el proyecto de la URL y muestra datos generales, avance, empleados, presupuestos, actividades, solicitudes y evidencia fotográfica.
La tabla de actividades tiene actividad, responsable, inicio, fin previsto, estado y acciones. Consultar/Editar muestran descripción, observaciones, porcentaje registrado, situación de tiempo y fin real. El responsable es un empleado real y puede quedar sin asignar.

El avance se calcula en PostgreSQL:

`ROUND(100.0 * COUNT(*) FILTER (WHERE estado = 'FINALIZADO') / NULLIF(COUNT(*), 0), 1)`

Cada actividad tiene el mismo peso. Sin actividades se muestra un mensaje y no una barra indeterminada. El porcentaje individual de actividad es un dato separado; no se promedia ni se cambia automáticamente al guardar un estado. El semáforo del proyecto no se modifica. La lista y el resumen usan una instantánea de lectura coherente.

La galería carga datos al desplegarla, con 12 fotos por página y carga diferida de imágenes. Muestra fecha, descripción y usuario de la entrada de bitácora. El visor abre la imagen completa en un diálogo.

Endpoint: api/avances.php?accion=...

| Método | Acciones |
|---|---|
| GET | listar, obtener, fotos_listar, imagen |
| POST JSON | crear, editar, estado |
| POST multipart/form-data | foto_subir |

Todas las acciones validan sesión y pertenencia al proyecto. Consultas preparadas PDO y mensajes de error sin detalles internos. Las actividades no se eliminan; las fotos tienen la eliminación controlada descrita en la actualización.

Las fotos aceptan JPG/JPEG/PNG y WEBP cuando PHP reconoce IMAGETYPE_WEBP; también se exige MIME concordante y getimagesize válido. Límite de 2 MB. Nombres aleatorios de 48 caracteres hexadecimales dentro de uploads/avances/. PostgreSQL guarda sólo la ruta relativa. El archivo y la entrada de bitácora se limpian/revierten ante fallo de persistencia. La consulta de imágenes no acepta rutas del cliente y valida el destino real contra el proyecto, extensión, MIME y directorio.

## Pruebas

Servidor iniciado mediante .\scripts\iniciar_proicon.ps1. Comandos principales:

```powershell
php -l api/avances.php
php -l tools/inspeccionar_avances.php
php -l tests/pruebas_avances.php
php -l scripts/servidor_local.php
node --check assets/js/avances.js
node --check tests/pruebas_interfaz_avances.js
php -d extension=fileinfo tests/pruebas_avances.php --navegador
node tests/pruebas_interfaz_adquisiciones.js
git diff --check
```

| Prueba | Resultado final |
|---|---|
| Sintaxis de los cuatro PHP afectados y dos JS nuevos | Correcta |
| Login real con usuario temporal y sesión | Correcto |
| Las ocho acciones sin sesión | 401 |
| Abrir proyecto y listar actividades reales | Correcto |
| Proyecto sin actividades | Porcentaje NULL; sin división por cero |
| Crear actividad temporal con responsable real | 201 |
| Consultar, editar nombre/porcentaje y quitar responsable | Correcto |
| Estados o fechas inválidos; porcentaje mayor de 100 | 400 |
| Responsable inexistente | 409 |
| Una de dos actividades finalizada | Avance 50% |
| Ambas actividades finalizadas | Avance 100% |
| Volver a EN_PROCESO | Correcto |
| Semáforo existente del proyecto | Sin cambios |
| Edición desde otro proyecto | 404 |
| Imagen ausente, extensión ejecutable, MIME falso, más de 2 MB | 400 |
| Subir PNG temporal y consultar contenido | 201 y 200; bytes originales conservados |
| Nombre original con ../ | Sustituido por nombre aleatorio seguro |
| Foto → bitácora → proyecto y usuario de sesión | Correcto |
| Acceso directo a uploads o imagen desde otro proyecto | 404 |
| Paginación de 13 fotos temporales | 12 y 1, sin duplicados |
| Métodos e identificadores inválidos | 405 y 400 |
| Regresión HTTP de clientes, proveedores, materiales, proyectos y presupuestos | Correcta |
| Regresión de solicitudes, compras, facturas y sus pagos | Correcta |
| Chrome a 1440 y 390 px | Barra 50%, tabla, formularios, consulta, galería diferida y visor correctos |
| Scroll interno y ancho de página | Sin desbordamiento de página |
| Excepciones JavaScript del navegador | Ninguna |
| Regresión visual existente de proyecto y adquisición | Correcta en ambos tamaños |
| Comparación de 17 tablas después de cada ejecución | Datos originales intactos |

La carga positiva automatizada utilizó PNG; JPG/JPEG/WEBP se validan por el código de MIME y formato, pero no se cargaron archivos positivos de esos formatos en esta suite. Los envíos y cambios se probaron por HTTP; el navegador comprobó vistas, formularios y visualización de la imagen persistida.

## Limpieza y problemas encontrados

Hubo cuatro ejecuciones de la integración: una HTTP inicial, dos con ajustes del test de navegador y una final completa. Cada ejecución creó y eliminó 18 registros: un usuario, un proyecto, dos actividades, una bitácora y 13 filas de fotografía. Las 13 fotos compartían un único archivo temporal para probar la paginación.
Total eliminado: 72 registros temporales y cuatro imágenes PNG. También se eliminaron las sesiones y perfiles temporales de navegador. uploads/avances quedó sin archivos. Las secuencias pueden dejar huecos normales; no se reiniciaron.

Los dos fallos intermedios fueron del test visual: seleccionaba un diálogo anterior ya cerrado y esperaba una imagen diferida sin desplazarse hasta ella. Se corrigieron los selectores y el desplazamiento; la suite final pasó sin cambios funcionales para resolverlos.

La base contiene cuatro rutas antiguas fotos/... pero la carpeta fotos no existe en esta copia local. Se conservan las filas originales y la interfaz informa que esas imágenes no están disponibles; no se recrearon ni eliminaron documentos originales.

El servidor y navegador temporales se detuvieron al terminar. Se ejecutaron git status y git diff --check finales. No se implementaron gráficas, PDF, alertas, semáforo automático ni roles avanzados.
