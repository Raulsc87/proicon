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
        const shots=fs.mkdtempSync(path.join(os.tmpdir(),'proicon-diseno-capturas-'));
        const targets=['index.html','registro.html','principal.html','clientes.html','proveedores.html','materiales.html','proyectos.html','presupuestos_resumen.html','empleados_proyectos.html','avance_proyectos.html','proyecto_detalle.html?id='+ids.id_proyecto,'solicitud_detalle.html?proyecto='+ids.id_proyecto+'&id='+ids.id_solicitud,'compra_detalle.html?proyecto='+ids.id_proyecto+'&id='+ids.id_compra,'factura_detalle.html?proyecto='+ids.id_proyecto+'&id='+ids.id_factura];
        assert(ids.hashes_reconocidos,'Contraseñas con hash conservadas');
        for(const role of ['ADMINISTRADOR','SECRETARIA']){
            const fixture=spawnSync('php',[helper,role],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(fixture.status,0);
            for(const width of [1440,1024,768,390]){
                await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
                for(const target of targets){
                    await call('Page.navigate',{url:'http://localhost:8000/'+target});
                    await wait('location.pathname==='+JSON.stringify('/'+target.split('?')[0])+' && document.readyState==="complete"');
                    if(!['index.html','registro.html'].includes(target))await wait('document.documentElement.classList.contains("acceso-listo")');
                    await sleep(180);
                    assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false,role+' '+width+' '+target+' sin desbordamiento');
                    assert(await evaluate('document.querySelector(".marca img")?.naturalWidth>0'),'Logo visible');
                    if(!['index.html','registro.html'].includes(target))assert.equal(await evaluate('document.querySelector("#rolUsuario").textContent'),role);
                    if(role==='SECRETARIA'&&target==='proveedores.html')assert.equal(await evaluate('document.querySelector("#nuevo").getClientRects().length'),0);
                    if(role==='ADMINISTRADOR'&&target==='proveedores.html')assert(await evaluate('document.querySelector("#nuevo").getClientRects().length>0'));
                    if(role==='SECRETARIA'&&target.startsWith('factura_detalle'))assert.equal(await evaluate('document.querySelector("main h1").textContent'),'Acceso no autorizado');
                    if(target==='registro.html')assert(await evaluate('document.getElementById("formularioRegistro").hidden'));
                    if(role==='ADMINISTRADOR'&&[1440,390].includes(width)&&['index.html','principal.html','proyectos.html'].includes(target)){
                        const capture=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(shots,width+'-'+target+'.png'),Buffer.from(capture.data,'base64'));
                    }
                }
                console.log('OK: 14 paginas, '+role+', '+width+'px; logo, permisos y sin desbordamiento');
            }
        }
        const after=spawnSync('php',[helper],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(after.status,0);assert.deepEqual(JSON.parse(after.stdout).tablas,ids.tablas,'Base de datos intacta');
        for (const href of ['clientes.html','proyectos.html','presupuestos_resumen.html','materiales.html','empleados_proyectos.html','avance_proyectos.html']) {
            await call('Page.navigate',{url:'http://localhost:8000/principal.html'});
            await wait('location.pathname==="/principal.html" && document.documentElement.classList.contains("acceso-listo")');
            await evaluate('document.querySelector('+JSON.stringify('a.tarjeta[href="'+href+'"]')+').click()');
            await wait('location.pathname==='+JSON.stringify('/'+href)+' && document.documentElement.classList.contains("acceso-listo")');
        }
        console.log('OK: las seis tarjetas conservan su navegacion');
        await evaluate('document.getElementById("botonCerrarSesion").click()');await wait('location.pathname==="/index.html"');
        await call('Page.navigate',{url:'http://localhost:8000/proyectos.html'});await wait('location.pathname==="/index.html"');
        await evaluate('document.getElementById("usuario").value="USUARIO_INEXISTENTE_PRUEBA_VISUAL";document.getElementById("contrasena").value="entrada_invalida";document.querySelector("#formularioLogin").requestSubmit()');
        await wait('document.querySelector("#mensaje").textContent.includes("incorrectos")');
        console.log('OK: acceso sin sesion y mensaje de login; PostgreSQL intacto');
        console.log('Capturas: '+shots);
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
