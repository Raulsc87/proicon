# Administración de Usuarios

Implementada en `usuarios.html`, con el diseño corporativo existente. El acceso Usuarios aparece en el menú lateral sólo con `VER_USUARIOS`. Crear, editar, activar/desactivar y cambiar contraseña requieren además `GESTIONAR_USUARIOS`, comprobado en PHP.

## Archivos

Nuevos:

- `usuarios.html`, `assets/js/usuarios.js`, `assets/css/usuarios.css` (legibilidad de la tabla y consulta, sin cambiar estilos globales).
- `api/usuarios_comun.php`: validaciones, consultas preparadas y transacciones del módulo.
- `api/usuarios_pagina.php`: autorización de la página y respuesta 403.
- Ocho endpoints enumerados debajo.
- `.htaccess`: en Apache dirige `usuarios.html` al controlador PHP.
- `tests/pruebas_usuarios.php`, `tests/pruebas_interfaz_usuarios.js`.
- `docs/administracion_usuarios.md` (este informe).

Modificados:

- `assets/js/principal.js`: registro del módulo USUARIOS y enlace de menú condicionado al permiso.
- `scripts/servidor_local.php`: protección PHP de la página en el servidor local, incluidas variantes de mayúsculas en Windows.

No se modificó el esquema, los permisos, contraseñas originales ni lógica de otros módulos. No hubo commit ni push.

## Endpoints

| Método | Ruta | Uso |
| --- | --- | --- |
| GET | `api/usuarios_listar.php?buscar=` | Buscar por usuario o nombre completo del empleado |
| GET | `api/usuarios_obtener.php?id=` | Consultar sin contraseña ni hash |
| GET | `api/usuarios_empleados.php` | Empleados existentes sin cuenta |
| GET | `api/usuarios_roles.php` | Cinco roles vigentes |
| POST | `api/usuarios_crear.php` | Crear con contraseña inicial y confirmación |
| POST | `api/usuarios_editar.php` | Cambiar usuario, rol y estado |
| POST | `api/usuarios_estado.php` | Activar/desactivar |
| POST | `api/usuarios_contrasena.php` | Cambiar contraseña y confirmación |

Los POST aceptan JSON. La consulta/edición nunca devuelve el hash. La creación y cambio de contraseña usan `password_hash(..., PASSWORD_DEFAULT)`; la edición conserva el hash. La contraseña admite entre 8 y 72 bytes, evitando truncamiento de bcrypt. Se mantiene el empleado original de la cuenta. INGENIERO y ASISTENTE no se ofrecen para cuentas nuevas; una cuenta antigua puede conservar su rol actual.

## Protecciones

La inspección de PostgreSQL confirmó que no existen restricciones UNIQUE para nombre de usuario o empleado. Sin cambiar el esquema, las escrituras del módulo se serializan mediante `LOCK TABLE usuario IN SHARE ROW EXCLUSIVE MODE`, dentro de una transacción. Así la comprobación de duplicados y del último administrador también es válida frente a peticiones simultáneas del módulo. Los nombres se comparan sin distinguir mayúsculas.

Se rechaza desactivar la cuenta conectada y cambiar el rol o desactivar al último ADMINISTRADOR ACTIVO. No existe endpoint de eliminación física. Los errores internos de PostgreSQL no se envían al cliente. Se conserva el rechazo existente de login y sesiones para cuentas INACTIVAS.

La página necesita pasar por PHP para emitir HTTP 403. Se verificó usando `scripts/iniciar_proicon.ps1`; Apache dispone de la regla `.htaccess`, que requiere mod_rewrite y AllowOverride habilitados. Apache no se ejecutó en esta prueba. No se debe servir esta página desde un servidor puramente estático.

## Pruebas ejecutadas

`php tests/pruebas_usuarios.php`:

- ADMINISTRADOR: listado, consulta, búsqueda y roles disponibles.
- Empleado y cuenta temporales: creación, login real, permisos SECRETARIA, edición, cambio de contraseña, desactivación y reactivación.
- Contraseña guardada diferente del texto original y verificada mediante `password_verify`; edición conserva exactamente el hash. Nueva contraseña funciona y la anterior falla.
- Usuario INACTIVO recibe 403 al iniciar sesión; su sesión anterior pierde acceso a endpoints (401).
- Rechazo de nombre duplicado, segundo usuario del mismo empleado, confirmación incorrecta y cambio manual del empleado.
- Autodesactivación rechazada; cambio de rol del único administrador original rechazado sin modificarlo.
- SECRETARIA recibe 403 en la página y los ocho endpoints; sin sesión, endpoints devuelven 401.
- Limpieza limitada al empleado y usuario creados por la prueba. Comparación de huellas de todas las tablas: registros originales intactos, sin datos temporales restantes. Las secuencias pueden avanzar por los INSERT de prueba; no se retroceden para evitar reutilización de identificadores.

`node tests/pruebas_interfaz_usuarios.js --capturas`:

- 1440 y 390 px sin desbordamiento horizontal de página.
- Menú activo, consulta, Nuevo, Editar, cambio de contraseña, enfoque, desplazamiento suave y Cancelar.
- SECRETARIA sin enlace Usuarios y con rechazo 403 por URL directa, incluida la variante en mayúsculas.
- Sin excepciones JavaScript; datos intactos. Sesiones y perfiles de navegador temporales eliminados.

También pasó `node tests/pruebas_interfaz_adquisiciones.js --edicion`: edición/Cancelar de mantenimientos existentes y formularios del flujo de adquisiciones en 1440 y 390 px, sin excepciones JavaScript.

Validaciones: `php -l` en 71 archivos, `node --check` en 21 archivos y `git diff --check`.

## Problemas encontrados

La ausencia de restricciones únicas se resolvió en las transacciones del módulo. Las páginas HTML existentes eran estáticas; para cumplir el 403 real de Usuarios se agregó autorización a su ruta. No se detectaron fallos funcionales en las comprobaciones del módulo. No se modificaron usuarios originales durante las pruebas exitosas.
