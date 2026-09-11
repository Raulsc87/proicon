const Proicon = (() => {
    const el = id => document.getElementById(id);
    function informar(texto, error = false) {
        el('mensajeModulo').textContent = texto;
        el('mensajeModulo').className = 'mensaje ' + (error ? 'error' : 'correcto');
    }
    async function api(accion, datos = null, parametros = {}) {
        return solicitar('api/proyectos_' + accion + '.php?' + new URLSearchParams(parametros), datos);
    }
    async function solicitar(url, datos = null) {
        const respuesta = await fetch(url, {
            method: datos ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
            ...(datos ? {headers: {'Content-Type': 'application/json'}, body: JSON.stringify(datos)} : {})
        });
        if (respuesta.status === 401) { location.replace('index.html'); throw new Error('La sesión ha terminado.'); }
        let r;
        try { r = await respuesta.json(); } catch { throw new Error('El servidor no devolvió una respuesta válida.'); }
        if (!respuesta.ok || !r.ok) throw new Error(r.mensaje || 'No se pudo completar la operación.');
        return r.datos;
    }
    let ocupado = false;
    async function operar(fn) {
        if (ocupado) return;
        ocupado = true;
        const botones = [...document.querySelectorAll('main button')].filter(b => !b.disabled);
        botones.forEach(b => { b.disabled = true; });
        try { await fn(); } catch (e) { informar(e.message || 'No se pudo conectar con el servidor.', true); }
        finally { ocupado = false; botones.forEach(b => { b.disabled = false; }); }
    }
    function opciones(id, registros, clave, etiqueta, opcional = false) {
        el(id).replaceChildren(new Option(opcional ? 'Sin material' : 'Selecciona…', ''));
        registros.forEach(r => el(id).add(new Option(clave ? r[etiqueta] : r, clave ? r[clave] : r)));
    }
    function abrir(id, registro = {}, lectura = false) {
        const f = el(id + 'Form');
        f.reset();
        f.querySelector('fieldset').disabled = lectura;
        f.querySelector('[type=submit]').hidden = lectura;
        const edicion = !lectura && el(id + 'Titulo').textContent.startsWith('Editar');
        f.querySelector('[type=submit]').textContent = edicion ? 'Guardar cambios' : 'Guardar';
        f.querySelector('[data-cerrar]').textContent = lectura ? 'Cerrar' : 'Cancelar';
        for (const campo of f.querySelectorAll('[name]')) if (Object.hasOwn(registro, campo.name)) campo.value = registro[campo.name] ?? '';
        el(id + 'Editor').hidden = false;
        requestAnimationFrame(() => {
            const campo = f.querySelector('input:not(:disabled), select:not(:disabled), textarea:not(:disabled)');
            (lectura ? el(id + 'Titulo') : campo || el(id + 'Titulo')).focus({preventScroll: true});
            el(id + 'Editor').scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
        });
    }
    function tabla(id, registros, campos, acciones) {
        el(id).replaceChildren();
        for (const r of registros) {
            const tr = document.createElement('tr');
            for (const campo of campos) {
                const td = document.createElement('td');
                td.textContent = (typeof campo === 'function' ? campo(r) : r[campo]) ?? '—';
                if (td.textContent.startsWith('Q\u00a0')) td.classList.add('importe');
                tr.append(td);
            }
            const td = document.createElement('td'); td.className = 'acciones';
            for (const [texto, accion] of acciones(r)) {
                const b = document.createElement('button'); b.type = 'button'; b.textContent = texto;
                b.addEventListener('click', () => operar(accion)); td.append(b);
            }
            tr.append(td); el(id).append(tr);
        }
        el(id + 'Estado').textContent = registros.length ? registros.length + ' registros.' : 'No se encontraron registros.';
    }
    function enviar(id, fn) { el(id + 'Form').addEventListener('submit', e => { e.preventDefault(); operar(async () => { await fn(Object.fromEntries(new FormData(e.target))); e.target.reset(); }); }); }
    document.querySelectorAll('[data-cerrar]').forEach(b => b.addEventListener('click', () => { if (!ocupado) { el(b.dataset.cerrar).hidden = true; b.closest('form').reset(); } }));
    // Formato decimal exacto: evita convertir los importes NUMERIC a coma flotante.
    function dinero(valor) {
        const [entero, fraccion = ''] = String(valor).split('.');
        const centavos = BigInt(entero) * 100n + BigInt(fraccion.padEnd(3, '0').slice(0, 2)) + (Number(fraccion[2] || 0) >= 5 ? 1n : 0n);
        return 'Q\u00a0' + (centavos / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (centavos % 100n).toString().padStart(2, '0');
    }
    function hoy() { const d = new Date(); return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-'); }
    return {el, informar, api, solicitar, operar, opciones, abrir, tabla, enviar, dinero, hoy};
})();
