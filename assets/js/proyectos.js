(() => {
    const {el, api, operar, opciones, abrir, tabla, enviar, informar} = Proicon;
    let id = null, estadoId = null, solicitud = 0;
    async function listar() {
        const turno = ++solicitud;
        el('filasEstado').textContent = 'Cargando…';
        try {
            const r = await api('listar', null, {buscar: el('buscar').value.trim()});
            if (turno !== solicitud) return;
            tabla('filas', r, ['codigo', 'nombre', 'cliente', 'estado', 'estado_avance'], p => [
                ['Ver proyecto', () => { location.href = 'proyecto_detalle.html?id=' + p.id_proyecto; }],
                ['Editar', async () => { const r = await api('obtener', null, {id: p.id_proyecto}); id = p.id_proyecto; el('proyectoTitulo').textContent = 'Editar proyecto'; abrir('proyecto', r); }],
                ['Cambiar estado', () => { estadoId = p.id_proyecto; abrir('estadoProyecto', {nuevo_estado: p.estado}); }]
            ]);
        } catch (e) { if (turno === solicitud) { el('filas').replaceChildren(); el('filasEstado').textContent = 'No se pudo cargar la lista.'; informar(e.message, true); } }
    }
    el('nuevo').addEventListener('click', () => { id = null; el('proyectoTitulo').textContent = 'Nuevo proyecto'; abrir('proyecto'); });
    el('busqueda').addEventListener('submit', e => { e.preventDefault(); listar(); });
    enviar('proyecto', async d => { await api(id ? 'editar' : 'crear', id ? {...d, id} : d); el('proyectoEditor').hidden = true; informar('Proyecto guardado.'); await listar(); });
    enviar('estadoProyecto', async d => { await api('estado', {id: estadoId, estado: d.nuevo_estado}); el('estadoProyectoEditor').hidden = true; informar('Estado guardado.'); await listar(); });
    operar(async () => {
        const c = await api('catalogos');
        opciones('id_cliente', c.clientes, 'id_cliente', 'nombre');
        opciones('estado', c.estados); opciones('nuevo_estado', c.estados); opciones('estado_avance', c.avances);
        el('nuevo').disabled = false;
        await listar();
    });
})();
