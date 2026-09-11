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
        if (process.argv.includes('--edicion')) {
            await call('Page.navigate', {url: 'http://localhost:8000/proyecto_detalle.html?id=' + ids.id_proyecto});
            await wait('document.querySelector("#presupuestos button") && document.querySelector("#empleados button") && document.querySelector("#actividadesProyecto tbody button") && !document.getElementById("centroProyecto").hidden');
            for (const [lista, editor, campo] of [['empleados','empleado','funcion_en_proyecto'], ['presupuestos','presupuesto','version']]) {
                await evaluate(`window.scrollTo(0, document.body.scrollHeight); Array.from(document.querySelectorAll('#${lista} button')).find(b => b.textContent.startsWith('Editar')).click()`);
                await wait(`!document.getElementById('${editor}Editor').hidden && document.activeElement.id === '${campo}'`);
                await sleep(700);
                assert(await evaluate(`document.getElementById('${editor}Editor').getBoundingClientRect().top >= -5 && document.getElementById('${editor}Editor').getBoundingClientRect().top < innerHeight`), 'Formulario visible tras scroll');
                assert(await evaluate(`document.querySelector('#${editor}Form [type=submit]').textContent === 'Guardar cambios'`));
                await evaluate(`document.querySelector('#${editor}Form [data-cerrar]').click()`);
                assert(await evaluate(`document.getElementById('${editor}Editor').hidden`));
                console.log('OK: Editar ' + lista + ' desplaza, enfoca y permite Cancelar');
            }
            await evaluate('document.getElementById("nuevoPresupuesto").click()');
            assert(await evaluate('document.getElementById("estado_presupuesto").value === "BORRADOR"'));
            await evaluate('document.querySelector("#presupuestoForm [data-cerrar]").click()');
            await evaluate('Array.from(document.querySelectorAll("#actividadesProyecto tbody button")).find(b=>b.textContent==="Editar").click()');
            await wait('!!document.querySelector(".avances dialog[open]")');
            assert(await evaluate('document.activeElement.name === "nombre" && document.querySelector(".avances dialog[open] [type=submit]").textContent === "Guardar cambios"'));
            await evaluate('Array.from(document.querySelectorAll(".avances dialog[open] button")).find(b=>b.textContent==="Cancelar").click()');
            await wait('!document.querySelector(".avances dialog[open]")');
            await evaluate('document.querySelector("#evidenciaFotografica details").open=true');
            await wait('!!document.querySelector(".foto-avance")');
            const cantidadFotos = await evaluate('document.querySelectorAll(".foto-avance").length');
            for (let i = 0; i < cantidadFotos; i++) { await evaluate(`document.querySelectorAll('.foto-avance')[${i}].scrollIntoView()`); await sleep(150); }
            await wait('Array.from(document.querySelectorAll(".foto-avance")).some(f=>f.textContent.includes("Imagen no disponible"))');
            assert(await evaluate('Array.from(document.querySelectorAll(".foto-avance")).find(f=>f.textContent.includes("Imagen no disponible")).querySelector("img").hidden'));
            await evaluate('Array.from(document.querySelectorAll(".foto-avance")).find(f=>f.textContent.includes("Imagen no disponible")).querySelector("button").click()');
            await wait('document.querySelector(".visor-foto")?.textContent.includes("Imagen no disponible")');
            assert(await evaluate('document.querySelector(".visor-foto img").hidden'));
            await evaluate('document.querySelector(".visor-foto").close()');
            console.log('OK: BORRADOR inicial, edición de actividad y fotografía faltante sin imagen rota');
            for (const pagina of ['clientes', 'proveedores', 'materiales', 'proyectos']) {
                await call('Page.navigate', {url: 'http://localhost:8000/' + pagina + '.html'});
                await wait('!!document.querySelector("#filas button")');
                await evaluate('Array.from(document.querySelectorAll("#filas button")).find(b=>b.textContent==="Editar").click()');
                const form = pagina === 'proyectos' ? 'proyectoForm' : 'formulario';
                await wait(`document.querySelector('#${form} [type=submit]')?.textContent === 'Guardar cambios'`);
                assert(await evaluate(`document.activeElement === document.querySelector('#${form} input:not(:disabled)')`));
                await evaluate(pagina === 'proyectos' ? 'document.querySelector("#proyectoForm [data-cerrar]").click()' : 'document.getElementById("cancelar").click()');
                console.log('OK: edición y Cancelar en ' + pagina);
            }
        }
        const routes = [
            ['proyecto_detalle.html?id=' + ids.id_proyecto, 'Solicitudes de materiales', '+ Nueva solicitud'],
            ['solicitud_detalle.html?proyecto=' + ids.id_proyecto + '&id=' + ids.id_solicitud, 'Materiales solicitados', 'Consultar'],
            ['compra_detalle.html?proyecto=' + ids.id_proyecto + '&id=' + ids.id_compra, 'Materiales comprados', '+ Registrar factura'],
            ['factura_detalle.html?proyecto=' + ids.id_proyecto + '&id=' + ids.id_factura, 'Pagos', '+ Registrar pago']
        ];
        for (const width of [1440, 390]) {
            await call('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: false});
            for (const [route, expected, button] of routes) {
                await call('Page.navigate', {url: 'http://localhost:8000/' + route});
                await wait(`document.querySelector('#adquisiciones')?.textContent.includes(${JSON.stringify(expected)}) && !document.querySelector('#adquisiciones [data-mensaje]')?.textContent`);
                await sleep(100);
                const overflow = await evaluate('document.documentElement.scrollWidth > window.innerWidth + 1');
                assert.equal(overflow, false, 'Sin desbordamiento de página');
                assert(await evaluate(`Array.from(document.querySelectorAll('#adquisiciones .tabla-contenedor')).every(e => getComputedStyle(e).overflowX === 'auto')`));
                await evaluate(`Array.from(document.querySelectorAll('#adquisiciones button')).find(b => b.textContent === ${JSON.stringify(button)}).click()`);
                await wait('!!document.querySelector("#adquisiciones dialog[open]")');
                assert(await evaluate('document.querySelector("#adquisiciones dialog[open]").getBoundingClientRect().width <= window.innerWidth'));
                if (button === 'Consultar') assert(await evaluate('!!document.querySelector("dialog textarea[name=observaciones]")'));
                if (button === '+ Registrar factura') {
                    await evaluate('document.querySelector("dialog input[name=monto_total]").value = "1"; document.querySelector("dialog input[name=monto_total]").dispatchEvent(new Event("input"))');
                    assert(await evaluate('document.querySelector("dialog").textContent.includes("El monto difiere")'));
                }
                await evaluate('document.querySelector("dialog[open]").close()');
                console.log('OK: interfaz ' + route.split('?')[0] + ' a ' + width + 'px, formulario y scroll interno');
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
