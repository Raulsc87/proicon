(() => {
    'use strict';
    const {el, api, solicitar, dinero, informar} = Proicon;
    const vista = document.querySelector('[data-resumen]').dataset.resumen;
    const crear = (tag, texto = '', clase = '') => {
        const n = document.createElement(tag); n.textContent = texto;
        if (clase) n.className = clase;
        return n;
    };
    function enlace(texto, proyecto, presupuesto = null) {
        const a = crear('a', texto);
        const parametros = new URLSearchParams({id: proyecto});
        if (presupuesto !== null) parametros.set('presupuesto', presupuesto);
        a.href = 'proyecto_detalle.html?' + parametros;
        return a;
    }
    async function consultar(proyecto) {
        const id_proyecto = proyecto.id_proyecto;
        if (vista === 'avance') {
            const r = await solicitar('api/avances.php?' + new URLSearchParams({accion: 'listar', id_proyecto}));
            return [{...r.resumen, proyecto}];
        }
        const r = await api(vista === 'presupuestos' ? 'presupuestos_listar' : 'empleados_listar', null, {id_proyecto});
        return r.map(registro => ({...registro, proyecto}));
    }
    function fila(r) {
        const tr = crear('tr');
        tr.append(crear('td', r.proyecto.codigo + ' · ' + r.proyecto.nombre));
        const columnas = vista === 'presupuestos'
            ? [r.version, r.fecha_registro, r.estado, dinero(r.total)]
            : vista === 'empleados'
                ? [r.nombre, r.funcion_en_proyecto, r.fecha_asignacion, r.estado]
                : [r.proyecto.estado, r.total, r.finalizadas];
        columnas.forEach(valor => tr.append(crear('td', valor ?? '—')));
        if (vista === 'presupuestos') tr.lastElementChild.className = 'importe';
        if (vista === 'avance') {
            const td = crear('td', r.porcentaje === null ? 'Sin actividades' : r.porcentaje + '%', 'avance-resumen');
            if (r.porcentaje !== null) {
                const barra = crear('progress'); barra.max = 100; barra.value = Number(r.porcentaje);
                barra.setAttribute('aria-label', 'Avance de ' + r.proyecto.nombre); td.append(barra);
            }
            tr.append(td);
        }
        const acciones = crear('td', '', 'acciones');
        acciones.append(enlace('Ver proyecto', r.proyecto.id_proyecto));
        if (vista === 'presupuestos') acciones.append(enlace('Ver presupuesto', r.proyecto.id_proyecto, r.id_presupuesto));
        tr.append(acciones); return tr;
    }
    async function cargar() {
        el('actualizarResumen').disabled = true;
        el('tablaResumen').setAttribute('aria-busy', 'true');
        el('filas').replaceChildren(); informar(''); el('filasEstado').textContent = 'Cargando…';
        try {
            const proyectos = await api('listar');
            const registros = [];
            // Reutiliza los endpoints por proyecto sin lanzar todas las peticiones simultáneamente.
            for (let i = 0; i < proyectos.length; i += 4) {
                const lote = await Promise.allSettled(proyectos.slice(i, i + 4).map(consultar));
                const fallo = lote.find(r => r.status === 'rejected');
                if (fallo) throw fallo.reason;
                lote.forEach(r => registros.push(...r.value));
            }
            el('filas').replaceChildren(...registros.map(fila));
            el('filasEstado').textContent = registros.length ? registros.length + ' registros.' : 'No hay registros para mostrar.';
        } catch (e) {
            el('filasEstado').textContent = 'No se pudo cargar la consulta completa.';
            informar(e.message || 'No se pudo conectar con el servidor. Intenta actualizar.', true);
        } finally {
            el('tablaResumen').setAttribute('aria-busy', 'false');
            el('actualizarResumen').disabled = false;
        }
    }
    el('actualizarResumen').addEventListener('click', cargar);
    cargar();
})();
