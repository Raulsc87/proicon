(() => {
    const $ = id => document.getElementById(id);
    let editar = null, claveId = null, estadoAnterior = null, ocupado = false;
    async function api(accion, datos, query = '') {
        const r = await fetch('api/usuarios_' + accion + '.php' + query, {
            credentials: 'same-origin', cache: 'no-store',
            ...(datos ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(datos)} : {})
        });
        const resultado = await r.json();
        if (!r.ok) throw new Error(resultado.mensaje || 'No se pudo completar la operación.');
        return resultado.datos ?? resultado;
    }
    function mensaje(texto, error = false) { $('mensajeModulo').textContent = texto; $('mensajeModulo').className = 'mensaje ' + (error ? 'error' : 'correcto'); }
    function mostrar(id, foco) {
        $(id).hidden = false;
        $(id).scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block:'start'});
        $(foco).focus({preventScroll:true});
    }
    function cerrar() {
        for (const id of ['editor','editorClave','consulta']) $(id).hidden = true;
        $('formulario').reset(); $('formularioClave').reset();
        $('mensajeEditor').textContent = ''; $('mensajeClave').textContent = '';
        editar = claveId = null;
    }
    function opciones(select, datos, texto, valor) {
        select.replaceChildren(new Option('Selecciona una opción', ''));
        for (const r of datos) select.add(new Option(r[texto], r[valor]));
    }
    async function listar() {
        const datos = await api('listar', null, '?buscar=' + encodeURIComponent($('buscar').value));
        $('filas').replaceChildren();
        for (const u of datos) {
            const fila = document.createElement('tr');
            for (const campo of ['nombre_usuario','empleado','correo','rol','estado']) {
                const td = document.createElement('td'); td.textContent = u[campo] || '—'; fila.append(td);
            }
            const acciones = document.createElement('td'); acciones.className = 'acciones';
            for (const [texto, accion] of [['Consultar','consultar'], ...(Autorizacion.puede('GESTIONAR_USUARIOS') ? [['Editar','editar'],['Cambiar contraseña','clave'],[u.estado === 'ACTIVO' ? 'Desactivar' : 'Activar','estado']] : [])]) {
                const b = document.createElement('button'); b.type = 'button'; b.textContent = texto;
                b.dataset.accion = accion; b.dataset.id = u.id_usuario;
                b.addEventListener('click', () => ejecutar(() => actuar(accion, u)));
                acciones.append(b);
            }
            fila.append(acciones); $('filas').append(fila);
        }
        $('estadoLista').textContent = datos.length ? datos.length + ' usuarios.' : 'No se encontraron usuarios.';
    }
    async function ejecutar(tarea, errorId) {
        if (ocupado) return;
        ocupado = true;
        for (const b of document.querySelectorAll('main button')) b.disabled = true;
        try { await tarea(); } catch (e) { if (errorId) $(errorId).textContent = e.message; else mensaje(e.message, true); }
        finally { ocupado = false; for (const b of document.querySelectorAll('main button')) b.disabled = false; }
    }
    async function abrir(u = null) {
        const [roles, empleados] = await Promise.all([api('roles'), u ? Promise.resolve([]) : api('empleados')]);
        cerrar(); editar = u?.id_usuario ?? null; estadoAnterior = u?.estado ?? null;
        opciones($('rol'), roles, 'nombre', 'id_rol');
        opciones($('empleado'), empleados, 'nombre', 'id_empleado');
        $('empleado').disabled = !!u;
        if (u) {
            $('empleado').add(new Option(u.empleado, u.id_empleado)); $('empleado').value = u.id_empleado;
            if (!roles.some(r => r.id_rol == u.id_rol)) $('rol').add(new Option(u.rol + ' (actual)', u.id_rol));
            $('nombreCuenta').value = u.nombre_usuario; $('rol').value = u.id_rol; $('estado').value = u.estado;
        }
        for (const id of ['claveInicial','confirmacionInicial']) $(id).hidden = !!u;
        for (const id of ['clave','confirmacion']) { $(id).required = !u; $(id).disabled = !!u; }
        $('tituloEditor').textContent = u ? 'Editar usuario' : 'Nuevo usuario';
        $('guardar').textContent = u ? 'Guardar cambios' : 'Guardar';
        mostrar('editor', u ? 'nombreCuenta' : 'empleado');
    }
    async function actuar(accion, usuario) {
        const u = await api('obtener', null, '?id=' + usuario.id_usuario);
        if (accion === 'editar') return abrir(u);
        if (accion === 'consultar') {
            cerrar(); $('datosConsulta').replaceChildren();
            for (const [campo, titulo] of [['nombre_usuario','Usuario'],['empleado','Empleado'],['correo','Correo'],['rol','Rol'],['estado','Estado']]) {
                const dt = document.createElement('dt'), dd = document.createElement('dd'); dt.textContent = titulo; dd.textContent = u[campo] || '—'; $('datosConsulta').append(dt, dd);
            }
            mostrar('consulta','cerrarConsulta');
        } else if (accion === 'clave') {
            cerrar(); claveId = u.id_usuario; $('cuentaClave').textContent = u.nombre_usuario; mostrar('editorClave','nuevaClave');
        } else {
            const estado = u.estado === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
            if (estado === 'INACTIVO' && !confirm('¿Desea desactivar al usuario ' + u.nombre_usuario + '? No podrá iniciar sesión.')) return;
            await api('estado', {id:u.id_usuario, estado}); cerrar(); await listar(); mensaje('Estado del usuario actualizado.');
        }
    }
    Autorizacion.lista.then(async permitido => {
        if (!permitido) return;
        $('nuevo').addEventListener('click', () => ejecutar(() => abrir()));
        for (const id of ['cancelar','cancelarClave','cerrarConsulta']) $(id).addEventListener('click', cerrar);
        $('busqueda').addEventListener('submit', e => { e.preventDefault(); ejecutar(listar); });
        $('formulario').addEventListener('submit', e => {
            e.preventDefault(); ejecutar(async () => {
                $('mensajeEditor').textContent = '';
                if (editar && estadoAnterior === 'ACTIVO' && $('estado').value === 'INACTIVO' && !confirm('¿Desea desactivar este usuario? No podrá iniciar sesión.')) return;
                const datos = {nombre_usuario:$('nombreCuenta').value.trim(), id_rol:$('rol').value, estado:$('estado').value};
                if (editar) datos.id = editar;
                else Object.assign(datos, {id_empleado:$('empleado').value, contrasena:$('clave').value, confirmacion:$('confirmacion').value});
                await api(editar ? 'editar' : 'crear', datos); cerrar();
                if (!await cargarSesion()) return;
                await listar(); mensaje('Usuario guardado correctamente.');
            }, 'mensajeEditor');
        });
        $('formularioClave').addEventListener('submit', e => {
            e.preventDefault(); ejecutar(async () => {
                $('mensajeClave').textContent = '';
                await api('contrasena', {id:claveId, contrasena:$('nuevaClave').value, confirmacion:$('confirmarClave').value});
                cerrar(); await listar(); mensaje('Contraseña actualizada correctamente.');
            }, 'mensajeClave');
        });
        await ejecutar(listar);
    });
})();
