# Gestión de empleados de PROICON

## Implementación y archivos

Nuevos: `empleados.html`, `assets/js/empleados.js`, `assets/css/empleados.css`, `api/empleados_comun.php`, `api/empleados_pagina.php`, los cinco endpoints siguientes, `tests/pruebas_empleados.php`, `tests/pruebas_interfaz_empleados.js` y este informe.

Modificados: `assets/js/principal.js` (menú y autorización de la vista), `assets/js/proyecto_detalle.js` (filtro visual del selector), `scripts/servidor_local.php` y `.htaccess` (protección PHP de la página).

| Método | Endpoint | Función |
| --- | --- | --- |
| GET | `api/empleados_listar.php?buscar=` | Listado y búsqueda por nombres, apellidos o correo |
| GET | `api/empleados_obtener.php?id=` | Consulta individual |
| POST | `api/empleados_crear.php` | Crear persona, sin crear cuenta |
| POST | `api/empleados_editar.php` | Actualizar datos básicos y estado |
| POST | `api/empleados_estado.php` | ACTIVO / INACTIVO |

Se utiliza PDO preparado, validación de nombres y apellidos obligatorios, correo opcional válido y longitudes reales: nombres/apellidos 80, teléfono 20 y correo 120 caracteres. Se normalizan espacios. No hay eliminación física en el módulo. Inactivar requiere confirmación tanto en el botón como al editar el estado.

## Permisos inspeccionados y política compatible

El m?dulo utiliza los permisos espec?ficos existentes en PostgreSQL: `VER_EMPLEADOS` para p?gina, men?, listado y consulta; `GESTIONAR_EMPLEADOS` para crear, editar y cambiar estado. Los endpoints llaman a `requerir_permiso()`. La interfaz consulta `Autorizacion.puede()` y la capacidad devuelta por el servidor.

Se elimin? la regla temporal basada en nombres de roles. La autorizaci?n depende exclusivamente de los permisos; no se modifica su configuraci?n ni se reutilizan permisos de asignaci?n a proyectos.

Distribuci?n verificada por SELECT: ADMINISTRADOR y SECRETARIA tienen ambos permisos; ENCARGADO_OBRA tiene s?lo VER_EMPLEADOS. El resto no tiene acceso salvo que se le configure el permiso correspondiente mediante la administraci?n de permisos existente.

La URL HTML pasa por PHP, igual que Usuarios. Se verificó con el servidor local. Apache requiere mod_rewrite/AllowOverride para la regla `.htaccess`; no se ejecutó Apache. Los endpoints sin sesión devuelven 401; la página redirige al login. Con sesión y sin permiso, página y endpoints devuelven 403.

## Relaciones y flujos

Nuevo empleado → Nuevo usuario: el empleado sin cuenta aparece en el selector existente de Administración de Usuarios. Sólo la creación explícita de una cuenta crea un usuario; se conserva el rechazo a una segunda cuenta para el mismo empleado.

Empleado → Proyecto: el selector de nuevas asignaciones ofrece empleados ACTIVO. Los empleados INACTIVO que ya estaban asignados se conservan como opciones históricas deshabilitadas para nuevas asignaciones, permitiendo editar su asignación existente. El filtro es visual; los endpoints, tablas y lógica de asignación no se modificaron.

El estado laboral de empleado, el estado de usuario y el estado de proyecto_empleado son independientes. Inactivar una persona no cambia su cuenta, contraseña ni asignaciones.

## Pruebas

- ADMINISTRADOR: consulta, búsqueda, creación temporal, edición y reactivación.
- SECRETARIA: acceso, autorización del endpoint de creación (validación de entrada), edición e inactivación/reactivación del empleado temporal.
- ENCARGADO_OBRA: consulta permitida y 403 en crear, editar y cambiar estado.
- Empleado temporal disponible en Nuevo usuario y en catálogo de proyecto cuando ACTIVO. Creación explícita de su cuenta; segundo usuario rechazado. No se creó ninguna asignación de prueba ni se modificó proyecto_empleado.
- Cuenta temporal COMPRAS: login real; 403 en página de empleados y cinco endpoints. Inactivar al empleado conserva exactamente su cuenta y permite login si usuario.estado sigue ACTIVO. Logout y sesión finalizada verificados.
- Comparación de todas las tablas al terminar: originales intactos y datos temporales eliminados. Las secuencias pueden avanzar por inserciones temporales; no se retroceden.
- Navegador: ADMINISTRADOR, SECRETARIA y ENCARGADO_OBRA a 1440 y 390 px; consulta, permisos visuales, formularios, foco, desplazamiento suave y Cancelar. Sin desbordamiento horizontal de página ni excepciones JavaScript.
- Selector de proyectos: todas las opciones disponibles para nueva asignación corresponden a empleados ACTIVO.
- Regresión de `pruebas_interfaz_usuarios.js` y `pruebas_interfaz_adquisiciones.js --edicion`: correctas, incluidos Usuarios, proyectos, edición de asignaciones y formularios de adquisiciones a 1440/390 px.
- `php -l`: 79 archivos correctos; `node --check`: 23 archivos correctos; `git diff --check`: correcto.

Para repetir: `php tests/pruebas_empleados.php` y `node tests/pruebas_interfaz_empleados.js`, con el servidor local iniciado mediante `scripts/iniciar_proicon.ps1`.

## Problemas encontrados

Faltaban permisos específicos del personal general; se resolvió mediante la política explícita anterior sin cambios en PostgreSQL. El catálogo de proyectos incluía empleados de cualquier estado; se ajustó únicamente la presentación de opciones disponibles. No se detectaron regresiones en las pruebas ejecutadas. No se cambió el esquema, las cuentas originales, las contraseñas ni los roles. Sin commit ni push.La pol?tica temporal por roles fue reemplazada por los dos permisos espec?ficos. Se verificaron las lecturas (200) y las operaciones de gesti?n con cuerpos deliberadamente inv?lidos: ADMINISTRADOR/SECRETARIA llegan a validaci?n (400), mientras ENCARGADO_OBRA recibe 403. No se enviaron operaciones v?lidas de escritura durante este ajuste. La comparaci?n de todas las tablas antes/despu?s confirma datos intactos. Tambi?n pas? la interfaz con los tres roles en 1440 y 390 px y las validaciones PHP, JavaScript y git diff --check.

Las pruebas funcionales con datos temporales descritas arriba corresponden a la implementaci?n inicial; no se repitieron durante el ajuste de autorizaci?n. Sin cambios de esquema, roles, datos ni otros m?dulos. Sin commit ni push.
