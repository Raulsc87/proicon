# Contraseñas seguras — PROICON

Migración local realizada el 7 de octubre de 2026.

## Almacenamiento y login

Las contraseñas se almacenan exclusivamente como resultado de `password_hash($clave, PASSWORD_DEFAULT)` en `usuario.contrasena VARCHAR(255)`. Nunca almacenar claves en texto plano ni registrar claves o hashes completos en logs, interfaz, argumentos de procesos o documentación.

`api/login.php` busca mediante PDO preparado únicamente por `nombre_usuario`. Verifica la contraseña con `password_verify()`, exige usuario ACTIVO y conserva la regeneración de sesión. Contraseña incorrecta y usuario inexistente reciben HTTP 401 con el mismo mensaje: “Usuario o contraseña incorrectos.” No existe una alternativa de acceso con texto plano ni con el hash almacenado como contraseña.

No existe actualmente una funcionalidad operativa para crear/cambiar contraseñas de usuarios. El registro antiguo sigue deshabilitado. Las dos suites que crean usuarios temporales se actualizaron para almacenar hashes; no se ejecutaron ni crearon usuarios en esta tarea. La suite de autorización usa sesiones de prueba sin leer las contraseñas almacenadas.

## Migración reproducible

```powershell
php tools/migrar_contrasenas.php
```

La utilidad sólo funciona por CLI; por HTTP devuelve 404 sin ejecutar cambios. Usa la conexión PDO existente y una transacción que:

1. Ejecuta `sql/migraciones/001_contrasenas_hash.sql`:

   ```sql
   ALTER TABLE public.usuario
   ALTER COLUMN contrasena TYPE VARCHAR(255);
   ```

2. Lee y bloquea los usuarios existentes.
3. Detecta hashes mediante `password_get_info()` y conserva los ya reconocidos.
4. Convierte únicamente las claves en texto plano usando `password_hash()` y actualizaciones preparadas por identificador.
5. Confirma los cambios y muestra sólo cantidades. Si hay una excepción, revierte tanto las actualizaciones como el cambio de columna, sin exponer detalles internos.

La comprobación de longitud impide que bcrypt trunque silenciosamente una clave anterior de más de 72 bytes. Ninguna de las tres claves locales excedía ese límite.

Para reproducirlo en la nube, ejecutar la utilidad CLI con la configuración PDO del destino durante el despliegue coordinado con el login nuevo. El archivo SQL también documenta el único cambio de esquema necesario. No modificar ninguna otra columna, los usuarios, sus roles ni los permisos. No copiar las claves originales a archivos auxiliares.

## Resultado local

- Primera ejecución: usuarios revisados **3**, contraseñas migradas **3**.
- Segunda ejecución: usuarios revisados **3**, contraseñas migradas **0**. Los hashes permanecieron exactamente iguales.
- `pxicara`, `cserrano` y `amorales`: hashes reconocidos y `password_verify()` correcto con las claves originales conservadas sólo en memoria durante la migración.
- Login HTTP de `pxicara`: correcto con su contraseña original.
- Pendiente de completar: login HTTP de `cserrano` y `amorales` mediante entrada local oculta. La primera prueba se detuvo después del login de pxicara por una expectativa obsoleta sobre su permiso de gestionar proveedores; se corrigió para consultar los permisos reales. Al terminar el proceso se descartaron las claves originales de memoria y no pueden recuperarse de los hashes.
- Sesiones temporales de las tres identidades: permisos contrastados con PostgreSQL, lecturas y gestión según su autorización real, logout y posterior HTTP 401 correctos. Esta comprobación no sustituye los dos logins HTTP pendientes.
- Clave incorrecta y usuario inexistente: HTTP 401 con el mismo mensaje.
- Usar un hash almacenado como contraseña: rechazado para los tres usuarios.
- Utilidad de migración desde HTTP: bloqueada.
- Columna comprobada como VARCHAR(255); no se ejecutaron modificaciones de usuarios, roles ni permisos ni se crearon usuarios.
- `php -l`: 58 archivos PHP sin errores, excluida la configuración local. `node --check`: 17 archivos JavaScript sin errores. Script PowerShell analizado sin errores. `git diff --check` sin errores.

## Verificar los accesos sin compartir claves

Con el servidor local encendido mediante `scripts/iniciar_proicon.ps1`, ejecutar en otra terminal:

```powershell
.\scripts\verificar_login_seguro.ps1
```

Solicita las claves con `Read-Host -AsSecureString`, las transmite al proceso PHP únicamente por entrada estándar y no las guarda en disco ni como argumentos. Usa `tests/pruebas_contrasenas.php --credenciales-stdin --solo-verificar`: no ejecuta nuevamente la migración, no crea usuarios y no modifica roles/permisos. Informar únicamente las líneas OK/FALLO; nunca enviar las claves al chat.

La prueba completa con migración, antes de convertir una base que aún contiene texto plano, es `php tests/pruebas_contrasenas.php`. Después de convertirla necesita las claves por entrada estándar para probar logins; no intenta recuperar contraseñas desde hashes.

## Archivos de esta tarea

Creados: `tools/migrar_contrasenas.php`, `sql/migraciones/001_contrasenas_hash.sql`, `tests/pruebas_contrasenas.php`, `scripts/verificar_login_seguro.ps1` y este documento.

Modificados: `api/login.php`, `tests/pruebas_adquisiciones.php`, `tests/pruebas_avances.php`, `tests/pruebas_autorizacion.php` y las referencias de seguridad en `docs/revision_general.md` y `docs/autorizacion_roles.md`.

Sin recuperación por correo, usuarios nuevos, cambios de roles/permisos, commit ni push.
