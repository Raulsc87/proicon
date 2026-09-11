(() => {
    'use strict';
    const root = document.getElementById('adquisiciones');
    if (!root) return;
    const params = new URLSearchParams(location.search);
    const vista = root.dataset.vista;
    const pid = params.get(vista === 'proyecto' ? 'id' : 'proyecto');
    const id = params.get('id');
    let cat, actual, ocupado = false;
    const nodo = (tag, texto, clase) => { const n = document.createElement(tag); if (texto != null) n.textContent = texto; if (clase) n.className = clase; return n; };
    const hoy = () => { const d = new Date(); return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-'); };
    const url = (pagina, registro) => pagina + '.html?' + new URLSearchParams({proyecto: pid, id: registro});
    const money = valor => {
        const s = String(valor), negativo = s.startsWith('-');
        const [entero, decimal = ''] = s.replace(/^-/, '').split('.');
        const cents = BigInt(entero) * 100n + BigInt(decimal.padEnd(2, '0').slice(0, 2)) + (Number(decimal[2] || 0) >= 5 ? 1n : 0n);
        return (negativo && cents ? '−' : '') + 'Q\u00a0' + (cents / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (cents % 100n).toString().padStart(2, '0');
    };
    const centavos = valor => { const [a, b = ''] = String(valor).split('.'); return BigInt(a) * 100n + BigInt(b.padEnd(2, '0').slice(0, 2)) + (Number(b[2] || 0) >= 5 ? 1n : 0n); };
    async function api(accion, datos = null, extra = {}) {
        let body;
        if (datos instanceof FormData) { datos.set('id_proyecto', pid); body = datos; }
        else if (datos) body = JSON.stringify({...datos, id_proyecto: pid});
        const r = await fetch('api/adquisiciones.php?' + new URLSearchParams({accion, id_proyecto: pid, ...extra}), {method: datos ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', ...(body ? {body} : {}), ...(datos && !(datos instanceof FormData) ? {headers: {'Content-Type': 'application/json'}} : {})});
        if (r.status === 401) { location.replace('index.html'); throw new Error('La sesión ha terminado.'); }
        let json; try { json = await r.json(); } catch { throw new Error('El servidor no devolvió una respuesta válida.'); }
        if (!r.ok || !json.ok) throw new Error(json.mensaje || 'No se pudo completar la operación.');
        return json.datos;
    }
    function mensaje(texto, error = false) {
        const destino = root.querySelector('dialog[open] .mensaje') || root.querySelector('[data-mensaje]');
        if (destino) { destino.textContent = texto; destino.className = 'mensaje ' + (error ? 'error' : 'correcto'); }
    }
    async function operar(fn) {
        if (ocupado) return;
        ocupado = true;
        const controles = [...root.querySelectorAll('button')].filter(b => !b.disabled);
        controles.forEach(b => { b.disabled = true; });
        try { await fn(); } catch (e) { mensaje(e.message || 'No se pudo conectar con el servidor.', true); }
        finally { ocupado = false; controles.forEach(b => { b.disabled = false; }); }
    }
    function boton(texto, fn, padre, deshabilitado = false) {
        const b = nodo('button', texto); b.type = 'button'; b.disabled = deshabilitado;
        b.addEventListener('click', () => operar(fn)); padre.append(b); return b;
    }
    function enlace(texto, href, padre) { const a = nodo('a', texto); a.href = href; padre.append(a); return a; }
    function panel(titulo) { const p = nodo('section', null, 'panel-mantenimiento'); p.append(nodo('h2', titulo)); root.append(p); return p; }
    function datos(padre, registro, campos) {
        const dl = nodo('dl', null, 'datos-proyecto');
        for (const [etiqueta, valor] of campos) dl.append(nodo('dt', etiqueta), nodo('dd', typeof valor === 'function' ? valor(registro) : registro[valor] ?? '—'));
        padre.append(dl);
    }
    function tabla(padre, registros, columnas, acciones) {
        const cont = nodo('div', null, 'tabla-contenedor'), t = nodo('table'), head = nodo('thead'), hr = nodo('tr'), body = nodo('tbody');
        columnas.forEach(([titulo]) => { const th = nodo('th', titulo); th.scope = 'col'; hr.append(th); });
        if (acciones) hr.append(nodo('th', 'Acciones'));
        head.append(hr); t.append(head, body);
        for (const r of registros) {
            const tr = nodo('tr');
            columnas.forEach(([, campo, clase]) => { tr.append(nodo('td', (typeof campo === 'function' ? campo(r) : r[campo]) ?? '—', clase)); });
            if (acciones) { const td = nodo('td', null, 'acciones'); acciones(r).forEach(([txt, fn]) => boton(txt, fn, td)); tr.append(td); }
            body.append(tr);
        }
        cont.append(t); if (!registros.length) cont.append(nodo('p', 'No hay registros.')); padre.append(cont);
    }
    function resumen(padre, elementos) {
        const r = nodo('div', null, 'resumen');
        elementos.forEach(([titulo, valor]) => { const p = nodo('p', titulo); p.append(nodo('strong', valor, 'importe')); r.append(p); }); padre.append(r);
    }
    const texto = (name, label, max = 250, required = false) => ({name, label, max, required});
    const fecha = (name, label, required = true) => ({name, label, type: 'date', required, value: hoy()});
    const observaciones = {name: 'observaciones', label: 'Observaciones', type: 'textarea', max: 100000};
    const numero = (name, label, cantidad = false) => ({name, label, type: 'number', required: true, min: name === 'precio_unitario' ? '0' : '0.01', maxNumero: cantidad ? '9999999999.99' : '999999999999.99', step: '0.01'});
    const selector = (name, label, registros, clave = null, etiqueta = null) => ({name, label, type: 'select', required: true, opciones: registros.map(r => clave ? [r[clave], r[etiqueta]] : [r, r])});
    const archivo = {name: 'archivo', label: 'Archivo PDF, JPG, JPEG o PNG (opcional, máximo 2 MB)', type: 'file'};
    function formulario(titulo, campos, valores, guardar, nota = '') {
        const dialog = nodo('dialog'), h = nodo('h2', titulo), form = nodo('form'), grid = nodo('fieldset', null, 'campos-mantenimiento');
        h.id = 'adquisicionTitulo'; dialog.setAttribute('aria-labelledby', h.id);
        const status = nodo('p', '', 'mensaje'); status.setAttribute('role', 'status');
        dialog.append(h); if (nota) dialog.append(nodo('p', nota, 'aviso'));
        for (const c of campos) {
            const group = nodo('div', null, 'grupo'), label = nodo('label', c.label + (c.required ? ' *' : ''));
            const input = nodo(c.type === 'select' ? 'select' : c.type === 'textarea' ? 'textarea' : 'input');
            input.name = c.name; input.id = 'adq_' + c.name; label.htmlFor = input.id;
            if (input.tagName === 'INPUT') input.type = c.type || 'text';
            if (c.type === 'select') { input.add(new Option('Selecciona…', '')); c.opciones.forEach(([v, t]) => input.add(new Option(t, v))); }
            if (c.type === 'file') input.accept = '.pdf,.jpg,.jpeg,.png';
            else input.value = valores[c.name] ?? c.value ?? (c.opciones?.length === 1 ? c.opciones[0][0] : '');
            input.required = Boolean(c.required); if (c.max) input.maxLength = c.max;
            if (c.min !== undefined) input.min = c.min; if (c.maxNumero) input.max = c.maxNumero; if (c.step) input.step = c.step;
            if (c.sugerencias) { const list = nodo('datalist'); list.id = input.id + '_opciones'; input.setAttribute('list', list.id); c.sugerencias.forEach(t => list.append(new Option(t, t))); group.append(list); }
            if (!guardar) input.disabled = true;
            group.append(label, input); grid.append(group);
        }
        form.append(grid, status);
        if (guardar) { const b = nodo('button', titulo.startsWith('Editar') ? 'Guardar cambios' : 'Guardar'); b.type = 'submit'; form.append(b); }
        const cerrar = nodo('button', guardar ? 'Cancelar' : 'Cerrar'); cerrar.type = 'button'; cerrar.addEventListener('click', () => { if (!ocupado) dialog.close(); }); form.append(cerrar);
        dialog.addEventListener('cancel', e => { if (ocupado) e.preventDefault(); });
        dialog.addEventListener('close', () => dialog.remove());
        form.addEventListener('submit', e => { e.preventDefault(); operar(async () => {
            const fd = new FormData(form), f = fd.get('archivo');
            if (f instanceof File && f.size > 2 * 1024 * 1024) throw new Error('El archivo supera los 2 MB.');
            await guardar(fd); dialog.close(); await cargar(); mensaje('Cambios guardados.');
        }); });
        dialog.append(form); root.append(dialog); dialog.showModal();
        form.querySelector('input:not(:disabled), select:not(:disabled), textarea:not(:disabled)')?.focus({preventScroll: true});
        form.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest'});
        return form;
    }
    function base() {
        root.replaceChildren();
        const m = nodo('p', '', 'mensaje'); m.dataset.mensaje = ''; m.setAttribute('role', 'status'); root.append(m);
        if (vista !== 'proyecto') { const nav = nodo('nav', null, 'ruta'); enlace('Proyectos', 'proyectos.html', nav); enlace(cat.proyecto.codigo + ' · ' + cat.proyecto.nombre, 'proyecto_detalle.html?id=' + pid, nav); root.append(nav); }
    }
    const obsDetalle = {...observaciones, max: 250};
    function descargar(factura, pago = null) {
        const q = {accion: 'archivo', id_proyecto: pid, id_factura: factura}; if (pago) q.id_pago = pago;
        const a = document.createElement('a'); a.href = 'api/adquisiciones.php?' + new URLSearchParams(q); a.target = '_blank'; a.rel = 'noopener'; a.click();
    }
    async function cargar() {
        // Conserva la vista actual hasta completar la lectura para no ocultar errores de guardado.
        const r = vista === 'proyecto' ? await api('solicitudes_listar') : await api(vista === 'solicitud' ? 'solicitudes_obtener' : vista === 'compra' ? 'compras_obtener' : 'facturas_obtener', null, {[vista === 'solicitud' ? 'id_solicitud' : vista === 'compra' ? 'id_compra' : 'id_factura']: id});
        actual = r; base();
        if (vista === 'proyecto') pintarSolicitudes(r);
        if (vista === 'solicitud') pintarSolicitud(r);
        if (vista === 'compra') pintarCompra(r);
        if (vista === 'factura') pintarFactura(r);
    }
    function pintarSolicitudes(registros) {
        const p = panel('Solicitudes de materiales');
        boton('+ Nueva solicitud', () => formulario('Nueva solicitud', [texto('codigo', 'Código', 30, true), fecha('fecha_solicitud', 'Fecha solicitada'), {...fecha('fecha_necesaria', 'Fecha necesaria', false), value: ''}, selector('estado', 'Estado', cat.estados_solicitud), observaciones], {}, fd => api('solicitudes_crear', Object.fromEntries(fd)), 'La revisión se registra por separado. La solicitud se crea sin actividad asociada.'), p);
        tabla(p, registros, [['Código', 'codigo'], ['Fecha solicitada', 'fecha_solicitud'], ['Fecha necesaria', 'fecha_necesaria'], ['Estado', 'estado'], ['Solicitada por', 'solicitante'], ['Revisada por', r => r.revisor ?? 'Sin revisar']], r => [['Abrir solicitud', () => { location.href = url('solicitud_detalle', r.id_solicitud); }]]);
    }
    function pintarSolicitud(r) {
        const p = panel('Solicitud ' + r.codigo);
        datos(p, r, [['Proyecto', () => cat.proyecto.nombre], ['Fecha', 'fecha_solicitud'], ['Fecha necesaria', 'fecha_necesaria'], ['Estado', 'estado'], ['Solicitada por', 'solicitante'], ['Revisada por', x => x.revisor ?? 'Sin revisar'], ['Actividad', x => x.actividad ?? 'Sin actividad asociada'], ['Observaciones', 'observaciones']]);
        boton('Revisar solicitud', async () => { if (!confirm('¿Registrar la revisión con tu usuario actual?')) return; await api('solicitudes_revisar', {id_solicitud: id}); await cargar(); }, p, !r.editable || !r.detalles.length);
        const mat = panel('Materiales solicitados');
        const editar = (d = {}, lectura = false) => formulario(lectura ? 'Consultar material' : d.id_detalle_solicitud ? 'Editar material solicitado' : 'Material solicitado', [selector('id_material', 'Material', cat.materiales.map(m => ({...m, etiqueta: m.nombre + ' (' + m.unidad + ')'})), 'id_material', 'etiqueta'), numero('cantidad', 'Cantidad', true), obsDetalle], d, lectura ? null : fd => api('solicitud_detalle_guardar', {...Object.fromEntries(fd), id_solicitud: id, id_detalle_solicitud: d.id_detalle_solicitud}), 'Los cambios de materiales requieren revisar nuevamente la solicitud.');
        boton('+ Agregar material', () => editar(), mat, !r.editable);
        tabla(mat, r.detalles, [['Material', 'material'], ['Unidad', 'unidad'], ['Cantidad', 'cantidad', 'cantidad']], d => [['Consultar', () => editar(d, true)], ...(r.editable ? [['Editar', () => editar(d)], ['Quitar', async () => { if (!confirm('¿Quitar este material de la solicitud?')) return; await api('solicitud_detalle_quitar', {id_solicitud: id, id_detalle_solicitud: d.id_detalle_solicitud}); await cargar(); }]] : [])]);
        if (!r.editable) mat.append(nodo('p', 'La solicitud ya tiene compras; sus materiales se conservan.', 'aviso'));
        const compras = panel('Compras de la solicitud');
        boton('+ Crear compra', () => formulario('Crear compra desde ' + r.codigo, [texto('codigo', 'Código de compra', 30, true), selector('id_proveedor', 'Proveedor', cat.proveedores, 'id_proveedor', 'nombre'), fecha('fecha_compra', 'Fecha'), selector('estado', 'Estado de recepción', cat.estados_compra), observaciones], {}, async fd => { const c = await api('compras_crear', {...Object.fromEntries(fd), id_solicitud: id}); location.href = url('compra_detalle', c.id_compra); }, 'Después selecciona materiales de esta solicitud e indica sus precios. Los estados disponibles son: ' + cat.estados_compra.join(', ') + '.'), compras, !r.revisada_por || r.estado !== 'APROBADA');
        tabla(compras, r.compras, [['Código', 'codigo'], ['Proveedor', 'proveedor'], ['Fecha', 'fecha_compra'], ['Recepción', 'estado'], ['Total', x => money(x.total), 'importe']], c => [['Abrir compra', () => { location.href = url('compra_detalle', c.id_compra); }]]);
    }
    function pintarCompra(r) {
        if (r.id_solicitud) enlace('← Solicitud ' + r.solicitud, url('solicitud_detalle', r.id_solicitud), root.querySelector('.ruta'));
        const p = panel('Compra ' + r.codigo);
        datos(p, r, [['Proyecto', () => cat.proyecto.nombre], ['Solicitud origen', 'solicitud'], ['Proveedor', 'proveedor'], ['Fecha', 'fecha_compra'], ['Estado de recepción', 'estado'], ['Creada por', 'creador'], ['Observaciones', 'observaciones']]);
        boton('Confirmar / cambiar recepción', () => formulario('Recepción de la compra', [selector('estado', 'Estado de recepción', cat.estados_compra)], r, fd => api('compras_estado', {...Object.fromEntries(fd), id_compra: id})), p, !r.detalles.length);
        const mat = panel('Materiales comprados');
        const disponibles = r.id_solicitud ? r.solicitados : cat.materiales.map(m => ({id_material: m.id_material, material: m.nombre}));
        const editar = (d = {}, lectura = false) => {
            const lista = [...disponibles];
            if (d.id_material && !lista.some(m => String(m.id_material) === String(d.id_material))) lista.push({id_material: d.id_material, material: d.material});
            const f = formulario(lectura ? 'Consultar detalle de compra' : d.id_detalle_compra ? 'Editar detalle de compra' : 'Material de compra', [selector('id_material', 'Material', lista, 'id_material', 'material'), numero('cantidad', 'Cantidad', true), numero('precio_unitario', 'P. Unitario (Q)'), obsDetalle], d, lectura ? null : fd => api('compra_detalle_guardar', {...Object.fromEntries(fd), id_compra: id, id_detalle_compra: d.id_detalle_compra}), r.id_solicitud ? 'Al seleccionar un material se propone la cantidad aún disponible de la solicitud. Indica el precio de compra.' : 'Indica la cantidad y el precio de compra.');
            if (!lectura && !d.id_detalle_compra) f.elements.id_material.addEventListener('change', () => { const m = lista.find(m => String(m.id_material) === f.elements.id_material.value); if (m?.disponible !== undefined) f.elements.cantidad.value = m.disponible; });
            if (!lectura && !d.id_detalle_compra && lista.length === 1 && lista[0].disponible !== undefined) f.elements.cantidad.value = lista[0].disponible;
        };
        boton('+ Agregar desde solicitud', () => editar(), mat, !r.editable);
        tabla(mat, r.detalles, [['Material', 'material'], ['Cantidad', 'cantidad', 'cantidad'], ['P. Unitario', d => money(d.precio_unitario), 'importe'], ['Subtotal', d => money(d.subtotal), 'importe']], d => [['Consultar', () => editar(d, true)], ...(r.editable ? [['Editar', () => editar(d)]] : [])]);
        resumen(mat, [['TOTAL COMPRA', money(r.total)]]);
        if (!r.editable) mat.append(nodo('p', 'La compra ya tiene facturas; los importes y materiales se conservan.', 'aviso'));
        const facturas = panel('Facturas');
        boton('+ Registrar factura', () => {
            const f = formulario('Registrar factura', [texto('numero_factura', 'Número de factura', 60, true), fecha('fecha_factura', 'Fecha'), numero('monto_total', 'Monto total (Q)'), selector('estado', 'Estado', cat.estados_factura), archivo], {monto_total: String(r.total).replace(/(\.\d{2})\d+$/, '$1')}, fd => { fd.set('id_compra', id); return api('facturas_crear', fd); });
            const aviso = nodo('p', '', 'aviso'); f.prepend(aviso);
            const comparar = () => { try { aviso.textContent = centavos(f.elements.monto_total.value || '0') !== centavos(r.total) ? 'El monto difiere del total de compra (' + money(r.total) + '). Puede incluir impuestos u otros cargos; se permite registrarlo.' : 'El monto coincide con el total de compra.'; } catch { aviso.textContent = 'Revisa el monto de factura.'; } }; f.elements.monto_total.addEventListener('input', comparar); comparar();
        }, facturas, !r.detalles.length || r.estado !== 'RECIBIDA');
        tabla(facturas, r.facturas, [['Número', 'numero_factura'], ['Fecha', 'fecha_factura'], ['Monto', f => money(f.monto_total), 'importe'], ['Estado', 'estado'], ['Registrada por', 'registrador']], f => [['Factura / pagos', () => { location.href = url('factura_detalle', f.id_factura); }], ...(f.ruta_archivo ? [['Archivo', () => descargar(f.id_factura)]] : [])]);
        if (r.facturas.some(f => centavos(f.monto_total) !== centavos(r.total))) facturas.append(nodo('p', 'Hay facturas cuyo monto difiere del total de compra. Consulta cada factura para revisar los importes.', 'aviso'));
    }
    function pintarFactura(r) {
        enlace('← Compra ' + r.compra, url('compra_detalle', r.id_compra), root.querySelector('.ruta'));
        const p = panel('Factura ' + r.numero_factura);
        datos(p, r, [['Proyecto', () => cat.proyecto.nombre], ['Compra', 'compra'], ['Fecha', 'fecha_factura'], ['Estado registrado', 'estado'], ['Registrada por', 'registrador']]);
        if (r.ruta_archivo) boton('Descargar factura', () => descargar(r.id_factura), p); else p.append(nodo('p', 'Sin archivo adjunto.'));
        resumen(p, [['Monto factura', money(r.monto_total)], ['Total pagado', money(r.total_pagado)], ['Saldo pendiente', money(r.saldo)]]);
        if (Number(r.saldo) <= 0) p.append(nodo('p', 'PAGADA' + (Number(r.saldo) < 0 ? ' · Existe un excedente de pago.' : ''), 'correcto'));
        const pagos = panel('Pagos');
        const campos = [{...texto('tipo_pago', 'Tipo de pago', 30, true), sugerencias: cat.tipos_pago}, texto('numero_operacion', 'Número de operación', 100), fecha('fecha_pago', 'Fecha'), numero('monto', 'Monto (Q)'), archivo, observaciones];
        boton('+ Registrar pago', () => formulario('Registrar pago', campos, {tipo_pago: cat.tipos_pago.length === 1 ? cat.tipos_pago[0] : '', monto: Number(r.saldo) > 0 ? r.saldo : ''}, fd => { fd.set('id_factura', id); return api('pagos_crear', fd); }, 'Puedes registrar pagos parciales. El tipo permite texto libre, por ejemplo Cheque o Depósito.'), pagos);
        tabla(pagos, r.pagos, [['Tipo', 'tipo_pago'], ['Operación', 'numero_operacion'], ['Fecha', 'fecha_pago'], ['Monto', x => money(x.monto), 'importe'], ['Registrado por', 'registrador']], x => [['Consultar', () => formulario('Consultar pago', campos.filter(c => c.type !== 'file'), x, null)], ...(x.ruta_comprobante ? [['Comprobante', () => descargar(r.id_factura, x.id_pago)]] : [])]);
    }
    root.replaceChildren(); const inicial = nodo('p', 'Cargando…', 'mensaje'); inicial.dataset.mensaje = ''; inicial.setAttribute('role', 'status'); root.append(inicial);
    operar(async () => {
        if (!/^[1-9]\d*$/.test(pid || '') || (vista !== 'proyecto' && !/^[1-9]\d*$/.test(id || ''))) throw new Error('Identificador inválido. Vuelve a Proyectos.');
        cat = await api('catalogos'); await cargar();
    });
})();
