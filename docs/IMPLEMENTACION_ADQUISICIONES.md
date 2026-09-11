# PROICON: adquisición de materiales

Implementado y verificado localmente el 10 de septiembre de 2026.

## Uso local

En PowerShell ejecutar `./scripts/iniciar_proicon.ps1` y abrir `http://localhost:8000`.
El lanzador habilita la extensión nativa fileinfo, si está desactivada, y usa el router que impide descargar adjuntos sin pasar por el endpoint autenticado.
Comando equivalente en esta instalación: `php -d extension=fileinfo -S localhost:8000 scripts/servidor_local.php`.
No se instaló ninguna dependencia ni se modificó la configuración de conexión.
En Apache, uploads/.htaccess deniega el acceso directo; el servidor debe respetar esa regla. En otros servidores debe configurarse la misma restricción. No usar el servidor PHP sin el router para servir adjuntos privados.

## Archivos creados

- api/adquisiciones.php
- assets/js/adquisiciones.js
- assets/css/adquisiciones.css
- solicitud_detalle.html
- compra_detalle.html
- factura_detalle.html
- uploads/.htaccess
- scripts/servidor_local.php
- scripts/iniciar_proicon.ps1
- tools/inspeccionar_adquisiciones.php
- tests/pruebas_adquisiciones.php
- tests/pruebas_interfaz_adquisiciones.php
- tests/pruebas_interfaz_adquisiciones.js
- docs/IMPLEMENTACION_ADQUISICIONES.md

Modificados: proyecto_detalle.html (sección de solicitudes y recursos del módulo) y .gitignore (uploads ignorados, conservando la protección de database.local.php).
No se modificaron los endpoints anteriores, cálculos de presupuesto, login, estilos globales ni esquema. No se hicieron commits ni push.

## Flujo implementado

1. Desde el proyecto se listan y crean solicitudes con autor tomado de sesión, actividad NULL y revisión inicialmente NULL.
2. La solicitud muestra materiales reales, cantidades y consulta de observaciones; permite agregar, editar y quitar antes de generar compras. Evita duplicados. Los cambios invalidan la revisión anterior.
3. Revisar una solicitud con materiales registra al usuario de sesión. No se agregan roles.
4. Una solicitud revisada y APROBADA permite generar varias compras para distintos proveedores, conservando proyecto y solicitud.
5. La compra permite seleccionar materiales de la solicitud, propone la cantidad aún disponible y solicita precio. Impide duplicados y cantidades acumuladas superiores a lo solicitado. No modifica detalle_solicitud.
6. Subtotales y total de compra se calculan en PostgreSQL mediante multiplicación y SUM. La recepción se muestra y se puede confirmar con los estados existentes.
7. Se registran facturas con autor de sesión y adjunto opcional. Una diferencia frente al total de compra genera aviso visual y no bloquea el registro. Una compra facturada conserva sus materiales e importes.
8. La factura muestra varios pagos, total pagado y saldo calculados mediante SUM. Un saldo no positivo muestra PAGADA sin cambiar el estado persistente. Los tipos tienen sugerencias reales y aceptan texto libre, permitido por el VARCHAR sin CHECK/ENUM. Se evita repetir tipo y número de operación dentro de la misma factura.

Las observaciones se conservan en detalle o consulta. Importes con Q inseparable del número, tablas con scroll interno y formularios en diálogos nativos. La navegación conserva el proyecto en la URL.

## Endpoint y operaciones

Todas las operaciones usan `api/adquisiciones.php?accion=...` y exigen sesión. Las lecturas reciben id_proyecto por URL; las escrituras por el cuerpo. Se valida la pertenencia de cada solicitud, compra, factura y pago al proyecto.

| Método | Acciones |
|---|---|
| GET | catalogos, solicitudes_listar, solicitudes_obtener, compras_obtener, facturas_obtener, archivo |
| POST JSON | solicitudes_crear, solicitudes_revisar, solicitud_detalle_guardar, solicitud_detalle_quitar, compras_crear, compra_detalle_guardar, compras_estado |
| POST multipart/form-data | facturas_crear, pagos_crear |

Los autores se toman de sesión aunque el cliente envíe otros valores. Las escrituras se serializan por proyecto en una transacción para proteger revisión, duplicados y cantidades concurrentes. Los errores no revelan detalles de PostgreSQL.

## Archivos adjuntos

- PDF, JPG, JPEG y PNG, con extensión y MIME comprobados mediante fileinfo; las imágenes también se validan con getimagesize.
- Máximo de aplicación: 2 MB. Los límites del servidor PHP también aplican.
- Nombres aleatorios de 48 caracteres hexadecimales, sin reutilizar el nombre original.
- Se crea uploads/facturas o uploads/pagos únicamente cuando es necesario. PostgreSQL guarda sólo la ruta relativa.
- El endpoint de descarga obtiene la ruta desde la fila, comprueba el contexto y la ruta real, y sirve una descarga con nosniff. No acepta una ruta arbitraria del cliente.
- Soporta las rutas antiguas facturas/... y pagos/... si los archivos existen; devuelve 404 si no están disponibles.
- Las cargas nuevas se limpian si falla la escritura posterior en la base.

## Estados y relaciones confirmados

| Campo | SELECT DISTINCT |
|---|---|
| solicitud_material.estado | APROBADA |
| compra.estado | RECIBIDA |
| factura.estado | REGISTRADA |
| pago.tipo_pago | TRANSFERENCIA |

No se inventaron estados. Por ello una solicitud nueva queda APROBADA pero sin revisor, y todavía no puede generar compras hasta revisarse. Una compra nueva queda RECIBIDA porque no existe un estado previo permitido. La confirmación de recepción conserva ese valor; no constituye una transición desde un estado pendiente inexistente.

PK simples autogeneradas en las seis tablas. FK verificadas:

- solicitud_material → proyecto, actividad, usuario solicitante y usuario revisor. id_actividad, revisada_por, fecha_necesaria y observaciones admiten NULL.
- detalle_solicitud → solicitud_material y material. Cantidad numeric(12,2), sin precio.
- compra → proyecto, proveedor, solicitud_material y usuario creador. id_solicitud admite NULL.
- detalle_compra → compra y material. Cantidad numeric(12,2), precio_unitario numeric(14,2).
- factura → compra y usuario registrador. monto_total numeric(14,2), ruta_archivo nullable.
- pago → factura y usuario registrador. monto numeric(14,2); número de operación, comprobante y observaciones nullable.

No hay UNIQUE en compra.id_solicitud, factura.id_compra ni pago.id_factura: se admiten múltiples compras, facturas y pagos. No hay CHECK/ENUM para estados ni tipo_pago. No se cambió ninguna restricción.

## Pruebas aprobadas

| Prueba | Resultado |
|---|---|
| Inspección directa, PK/FK/nulabilidad/precisión y estados | Correcta |
| php -l en los cinco PHP nuevos | Correcto |
| node --check en ambos JavaScript nuevos | Correcto |
| Las 15 acciones sin sesión | 401 |
| Login con usuario temporal, contraseña incorrecta, sesión y logout | Correctos |
| Listar solicitudes y consultar SOL-001 con materiales | Correcto |
| Crear solicitud temporal y comprobar autor/NULL opcionales | Correcto |
| Rechazar estado inventado y fecha inválida | 400 |
| Revisar solicitud vacía o comprar sin revisión | 409 |
| Agregar, editar y quitar material antes de compras | Correcto |
| Material duplicado en solicitud | 409 |
| Cambiar material tras revisión | Revisor vuelve a NULL |
| Registrar revisión | Usuario de sesión |
| Proveedor inexistente | 409 |
| Primera y segunda compra de una solicitud | Correcto |
| Editar/quitar materiales de solicitud con compras | 409 |
| Copiar materiales con precio y editar antes de facturar | Correcto |
| Duplicados, exceso de cantidad y exceso acumulado entre compras | 409 |
| Cantidad negativa | 400 |
| 180 × 81.75 | Subtotal 14715.00 |
| Más 800 × 7.10 | Total compra 20395.00 |
| Confirmar RECIBIDA | Correcto |
| Archivo con extensión ejecutable, MIME falso o más de 2 MB | 400 |
| Factura 20400.00 con total de compra 20395.00 y PDF real | Registrada sin bloquear diferencia |
| Autor de factura enviado por el cliente | Se utiliza sesión |
| Nombre original con ../ | Sustituido por nombre seguro aleatorio |
| Factura duplicada | 409 |
| Editar compra facturada | 409 |
| Descarga autenticada de factura y comprobante | Contenido idéntico al cargado |
| Acceso estático a uploads con router | 404 |
| Pago parcial con PNG | Total pagado 10000.00, saldo 10400.00 |
| Repetir operación de pago | 409 |
| Segundo pago tipo Cheque con PDF | Total pagado 20400.00, saldo 0.00 |
| Estado después de pago completo | Sigue REGISTRADA |
| Solicitud, compra, factura y archivo desde otro proyecto | 404 |
| Método incorrecto | 405 |
| Regresión de Proyectos, Empleados asignados y Presupuestos | Todas las pruebas existentes correctas |
| Regresión de Clientes, Proveedores y Materiales | Todas las pruebas existentes correctas |
| Chrome sin dependencias: cuatro vistas a 1440 y 390 px | Carga, diálogo y scroll interno correctos; sin desbordamiento de página |
| Consulta de observaciones y aviso por diferencia de factura | Correctos en navegador |
| Excepciones JavaScript del navegador | Ninguna |
| Comparación de 14 tablas antes/después de adquisición | Originales intactos |

Pruebas fallidas durante el desarrollo: la primera ejecución de integración detectó fileinfo desactivada antes de crear datos. Se corrigió el arranque habilitando la extensión nativa ya instalada. Una comprobación auxiliar con php -r falló por comillas de PowerShell; se reemplazó por php --ri y la comprobación dentro de la prueba. No quedan fallos en las suites finales.

No se automatizaron envíos de formularios desde Chrome: las escrituras, cargas y cálculos se probaron por HTTP; el navegador comprobó vistas, diálogos, aviso y dimensiones.

## Limpieza y cierre

La integración creó 12 registros en total: un usuario temporal, una solicitud, tres detalles de solicitud (uno quitado durante la prueba), dos compras, dos detalles de compra, una factura y dos pagos. Todos fueron eliminados respetando las FK.
Las regresiones crearon y eliminaron seis registros del flujo proyecto/presupuesto y un cliente, un proveedor y un material: nueve adicionales. Total: 21 registros temporales eliminados.
Se eliminaron los tres adjuntos persistidos de prueba (dos PDF y un PNG), las sesiones temporales y el perfil temporal de Chrome. uploads conserva únicamente .htaccess como archivo; las subcarpetas vacías están disponibles para uso futuro.
Las secuencias pueden dejar huecos normales tras los INSERT temporales; no se reiniciaron. No se modificaron datos originales.
El servidor PHP temporal y el navegador de prueba se detuvieron. git status y git diff --check se ejecutaron al cierre.

## Limitaciones detectadas y siguiente paso

- Faltan estados previos reales para distinguir solicitud pendiente y compra por recibir. Definir ese catálogo y sus transiciones es el siguiente paso recomendado, antes de roles avanzados.
- Las FK de compra a proyecto y solicitud son independientes: no garantizan por sí solas que ambos correspondan al mismo proyecto. La API comprueba el contexto.
- La base no impide materiales repetidos ni operaciones de pago repetidas; esta API aplica esas validaciones sin cambiar el esquema.
- La revisión no tiene fecha ni historial en el esquema actual; sólo puede conservar el revisor.
- El login existente compara la contraseña directamente con el valor almacenado. Se conservó intacto por alcance; conviene abordar su migración a hashes en una tarea específica.
- Los archivos antiguos sólo pueden descargarse si están presentes físicamente en el servidor; no se recrearon documentos originales.
