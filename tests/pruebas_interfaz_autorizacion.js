// Node nativo + Chrome DevTools; sin paquetes ni cambios de datos.
const {spawn, spawnSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
    const sid = crypto.randomBytes(24).toString('hex');
    const helper = path.join(__dirname, 'pruebas_interfaz_adquisiciones.php');
    const inicio = spawnSync('php', [helper], {input: sid, encoding: 'utf8', windowsHide: true});
    if (inicio.status !== 0) throw new Error('No se pudo preparar sesión de interfaz.');
    const ids = JSON.parse(inicio.stdout);
    const perfil = fs.mkdtempSync(path.join(os.tmpdir(), 'proicon-ui-'));
    const executable = ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(f => fs.existsSync(f));
    assert(executable, 'Navegador local disponible');
    const browser = spawn(executable, ['--headless=new', '--remote-debugging-port=0', '--user-data-dir=' + perfil, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', 'about:blank'], {windowsHide: true, stdio: 'ignore'});
    let ws;
    try {
        const active = path.join(perfil, 'DevToolsActivePort');
        for (let i = 0; i < 100 && !fs.existsSync(active); i++) await sleep(100);
        const port = fs.readFileSync(active, 'utf8').split('\n')[0];
        const pages = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
        ws = new WebSocket(pages.find(p => p.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
        let seq = 0; const pending = new Map(), errors = [];
        ws.onmessage = e => {
            const m = JSON.parse(e.data);
            if (m.id) { const p = pending.get(m.id); if (p) { pending.delete(m.id); m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result); } }
            if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text);
        };
        const call = (method, params = {}) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); });
        const evaluate = async expression => { const r = await call('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true}); if (r.exceptionDetails) throw new Error('Error al evaluar interfaz'); return r.result.value; };
        const wait = async expression => { for (let i = 0; i < 100; i++) { if (await evaluate(expression)) return; await sleep(100); } throw new Error('La interfaz no completó la carga esperada: ' + expression); };
        await call('Runtime.enable'); await call('Network.enable');
        await call('Network.setCookie', {name: 'PHPSESSID', value: sid, url: 'http://localhost:8000'});

        if (process.argv.some(a=>a.startsWith('--fotos'))) {
            const gestionar=!process.argv.includes('--fotos-lectura');
            for(const width of [1440,390]) {
                await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
                await call('Page.navigate',{url:'http://localhost:8000/proyecto_detalle.html?id='+ids.id_proyecto});
                await wait('document.querySelector("#centroProyecto")?.hidden===false && document.querySelector("#evidenciaFotografica button")?.disabled===false');
                assert.equal(await evaluate('document.getElementById("actividadesProyecto").getClientRects().length'),0);
                await evaluate('document.querySelector("#evidenciaFotografica details").open=true');
                await wait('document.querySelectorAll(".foto-avance").length>0');
                const buttons=await evaluate('Array.from(document.querySelectorAll(".foto-avance:first-child button")).filter(e=>e.getClientRects().length).map(e=>e.textContent)');
                assert.deepEqual(buttons,gestionar?['Consultar','Editar','Eliminar']:['Consultar']);
                if(gestionar) {
                    await evaluate('Array.from(document.querySelectorAll(".foto-avance button")).find(b=>b.textContent==="Editar").click()');
                    await wait('!!document.querySelector("dialog[open] textarea")');
                    assert.equal(await evaluate('document.activeElement.name'),'descripcion');
                    await evaluate('document.querySelector("dialog[open]").close()');
                }
                console.log('OK: permisos independientes de fotografias y actividades a '+width+'px');
            }
        } else {
        for(const width of [1440,390]){
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            await call('Page.navigate',{url:'http://localhost:8000/principal.html'});
            await wait('document.documentElement.classList.contains("acceso-listo")');
            const visible='e=>!!e.getClientRects().length';
            assert.deepEqual(await evaluate('Array.from(document.querySelectorAll("a.tarjeta")).filter('+visible+').map(a=>a.getAttribute("href"))'),['proyectos.html']);
            assert.deepEqual(await evaluate('Array.from(document.querySelectorAll(".menu nav a")).filter('+visible+').map(a=>a.getAttribute("href"))'),['principal.html','proveedores.html','proyectos.html']);
            await call('Page.navigate',{url:'http://localhost:8000/proveedores.html'});
            await wait('location.pathname==="/proveedores.html" && document.querySelectorAll("#filas tr").length>0');
            assert.equal(await evaluate('document.getElementById("nuevo").getClientRects().length'),0);
            assert(await evaluate('Array.from(document.querySelectorAll("#filas button")).filter('+visible+').every(b=>b.textContent==="Ver")'));
            await evaluate('document.querySelector("#filas button").click()');
            await wait('!document.getElementById("editor").hidden');
            assert(await evaluate('document.querySelector("#formulario input[name=nombre]").disabled'));
            await call('Page.navigate',{url:'http://localhost:8000/clientes.html'});
            await wait('document.querySelector("main h1")?.textContent==="Acceso no autorizado"');
            assert.equal(await evaluate('document.querySelectorAll("#formulario").length'),0);
            await call('Page.navigate',{url:'http://localhost:8000/proyecto_detalle.html?id='+ids.id_proyecto});
            await wait('document.querySelector("#centroProyecto")?.hidden===false');
            assert.equal(await evaluate('document.getElementById("presupuestos").getClientRects().length'),0);
            assert.equal(await evaluate('document.getElementById("empleados").getClientRects().length'),0);
            assert.equal(await evaluate('document.querySelectorAll("#actividadesProyecto").length'),0);
            await call('Page.navigate',{url:'http://localhost:8000/factura_detalle.html?proyecto='+ids.id_proyecto+'&id='+ids.id_factura});
            await wait('location.pathname==="/factura_detalle.html" && document.querySelector("#adquisiciones .resumen")');
            assert(!await evaluate('Array.from(document.querySelectorAll("#adquisiciones section")).filter('+visible+').some(s=>s.querySelector("h2")?.textContent==="Pagos")'));
            assert(!await evaluate('document.querySelector("#adquisiciones .resumen").textContent.includes("Total pagado")'));
            assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
            console.log('OK: consulta sin gestion, menu, tarjetas, URL directa y pagos ocultos a '+width+'px');
        }
        }
        assert.deepEqual(errors, [], 'Sin excepciones JavaScript del navegador');
        console.log('OK: navegador sin excepciones JavaScript');
        await call('Browser.close');
    } finally {
        if (ws) ws.close(); browser.kill();
        spawnSync('php', [helper, 'cerrar'], {input: sid, encoding: 'utf8', windowsHide: true});
        await sleep(800);
        // Sólo el directorio aleatorio de esta ejecución, verificado bajo el temporal del sistema.
        const resolved = path.resolve(perfil), parent = path.resolve(os.tmpdir());
        assert(path.dirname(resolved) === parent && path.basename(resolved).startsWith('proicon-ui-'));
        fs.rmSync(resolved, {recursive: true, force: true, maxRetries: 5, retryDelay: 200});
    }
})().catch(e => { console.error('FALLO: ' + e.message); process.exitCode = 1; });
