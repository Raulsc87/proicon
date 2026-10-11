-- Permisos del catálogo general de empleados. Puede ejecutarse varias veces.
-- Conserva los permisos y las asignaciones que ya existen.
BEGIN;

INSERT INTO public.permiso (codigo, nombre, descripcion)
VALUES
    ('VER_EMPLEADOS', 'Ver empleados',
     'Permite consultar el catálogo general de empleados'),
    ('GESTIONAR_EMPLEADOS', 'Gestionar empleados',
     'Permite crear, editar y activar o inactivar empleados')
ON CONFLICT (codigo) DO NOTHING;

INSERT INTO public.rol_permiso (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM (VALUES
    ('ADMINISTRADOR', 'VER_EMPLEADOS'),
    ('ADMINISTRADOR', 'GESTIONAR_EMPLEADOS'),
    ('SECRETARIA', 'VER_EMPLEADOS'),
    ('SECRETARIA', 'GESTIONAR_EMPLEADOS'),
    ('ENCARGADO_OBRA', 'VER_EMPLEADOS')
) AS asignacion (rol_nombre, permiso_codigo)
JOIN public.rol AS r ON r.nombre = asignacion.rol_nombre
JOIN public.permiso AS p ON p.codigo = asignacion.permiso_codigo
ON CONFLICT (id_rol, id_permiso) DO NOTHING;

COMMIT;
