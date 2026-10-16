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

        let referencia;
        for(const rol of ['ADMINISTRADOR','SECRETARIA','ENCARGADO_OBRA']) {
            const fixture=spawnSync('php',[helper,rol],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(fixture.status,0);
            for(const width of [1440,1024,768,390]) {
                await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
                await call('Page.navigate',{url:'http://localhost:8000/principal.html'});
                await wait('document.getElementById("indicadorProyectos")?.textContent!=="—" && document.getElementById("dashboardDatos")?.getAttribute("aria-busy")==="false"');
                const d=await evaluate('(async()=> (await (await fetch("api/dashboard.php")).json()).datos)()');
                assert(d&&Number.isFinite(d.indicadores.avance_general));
                if(!referencia)referencia=d;
                assert.deepEqual(d.indicadores,referencia.indicadores);
                assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
                assert.equal(await evaluate('document.getElementById("indicadorProyectos").textContent'),String(d.indicadores.proyectos_activos));
                assert.equal(await evaluate('document.querySelectorAll("#filasDashboard tr").length'),d.proyectos.length);
                assert.equal(await evaluate('document.getElementById("riesgosDashboard").hidden'),d.riesgos.length===0);
                assert.equal(await evaluate(`[...document.querySelectorAll('.menu a')].some(a=>a.textContent==='Usuarios'&&a.getClientRects().length>0)`),rol==='ADMINISTRADOR');
                assert.equal(await evaluate(`document.querySelector('.tarjeta[href="materiales.html"]').getClientRects().length>0`),true);
                if(width===1440) {
                    const comprobacion=await evaluate(`(async()=>{
                        const proyectos=(await (await fetch('api/proyectos_listar.php')).json()).datos;
                        const avances=[];const importes=[];
                        for(const p of proyectos){
                            const a=(await (await fetch('api/avances.php?accion=listar&id_proyecto='+p.id_proyecto)).json()).datos.resumen;
                            avances.push({id:p.id_proyecto,estado:p.estado,porcentaje:Number(a.porcentaje||0)});
                            const b=(await (await fetch('api/proyectos_presupuestos_listar.php?id_proyecto='+p.id_proyecto)).json()).datos.filter(b=>b.estado==='APROBADO');
                            const ultima=Math.max(...b.map(b=>Number(b.version)));const candidatos=b.filter(b=>Number(b.version)===ultima);
                            if(candidatos.length===1)importes.push(candidatos[0].total);
                        }
                        const clientes=(await (await fetch('api/clientes_listar.php')).json()).datos.length;
                        const empleados=(await (await fetch('api/empleados_listar.php?estado=ACTIVO')).json()).datos.length;
                        return {avances,importes,clientes,empleados};
                    })()`);
                    assert.equal(d.indicadores.clientes,comprobacion.clientes);assert.equal(d.indicadores.empleados_activos,comprobacion.empleados);
                    const activos=comprobacion.avances.filter(a=>a.estado==='EN_PROCESO');assert.equal(d.indicadores.proyectos_activos,activos.length);
                    assert.equal(d.indicadores.avance_general,activos.length?Math.round(activos.reduce((s,a)=>s+a.porcentaje,0)/activos.length*100)/100:0);
                    for(const p of d.proyectos)assert.equal(p.avance_real,comprobacion.avances.find(a=>a.id==p.id_proyecto).porcentaje);
                    assert.equal(Math.round(Number(d.indicadores.presupuestado)*100),Math.round(comprobacion.importes.reduce((s,v)=>s+Number(v),0)*100));
                    assert.equal(await evaluate(`(async()=> (await fetch('api/usuarios_listar.php')).status)()`),rol==='ADMINISTRADOR'?200:403);
                }
                if(rol==='ADMINISTRADOR'&&[1440,390].includes(width)) {
                    const c=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(os.tmpdir(),'proicon-dashboard-'+width+'.png'),Buffer.from(c.data,'base64'));
                }
                console.log('OK: '+rol+' '+width+'px, indicadores reales, permisos y responsive');
            }
            for(const href of ['clientes.html','proyectos.html','presupuestos_resumen.html','materiales.html','empleados_proyectos.html','avance_proyectos.html']) {
                await call('Page.navigate',{url:'http://localhost:8000/principal.html'});await wait('document.getElementById("dashboardDatos")?.getAttribute("aria-busy")==="false"');
                await evaluate('document.querySelector('+JSON.stringify('a.tarjeta[href="'+href+'"]')+').click()');
                await wait('location.pathname==='+JSON.stringify('/'+href)+' && document.documentElement.classList.contains("acceso-listo")');
            }
            console.log('OK: '+rol+' conserva los seis accesos del panel');
        }
        await evaluate('document.getElementById("botonCerrarSesion").click()');await wait('location.pathname==="/index.html"');
        assert.equal(await evaluate('(async()=> (await fetch("api/dashboard.php")).status)()'),401);
        await evaluate('document.getElementById("usuario").value="DASHBOARD_USUARIO_INEXISTENTE";document.getElementById("contrasena").value="invalida";document.getElementById("formularioLogin").requestSubmit()');
        await wait('document.getElementById("mensaje").textContent.includes("incorrectos")');
        const after=spawnSync('php',[helper],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(after.status,0);assert.deepEqual(JSON.parse(after.stdout).tablas,ids.tablas);
        assert.deepEqual(errors,[]);console.log('OK: logout, login inválido, endpoint 401, consola sin excepciones y todas las tablas intactas');
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
