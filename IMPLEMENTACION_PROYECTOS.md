# PROICON: proyectos, asignaciones y presupuestos

Implementación y verificación local: 10 de septiembre de 2026.

## Archivos

Creados:
- api/proyectos_base.php
- api/proyectos_catalogos.php
- api/proyectos_crear.php
- api/proyectos_detalles_crear.php
- api/proyectos_detalles_editar.php
- api/proyectos_editar.php
- api/proyectos_empleados_crear.php
- api/proyectos_empleados_editar.php
- api/proyectos_empleados_listar.php
- api/proyectos_estado.php
- api/proyectos_listar.php
- api/proyectos_obtener.php
- api/proyectos_presupuestos_crear.php
- api/proyectos_presupuestos_listar.php
- api/proyectos_presupuestos_obtener.php
- inspeccionar_proyectos.php
- proyecto_detalle.html
- proyecto_detalle.js
- proyectos.css
- proyectos.html
- proyectos.js
- proyectos_comun.js
- pruebas_proyectos.php
- IMPLEMENTACION_PROYECTOS.md

Modificado: principal.html, para enlazar Proyectos y Presupuestos por proyecto desde el menú y las tarjetas.
No se modificaron el login, los mantenimientos anteriores, estilos.css ni config/database.local.php. No se agregaron dependencias ni se alteró el esquema.

## Funcionalidades

- Proyectos: listar, buscar por código/nombre, crear, consultar, editar y guardar estado sin eliminación física. Cliente seleccionado de PostgreSQL.
- Centro del proyecto en proyecto_detalle.html?id=ID: datos generales, empleados y presupuestos conservando el contexto de la URL.
- Asignaciones: seleccionar empleados existentes, escribir función libre, fecha y estado; consultar y editar. La clave compuesta impide duplicados.
- Presupuestos: listar y crear versiones dentro del proyecto. Autor obtenido de id_usuario en sesión. La creación bloquea la fila del proyecto dentro de una transacción para evitar versiones simultáneas repetidas.
- Detalles: agregar, consultar y editar; categoría real y material opcional. Cantidades positivas y precios no negativos, con dos decimales.
- Subtotales calculados con cantidad * precio_unitario; totales con SUM en PostgreSQL. No se almacenan columnas adicionales. Los importes se formatean sin convertir a Number en el navegador.
- Endpoints con sesión PHP, métodos HTTP definidos, parámetros preparados y errores sin información de conexión. Se valida la pertenencia del presupuesto al proyecto y del detalle al presupuesto.

## Inspección real de PostgreSQL

SELECT DISTINCT devolvió:

| Campo | Valores |
|---|---|
| proyecto.estado | EN_PROCESO |
| proyecto.estado_avance | AMARILLO, ROJO, VERDE |
| proyecto_empleado.estado | ACTIVO |
| presupuesto.estado | APROBADO |

Los selects consultan estos valores dinámicamente. No se añadió INACTIVO ni otro estado inexistente. Por ello todavía no puede desactivarse una asignación ni cambiarse un proyecto a otro estado: hace falta definir los valores permitidos en una tarea posterior autorizada. El guardado de estados existentes está implementado y probado.

empleado contiene id_empleado, nombres, apellidos, telefono, correo y estado; no tiene cargo. La pantalla usa nombres/apellidos y teléfono, además de funcion_en_proyecto.

Relaciones verificadas:

- proyecto.id_cliente → cliente.id_cliente.
- proyecto_empleado → proyecto y empleado; PRIMARY KEY (id_proyecto, id_empleado).
- presupuesto → proyecto y usuario (creado_por).
- detalle_presupuesto → presupuesto, categoria_costo y material; material admite NULL.
- usuario → empleado y rol.
- material → unidad_medida.
- También referencian proyecto: actividad, bitacora, solicitud_material y compra. Se inspeccionaron las relaciones, sin desarrollar esos módulos.

## Pruebas y resultados

| Prueba | Resultado |
|---|---|
| git status antes del desarrollo | Árbol limpio |
| php -l de los 17 PHP nuevos | Correcto |
| node --check de los tres JavaScript nuevos | Correcto tras corregir un paréntesis durante desarrollo |
| Acceso sin sesión a los 14 endpoints | 401 en todos |
| Carga de clientes, empleados, categorías, materiales y estados | Correcto, datos reales |
| Listado y consulta de proyecto original con cliente | Correcto |
| Crear proyecto temporal y buscar por código/nombre | Correcto |
| Editar proyecto y comprobar cliente persistido | Correcto |
| Editar los tres valores reales de estado_avance | Correcto |
| Guardar estado existente sin eliminar proyecto | Correcto |
| Asignar empleado, consultar y editar función/estado | Correcto |
| Asignación duplicada | 409 |
| Crear presupuesto con autor falsificado en el cuerpo | Se utilizó el usuario de la sesión |
| Crear segunda versión / repetir versión | 201 / 409 |
| Agregar detalle con material y otro sin material | Correcto; material opcional persistido como NULL |
| Subtotal 450 × 82.50 | 37125.00 |
| Total con mano de obra 95000.00 | 132125.00 |
| Editar cantidad a 2 y volver a listar presupuesto | Total recalculado 95165.00 |
| Consultar/editar presupuesto desde otro proyecto | 404 |
| Fecha inválida, nombre largo, estado inventado | 400 |
| Cantidad negativa y precio con exceso de decimales | 400 |
| Material inexistente | 409 |
| Identificador inválido / proyecto inexistente | 400 / 404 |
| Método incorrecto | 405 |
| Ambas páginas servidas mediante HTTP | Correcto |
| Pruebas existentes de Clientes | Todas correctas; registro temporal eliminado |
| Pruebas existentes de Proveedores | Todas correctas; registro temporal eliminado |
| Pruebas existentes de Materiales | Todas correctas; registro temporal eliminado |
| Comparación de registros antes/después | Sin cambios en originales de las nueve tablas verificadas |

Se utilizó php -S localhost:8000. El servidor temporal se detuvo al finalizar.
No se ejecutó una prueba visual interactiva en navegador; se verificaron HTTP, lógica de integración y sintaxis.

## Datos temporales y limpieza

La prueba principal creó un proyecto (ID 5), una asignación, dos presupuestos y dos detalles. Eliminó los seis registros, en orden detalle → presupuesto → asignación → proyecto, utilizando el código aleatorio de la ejecución.
Las pruebas de regresión crearon y eliminaron un cliente, un proveedor y un material adicionales: nueve registros temporales en total, todos eliminados.
Las sesiones temporales también se destruyeron.
Se compararon proyecto, proyecto_empleado, presupuesto, detalle_presupuesto, cliente, proveedor, material, empleado y categoria_costo con sus datos anteriores. Las secuencias autogeneradas pueden dejar huecos normales tras estas inserciones; no se reiniciaron.

## Problemas y siguiente paso

El entorno restringido bloqueó php.exe; se ejecutó con el permiso correspondiente. Se corrigió el error sintáctico JavaScript detectado antes de probar.
La limitación funcional pendiente es la ausencia de estados alternativos en las tablas reales. No se inventaron valores.
Se recomienda definir primero los estados permitidos y después desarrollar actividades por proyecto reutilizando este contexto, previa inspección de su tabla.
No se hicieron commits ni push.
