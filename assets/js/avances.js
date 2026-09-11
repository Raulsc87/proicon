(() => {
    'use strict';
    const pid = new URLSearchParams(location.search).get('id');
    const n = (tag, text = '', cls = '') => { const e = document.createElement(tag); e.textContent = text; if (cls) e.className = cls; return e; };
    const actividades = n('section', '', 'panel-mantenimiento avances'); actividades.id = 'actividadesProyecto';
    const evidencia = n('section', '', 'panel-mantenimiento avances'); evidencia.id = 'evidenciaFotografica';
    const avance = n('section', '', 'panel-mantenimiento avances'); avance.id = 'avanceObra';
    document.getElementById('datosProyecto').closest('section').after(avance);
    document.getElementById('adquisiciones').before(actividades);
    document.getElementById('adquisiciones').after(evidencia);
    const mensaje = n('p', '', 'mensaje'); mensaje.setAttribute('role', 'status');
    actividades.append(n('h2', 'Actividades del proyecto'), mensaje);
    let catalogos, ocupado = false, siguiente = null, fotosCargadas = false;
    async function api(accion, datos = null, extra = {}) {
        let body;
        if (datos instanceof FormData) { datos.set('id_proyecto', pid); body = datos; }
        else if (datos) body = JSON.stringify({...datos, id_proyecto: pid});
        const res = await fetch('api/avances.php?' + new URLSearchParams({accion, id_proyecto: pid, ...extra}), {method: datos ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', ...(body ? {body} : {}), ...(datos && !(datos instanceof FormData) ? {headers: {'Content-Type': 'application/json'}} : {})});
        if (res.status === 401) { location.replace('index.html'); throw new Error('La sesión ha terminado.'); }
        let r; try { r = await res.json(); } catch { throw new Error('No se pudo leer la respuesta del servidor.'); }
        if (!res.ok || !r.ok) throw new Error(r.mensaje || 'No se pudo completar la operación.');
        return r.datos;
    }
    async function operar(fn) {
        if (ocupado) return;
        ocupado = true;
        const botones = [...document.querySelectorAll('.avances button')].filter(b => !b.disabled);
        botones.forEach(b => { b.disabled = true; });
        try { await fn(); }
        catch (e) {
            const destino = document.querySelector('.avances dialog[open] .mensaje') || mensaje;
            destino.textContent = e.message; destino.className = 'mensaje error';
        } finally { ocupado = false; botones.forEach(b => { b.disabled = false; }); }
    }
    function boton(txt, fn, parent) { const b = n('button', txt); b.type = 'button'; b.addEventListener('click', () => operar(fn)); parent.append(b); return b; }
    function campo(name, label, type = 'text', extra = {}) { return {name, label, type, ...extra}; }
    function dialogo(titulo, campos, valores = {}, guardar = null) {
        const d = n('dialog'), h = n('h2', titulo), f = n('form'), grid = n('fieldset', '', 'campos-mantenimiento'), status = n('p', '', 'mensaje');
        h.id = 'tituloDialogoAvance'; d.setAttribute('aria-labelledby', h.id); status.setAttribute('role', 'status');
        for (const c of campos) {
            const g = n('div', '', 'grupo'), label = n('label', c.label + (c.required ? ' *' : ''));
            const input = n(c.type === 'select' ? 'select' : c.type === 'textarea' ? 'textarea' : 'input');
            if (input.tagName === 'INPUT') input.type = c.type;
            input.id = 'avance_' + c.name; input.name = c.name; label.htmlFor = input.id;
            if (c.type === 'select') { input.add(new Option(c.required ? 'Selecciona…' : 'Sin responsable', '')); c.options.forEach(([v, t]) => input.add(new Option(t, v))); }
            input.required = Boolean(c.required); input.disabled = !guardar;
            if (c.maxLength) input.maxLength = c.maxLength;
            if (c.type === 'number') { input.min = '0'; input.max = '100'; input.step = '0.01'; }
            if (c.type === 'file') input.accept = catalogos.formatos_foto.map(e => '.' + e).join(',');
            else input.value = valores[c.name] ?? c.value ?? '';
            g.append(label, input); grid.append(g);
        }
        f.append(grid, status);
        if (guardar) { const b = n('button', titulo.startsWith('Editar') ? 'Guardar cambios' : 'Guardar'); b.type = 'submit'; f.append(b); }
        const cerrar = n('button', guardar ? 'Cancelar' : 'Cerrar'); cerrar.type = 'button'; cerrar.addEventListener('click', () => { if (!ocupado) d.close(); }); f.append(cerrar);
        d.addEventListener('cancel', e => { if (ocupado) e.preventDefault(); }); d.addEventListener('close', () => d.remove());
        f.addEventListener('submit', e => { e.preventDefault(); operar(async () => {
            const fd = new FormData(f), archivo = fd.get('archivo');
            if (archivo instanceof File && archivo.size > 2 * 1024 * 1024) throw new Error('La imagen supera los 2 MB.');
            await guardar(fd); d.close(); mensaje.textContent = 'Cambios guardados.'; mensaje.className = 'mensaje correcto';
        }); });
        d.append(h, f); actividades.append(d); d.showModal();
        f.querySelector('input:not(:disabled), select:not(:disabled), textarea:not(:disabled)')?.focus({preventScroll: true});
        f.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest'});
    }
    function camposActividad() {
        return [campo('nombre', 'Actividad', 'text', {required: true, maxLength: 150}), campo('id_empleado_responsable', 'Responsable', 'select', {options: catalogos.empleados.map(e => [e.id_empleado, e.nombre])}), campo('fecha_inicio', 'Inicio', 'date'), campo('fecha_fin_prevista', 'Fin previsto', 'date'), campo('fecha_fin_real', 'Fin real', 'date'), campo('estado', 'Estado', 'select', {required: true, options: catalogos.estados.map(e => [e, e])}), campo('porcentaje_avance', 'Avance registrado de la actividad (%)', 'number', {required: true, value: '0'}), campo('situacion_tiempo', 'Situación de tiempo registrada', 'select', {required: true, options: catalogos.situaciones.map(e => [e, e])}), campo('descripcion', 'Descripción', 'textarea', {maxLength: 100000}), campo('observaciones', 'Observaciones', 'textarea', {maxLength: 100000})];
    }
    async function editar(id = null, lectura = false, estado = false) {
        const r = id ? await api('obtener', null, {id_actividad: id}) : {};
        dialogo(lectura ? 'Consultar actividad' : estado ? 'Cambiar estado' : id ? 'Editar actividad' : 'Nueva actividad', estado ? camposActividad().filter(c => c.name === 'estado') : camposActividad(), r, lectura ? null : async fd => {
            await api(estado ? 'estado' : id ? 'editar' : 'crear', {...Object.fromEntries(fd), id_actividad: id}); await cargar();
        });
    }
    const nueva = boton('+ Nueva actividad', () => editar(), actividades); nueva.disabled = true;
    const contenedor = n('div', '', 'tabla-contenedor'); actividades.append(contenedor);
    async function cargar() {
        const r = await api('listar'); catalogos = r;
        avance.replaceChildren(n('h2', 'Avance de obra'));
        const {total, finalizadas, porcentaje} = r.resumen;
        if (porcentaje === null) avance.append(n('p', 'Sin actividades: todavía no hay un porcentaje de avance.'));
        else { avance.append(n('p', porcentaje + '%', 'porcentaje-obra')); const barra = n('progress'); barra.max = 100; barra.value = Number(porcentaje); barra.setAttribute('aria-label', 'Avance de obra por actividades finalizadas'); avance.append(barra); }
        avance.append(n('p', `${finalizadas} de ${total} actividades finalizadas. Todas tienen el mismo peso.`), n('p', 'Cálculo según el estado FINALIZADO; el porcentaje individual se conserva como dato independiente.', 'nota-avance'));
        const tabla = n('table'), thead = n('thead'), tr = n('tr'), tbody = n('tbody');
        ['Actividad', 'Responsable', 'Inicio', 'Fin previsto', 'Estado', 'Acciones'].forEach(t => { const th = n('th', t); th.scope = 'col'; tr.append(th); }); thead.append(tr);
        for (const a of r.actividades) {
            const fila = n('tr'); ['nombre', 'responsable', 'fecha_inicio', 'fecha_fin_prevista', 'estado'].forEach(c => fila.append(n('td', a[c] ?? '—')));
            const acciones = n('td', '', 'acciones'); boton('Consultar', () => editar(a.id_actividad, true), acciones); boton('Editar', () => editar(a.id_actividad), acciones); boton('Estado', () => editar(a.id_actividad, false, true), acciones); fila.append(acciones); tbody.append(fila);
        }
        tabla.append(thead, tbody); contenedor.replaceChildren(tabla);
        if (!r.actividades.length) contenedor.append(n('p', 'No hay actividades registradas.'));
        nueva.disabled = false;
    }
    evidencia.append(n('h2', 'Evidencia fotográfica'));
    const subir = boton('+ Subir foto', () => dialogo('Subir fotografía de avance', [campo('archivo', 'Imagen (máximo 2 MB)', 'file', {required: true}), campo('descripcion', 'Descripción', 'textarea', {maxLength: 250})], {}, async fd => { await api('foto_subir', fd); await cargarFotos(true); desplegable.open = true; }), evidencia); subir.disabled = true;
    evidencia.append(n('p', 'La fecha y el usuario se registran automáticamente.'));
    const desplegable = n('details'), sum = n('summary', 'Ver fotografías del proyecto'), galeria = n('div', '', 'galeria-avance'), estadoFotos = n('p');
    estadoFotos.setAttribute('role', 'status');
    desplegable.append(sum, estadoFotos, galeria); evidencia.append(desplegable);
    const mas = boton('Cargar más fotografías', () => cargarFotos(), desplegable); mas.hidden = true;
    function imagenUrl(id) { return 'api/avances.php?' + new URLSearchParams({accion: 'imagen', id_proyecto: pid, id_fotografia: id}); }
    async function verFoto(f) {
        const d = n('dialog', '', 'visor-foto'), h = n('h2', 'Consultar fotografía'), img = n('img');
        img.src = imagenUrl(f.id_fotografia); img.alt = f.descripcion || 'Fotografía de avance';
        const error = n('p'); img.addEventListener('error', () => { img.hidden = true; error.textContent = 'Imagen no disponible'; });
        d.append(h, img, error, n('p', f.descripcion || 'Sin descripción'), n('p', f.fecha_carga + ' · ' + f.registrada_por));
        const b = n('button', 'Cerrar'); b.type = 'button'; b.addEventListener('click', () => d.close()); d.append(b); d.addEventListener('close', () => d.remove()); evidencia.append(d); d.showModal();
    }
    function editarFoto(f) {
        dialogo('Editar fotografía de avance', [campo('descripcion', 'Descripción', 'textarea', {maxLength: 250}), campo('fecha_carga', 'Fecha de carga', 'date', {required: true}), campo('archivo', 'Reemplazar imagen (opcional, máximo 2 MB)', 'file')], f, async fd => {
            fd.set('id_fotografia', f.id_fotografia);
            const r = await api('foto_editar', fd);
            await cargarFotos(true);
            estadoFotos.textContent = r.aviso || 'Fotografía actualizada.';
        });
    }
    async function eliminarFoto(f) {
        if (!confirm('¿Desea eliminar esta fotografía de avance?')) return;
        try {
            const r = await api('foto_eliminar', {id_fotografia: f.id_fotografia});
            await cargarFotos(true);
            estadoFotos.textContent = r.aviso || 'Fotografía eliminada. La bitácora se conserva.';
        } catch (e) { estadoFotos.textContent = e.message; }
    }
    async function cargarFotos(reiniciar = false) {
        const r = await api('fotos_listar', null, !reiniciar && siguiente ? {antes: siguiente} : {});
        if (reiniciar) galeria.replaceChildren();
        for (const f of r.fotos) {
            const card = n('article', '', 'foto-avance'), img = n('img'); img.src = imagenUrl(f.id_fotografia); img.alt = f.descripcion || 'Fotografía de avance'; img.loading = 'lazy';
            img.addEventListener('error', () => { img.hidden = true; card.prepend(n('p', 'Imagen no disponible')); }, {once: true});
            card.append(img, n('p', f.descripcion || 'Sin descripción'), n('p', f.fecha_carga + ' · ' + f.registrada_por));
            boton('Consultar', () => verFoto(f), card);
            boton('Editar', () => editarFoto(f), card);
            boton('Eliminar', () => eliminarFoto(f), card);
            galeria.append(card);
        }
        fotosCargadas = true; siguiente = r.siguiente; mas.hidden = !siguiente; estadoFotos.textContent = galeria.children.length ? '' : 'Todavía no hay fotografías.';
    }
    desplegable.addEventListener('toggle', () => { if (desplegable.open && !fotosCargadas) operar(() => cargarFotos(true)); });
    operar(async () => {
        if (!/^[1-9]\d*$/.test(pid || '')) throw new Error('Identificador de proyecto inválido.');
        await cargar(); subir.disabled = false;
    });
})();
