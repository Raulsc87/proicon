-- Único cambio de esquema requerido para password_hash(PASSWORD_DEFAULT).
ALTER TABLE public.usuario
ALTER COLUMN contrasena TYPE VARCHAR(255);
