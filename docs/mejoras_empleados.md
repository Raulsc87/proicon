# Filtro de empleados y función en proyectos

Archivos modificados: `empleados.html`, `assets/js/empleados.js`, `assets/css/empleados.css`, `api/empleados_comun.php`, `proyecto_detalle.html` y `assets/js/proyecto_detalle.js`.

Archivos creados: `tests/pruebas_mejoras_empleados.js` y este informe.

## Comportamiento

- La pantalla inicia en ACTIVOS; INACTIVOS y TODOS actualizan el listado conservando la búsqueda. El endpoint combina ambos filtros con parámetros preparados. Si una llamada antigua omite estado, conserva el listado completo.
- «Dar de baja» reemplaza sólo el texto de «Desactivar», mantiene la confirmación y envía INACTIVO. Reactivar envía ACTIVO. Al salir del filtro actual, la fila desaparece del listado sin borrar al empleado.
- La función de una asignación usa las ocho opciones solicitadas y una opción inicial vacía. «Otro» muestra un texto obligatorio de hasta 100 caracteres; se envía su contenido en `funcion_en_proyecto`, nunca el marcador «Otro».
- Editar una función estándar selecciona su opción; una función antigua/personalizada selecciona Otro y conserva el texto. Al abrir otra asignación se restablecen los campos correspondientes.
- Nueva asignación sólo muestra empleados globalmente ACTIVO. Al editar se añade la persona histórica seleccionada, aunque sea INACTIVO, sin alterar su historial ni cambiar su identidad.

## Pruebas

Se ejecutó `node tests/pruebas_mejoras_empleados.js`: filtros contra el endpoint real mediante SELECT, estado inválido rechazado y búsqueda combinada correcta. En navegador a 1440 y 390 px se comprobaron los tres filtros, baja/reactivación, opciones disponibles, historial, Albañil, Ingeniero encargado, Otro obligatorio, Bodeguero y recuperación de funciones estándar/personalizadas al editar.

Las operaciones de escritura se simularon en memoria del navegador y se comprobaron sus JSON; no se probó persistencia real mediante INSERT/UPDATE. El código PHP de guardado de asignaciones no se modificó. La prueba bloquea POST reales y compara las huellas de todas las tablas antes/después: PostgreSQL intacto.

También se ejecutaron las regresiones de interfaz de empleados (tres roles, permisos y edición) y adquisiciones con `--edicion`. Validaciones: `php -l`, `node --check`, `git diff --check`. Sin excepciones JavaScript ni desbordamiento horizontal de la página en los tamaños probados.

Sin cambios en esquema, tablas, columnas, relaciones, roles, permisos, usuarios ni registros originales. Sin commit ni push.
