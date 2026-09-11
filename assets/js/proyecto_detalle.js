(() => {
    const {el, api, operar, opciones, abrir, tabla, enviar, informar, dinero, hoy} = Proicon;
    const pid = new URLSearchParams(location.search).get('id');
    let empleadoId = null, presupuestoId = null, presupuestoEditarId = null, detalleId = null, catalogos, presupuestos = [];
    const leer = (accion, extra = {}) => api(accion, null, {id_proyecto: pid, ...extra});
    const guardar = (accion, datos) => api(accion, {...datos, id_proyecto: pid});
    function abrirEmpleado(r = null) {
        empleadoId = r?.id_empleado ?? null;
        el('empleadoTitulo').textContent = r ? 'Editar asignación y estado' : 'Asignar empleado';
        abrir('empleado', r ? {...r, estado_empleado: r.estado} : {fecha_asignacion: hoy(), estado_empleado: 'ACTIVO'});
        el('id_empleado').disabled = Boolean(r);
    }
    async function cargarEmpleados() {
        const r = await leer('empleados_listar');
        tabla('empleados', r, ['nombre', 'telefono', 'funcion_en_proyecto', 'fecha_asignacion', 'estado'], e => [['Editar / estado', () => abrirEmpleado(e)]]);
        opciones('id_empleado', catalogos.empleados, 'id_empleado', 'nombre');
        [...el('id_empleado').options].forEach(o => { o.disabled = r.some(e => String(e.id_empleado) === o.value); });
    }
    async function cargarPresupuestos() {
        presupuestos = await leer('presupuestos_listar');
        tabla('presupuestos', presupuestos, ['version', 'fecha_registro', 'estado', 'creador', r => dinero(r.total)], r => [['Abrir presupuesto', () => cargarDetalles(r.id_presupuesto)], ['Editar', async () => {
            const p = await leer('presupuestos_obtener', {id_presupuesto: r.id_presupuesto});
            presupuestoEditarId = p.id_presupuesto;
            el('presupuestoTitulo').textContent = 'Editar presupuesto';
            abrir('presupuesto', {...p, estado_presupuesto: p.estado, observaciones_presupuesto: p.observaciones});
        }]]);
    }
    function abrirDetalle(r = null, lectura = false) {
        detalleId = r?.id_detalle_presupuesto ?? null;
        el('detalleTitulo').textContent = lectura ? 'Consultar detalle' : r ? 'Editar detalle' : 'Agregar detalle';
        abrir('detalle', r ? {...r, observaciones_detalle: r.observaciones} : {}, lectura);
    }
    async function cargarDetalles(id = presupuestoId) {
        const r = await leer('presupuestos_obtener', {id_presupuesto: id});
        presupuestoId = id;
        el('detalleEditor').hidden = true;
        el('tituloPresupuesto').textContent = 'Detalle del presupuesto · Versión ' + r.version;
        el('resumenPresupuesto').textContent = [r.fecha_registro, r.estado, 'Creado por: ' + r.creador, r.observaciones].filter(Boolean).join(' · ');
        tabla('detalles', r.detalles, ['concepto', 'categoria', r => r.material ?? 'Sin material', 'cantidad', r => dinero(r.precio_unitario), r => dinero(r.subtotal)], d => [['Consultar', () => abrirDetalle(d, true)], ['Editar', () => abrirDetalle(d)]]);
        el('totalPresupuesto').textContent = 'TOTAL PRESUPUESTO: ' + dinero(r.total).replace('Q ', 'Q\u00a0');
        el('detallePresupuesto').hidden = false;
        el('tituloPresupuesto').focus();
    }
    el('asignar').addEventListener('click', () => abrirEmpleado());
    el('nuevoPresupuesto').addEventListener('click', () => {
        presupuestoEditarId = null;
        el('presupuestoTitulo').textContent = 'Nuevo presupuesto';
        abrir('presupuesto', {version: Math.max(0, ...presupuestos.map(p => Number(p.version))) + 1, fecha_registro: hoy(), estado_presupuesto: 'BORRADOR'});
    });
    el('agregarDetalle').addEventListener('click', () => abrirDetalle());
    enviar('empleado', async d => {
        await guardar(empleadoId ? 'empleados_editar' : 'empleados_crear', {...d, id_empleado: empleadoId ?? d.id_empleado, estado: d.estado_empleado});
        el('empleadoEditor').hidden = true; informar('Asignación guardada.'); await cargarEmpleados();
    });
    enviar('presupuesto', async d => {
        const r = await guardar(presupuestoEditarId ? 'presupuestos_editar' : 'presupuestos_crear', {...d, id_presupuesto: presupuestoEditarId, estado: d.estado_presupuesto, observaciones: d.observaciones_presupuesto});
        el('presupuestoEditor').hidden = true; informar('Presupuesto guardado.'); await cargarPresupuestos(); await cargarDetalles(r.id_presupuesto);
    });
    enviar('detalle', async d => {
        await guardar(detalleId ? 'detalles_editar' : 'detalles_crear', {...d, id_presupuesto: presupuestoId, id_detalle_presupuesto: detalleId, observaciones: d.observaciones_detalle});
        el('detalleEditor').hidden = true; informar('Detalle guardado.'); await cargarPresupuestos(); await cargarDetalles();
    });
    operar(async () => {
        if (!/^[1-9]\d*$/.test(pid || '') || Number(pid) > 2147483647) throw new Error('El identificador del proyecto no es válido. Vuelve a Proyectos.');
        const [p, c] = await Promise.all([leer('obtener'), api('catalogos')]); catalogos = c;
        el('tituloProyecto').textContent = p.codigo + ' · ' + p.nombre;
        for (const [campo, titulo] of Object.entries({codigo: 'Código', nombre: 'Nombre', cliente: 'Cliente', descripcion: 'Descripción', ubicacion: 'Ubicación', estado: 'Estado', estado_avance: 'Estado de avance', fecha_inicio: 'Fecha inicio', fecha_fin_estimada: 'Fecha final estimada', observaciones: 'Observaciones'})) {
            const dt = document.createElement('dt'), dd = document.createElement('dd'); dt.textContent = titulo; dd.textContent = p[campo] ?? '—'; el('datosProyecto').append(dt, dd);
        }
        opciones('estado_empleado', c.estados_empleado); opciones('estado_presupuesto', c.estados_presupuesto);
        opciones('id_categoria_costo', c.categorias, 'id_categoria_costo', 'nombre'); opciones('id_material', c.materiales, 'id_material', 'nombre', true);
        await Promise.all([cargarEmpleados(), cargarPresupuestos()]); el('centroProyecto').hidden = false;
    });
})();
