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


        for(const role of ['ADMINISTRADOR','SECRETARIA','ENCARGADO_OBRA']) {
            const fixture=spawnSync('php',[helper,role],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(fixture.status,0);
            for(const width of [1440,390]) {
                await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
                await call('Page.navigate',{url:'http://localhost:8000/empleados.html'});
                await wait('document.querySelector("#estadoLista")?.textContent.includes("registros.")');
                assert(await evaluate(`document.querySelector('.menu a[href="empleados.html"]')?.getAttribute('aria-current')==='page'`));
                if (width === 1440) {
                    const lecturas = await evaluate(`(async()=>{
                        const lista=await fetch('api/empleados_listar.php');const datos=await lista.json();
                        const consulta=await fetch('api/empleados_obtener.php?id='+datos.datos[0].id_empleado);
                        return [lista.status,consulta.status];
                    })()`);
                    assert.deepEqual(lecturas,[200,200]);
                    for (const accion of ['crear','editar','estado']) {
                        // Entrada inválida deliberada: comprueba autorización sin escribir datos.
                        const status=await evaluate(`(async()=> (await fetch('api/empleados_${accion}.php', {method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})).status)()`);
                        assert.equal(status,role==='ENCARGADO_OBRA'?403:400,role+' '+accion);
                    }
                    console.log('OK: '+role+' lectura 200; gestión '+(role==='ENCARGADO_OBRA'?'403':'autorizada, entrada inválida 400')+'; sin escrituras');
                }
                assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
                assert.equal(await evaluate('document.getElementById("nuevo").hidden'),role==='ENCARGADO_OBRA');
                assert.equal(await evaluate('[...document.querySelectorAll("#filas button")].some(b=>b.textContent==="Editar")'),role!=='ENCARGADO_OBRA');
                await evaluate('document.querySelector("#filas button").click()');
                await wait('!document.getElementById("editor").hidden');
                assert(await evaluate('document.getElementById("nombres").disabled'));
                await evaluate('document.getElementById("cancelar").click()');
                if(role!=='ENCARGADO_OBRA') {
                    await evaluate('[...document.querySelectorAll("#filas button")].find(b=>b.textContent==="Editar").click()');
                    await wait('document.activeElement.id==="nombres"');await sleep(700);
                    assert.equal(await evaluate('document.getElementById("tituloEditor").textContent'),'Editar empleado');
                    assert.equal(await evaluate('document.getElementById("guardar").textContent'),'Guardar cambios');
                    const top=await evaluate('document.getElementById("editor").getBoundingClientRect().top');assert(top>=-1&&top<1000,"Formulario visible, top="+top);
                    await evaluate('document.getElementById("cancelar").click()');
                    assert(await evaluate('document.getElementById("editor").hidden'));
                    await evaluate('document.getElementById("nuevo").click()');
                    assert(await evaluate('document.getElementById("nombres").value==="" && !document.getElementById("editor").hidden'));
                    assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
                    await evaluate('document.getElementById("cancelar").click()');
                }
                console.log('OK: '+role+' '+width+'px, consulta, permisos, formularios y sin desbordamiento');
            }
        }
        const fixture=spawnSync('php',[helper],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(fixture.status,0);
        await call('Page.navigate',{url:'http://localhost:8000/proyecto_detalle.html?id='+ids.id_proyecto});
        await wait('document.querySelector("#id_empleado option[value]")');
        assert(await evaluate('(async()=>{const c=await (await fetch("api/proyectos_catalogos.php")).json();return [...document.querySelectorAll("#id_empleado option")].filter(o=>o.value&&!o.disabled).every(o=>c.datos.empleados.find(e=>String(e.id_empleado)===o.value)?.estado==="ACTIVO")})()'));
        console.log('OK: proyecto ofrece s?lo empleados ACTIVO como nuevas opciones disponibles');
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
