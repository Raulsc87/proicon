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
    const helper = path.join(__dirname, 'pruebas_diseno.php');
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

        await call('Page.enable');

        for(const width of [1440,390]){
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            await call('Page.navigate',{url:'http://localhost:8000/usuarios.html'});
            await wait('document.querySelector("#filas tr") && document.querySelector("#estadoLista").textContent.includes("usuarios.")');
            assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
            if (process.argv.includes('--capturas')) {
                const captura = await call('Page.captureScreenshot', {format:'png',captureBeyondViewport:false});
                const destino = path.join(os.tmpdir(),'proicon-usuarios-'+width+'.png');
                fs.writeFileSync(destino,Buffer.from(captura.data,'base64'));console.log('Captura: '+destino);
            }
            assert(await evaluate(`document.querySelector('.menu a[href="usuarios.html"]').getAttribute('aria-current')==='page'`));
            await evaluate('document.querySelector("[data-accion=editar]").click()');
            await wait('!document.getElementById("editor").hidden && document.activeElement.id==="nombreCuenta"');
            assert.equal(await evaluate('document.getElementById("tituloEditor").textContent'),'Editar usuario');
            assert(await evaluate('document.getElementById("empleado").disabled && document.getElementById("claveInicial").hidden'));
            await sleep(700);
            assert(await evaluate('document.getElementById("editor").getBoundingClientRect().top>=0 && document.getElementById("editor").getBoundingClientRect().top<innerHeight'));
            await evaluate('document.getElementById("cancelar").click()');
            assert(await evaluate('document.getElementById("editor").hidden'));
            await evaluate('document.getElementById("nuevo").click()');
            await wait('!document.getElementById("editor").hidden && document.activeElement.id==="empleado"');
            assert(await evaluate('!document.getElementById("empleado").disabled && document.getElementById("clave").required'));
            assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
            await evaluate('document.getElementById("cancelar").click()');
            await evaluate('document.querySelector("[data-accion=clave]").click()');
            await wait('!document.getElementById("editorClave").hidden && document.activeElement.id==="nuevaClave"');
            await evaluate('document.getElementById("cancelarClave").click()');
            assert(await evaluate('document.getElementById("editorClave").hidden && document.getElementById("nuevaClave").value===""'));
            await evaluate('document.querySelector("[data-accion=consultar]").click()');
            await wait('!document.getElementById("consulta").hidden');
            assert(await evaluate('document.getElementById("datosConsulta").textContent.length>0'));
            await evaluate('document.getElementById("cerrarConsulta").click()');
            console.log('OK: usuarios '+width+'px, navegacion, formularios, foco, scroll, Cancelar y consulta');
        }
        const fixture=spawnSync('php',[helper,'SECRETARIA'],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(fixture.status,0);
        await call('Page.navigate',{url:'http://localhost:8000/principal.html'});
        await wait('document.getElementById("rolUsuario")?.textContent==="SECRETARIA"');
        assert.equal(await evaluate(`document.querySelector('.menu a[href="usuarios.html"]')`),null);
        assert.equal(await evaluate('(async()=> (await fetch("usuarios.html")).status)()'),403);
        assert.equal(await evaluate('(async()=> (await fetch("USUARIOS.HTML")).status)()'),403);
        await call('Page.navigate',{url:'http://localhost:8000/usuarios.html'});
        await wait('document.querySelector("h1")?.textContent==="Acceso no autorizado"');
        console.log('OK: SECRETARIA sin acceso en menu ni URL directa (403), incluidas mayusculas');
        const after=spawnSync('php',[helper],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(after.status,0);assert.deepEqual(JSON.parse(after.stdout).tablas,ids.tablas);
        assert.deepEqual(errors,[]);console.log('OK: sin excepciones JavaScript y datos intactos');
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
