# Rediseño visual corporativo de PROICON

Se aplicó la identidad negra, gris y dorada de PROICON manteniendo las áreas de trabajo claras. No se modificaron endpoints, lógica de autorización, cálculos, CRUD, archivos de uploads ni datos de PostgreSQL. No se hizo commit ni push.

## Archivos y páginas

- Nuevo `assets/css/corporativo.css`: variables de color y estilos compartidos. Se carga después de las hojas existentes, que se conservaron completas.
- Nuevo `assets/js/presentacion.js`: clases visuales para botones y badges; conserva textos, valores y eventos.
- `assets/js/principal.js`: muestra el rol que ya devuelve la sesión, además del nombre existente.
- HTML actualizados: `index.html`, `registro.html`, `principal.html`, `clientes.html`, `proveedores.html`, `materiales.html`, `proyectos.html`, `proyecto_detalle.html`, `presupuestos_resumen.html`, `empleados_proyectos.html`, `avance_proyectos.html`, `solicitud_detalle.html`, `compra_detalle.html` y `factura_detalle.html`.
- Nuevos `tests/pruebas_diseno.php` y `tests/pruebas_interfaz_diseno.js`: pruebas visuales automatizadas con navegador local, sin dependencias adicionales.
- Este informe: `docs/redisenio_visual.md`.

## Presentación

Sidebar oscuro con usuario y rol reales, sección activa dorada y cierre de sesión rojo. En móvil se adapta a un encabezado con navegación flexible. Login corporativo y registro público todavía deshabilitado. Tarjetas claras con borde superior dorado y rutas originales. Botones, formularios, tablas, modales, badges, galerías y barras de progreso comparten estilos; las tablas mantienen desplazamiento horizontal interno.

Se utiliza `assets/img/logo-proicon.jpeg`, proporcionado previamente por el usuario, sin alterar el archivo. Las imágenes conservan proporción y `object-fit: contain`; el contenedor aprovecha el espacio vacío negro del original para integrar la marca. Se corrigió la altura del contenedor del login para mostrar completo el texto inferior del logo.

## Verificación

Servidor local iniciado mediante `scripts/iniciar_proicon.ps1`, disponible en `http://localhost:8000`.

- Chrome: 14 páginas con ADMINISTRADOR y SECRETARIA en 1440, 1024, 768 y 390 px: 112 combinaciones correctas, logo cargado y sin desbordamiento horizontal de la página.
- Revisión de capturas del login, panel y proyectos en escritorio y móvil.
- Las seis tarjetas del panel conservan su navegación mediante clic.
- Botones de proveedor visibles para ADMINISTRADOR y ocultos para SECRETARIA; factura restringida muestra acceso no autorizado para SECRETARIA. Nombre y rol corresponden a cada sesión.
- Logout, redirección al login sin sesión, mensaje de credenciales inválidas y registro deshabilitado correctos.
- Edición conserva desplazamiento, foco y Cancelar en empleados asignados, presupuestos, actividades, clientes, proveedores, materiales y proyectos. Formularios del flujo de adquisiciones comprobados en 1440 y 390 px.
- Se conserva el aviso de fotografía faltante, sin imagen rota.
- Sin excepciones JavaScript en el navegador.
- `php -l`: 60 archivos correctos. `node --check`: 19 archivos correctos. `git diff --check`: correcto.
- Comparación de huellas de todas las tablas antes y después: PostgreSQL intacto. Hashes de contraseñas reconocidos; no se cambiaron contraseñas ni permisos.

Las pruebas usan sesiones efímeras de usuarios existentes y transacciones PostgreSQL de solo lectura. Se eliminan las sesiones de prueba y perfiles temporales del navegador. No se crean registros. El ingreso válido con contraseña no se volvió a probar porque no se proporcionaron credenciales; se verificó el acceso con sesiones de ambos roles y el rechazo de credenciales inválidas. No se guardaron operaciones CRUD durante esta revisión visual.

## Problemas encontrados

El logo original tiene bastante espacio negro, que se ajustó visualmente sin editar la imagen. No se detectaron regresiones en las comprobaciones ejecutadas. Las fotografías antiguas sin archivo conservan su mensaje existente; no se alteraron sus registros.

Para repetir: `node tests/pruebas_interfaz_diseno.js` y `node tests/pruebas_interfaz_adquisiciones.js --edicion`, con PHP, Chrome o Edge y el servidor local disponibles.
