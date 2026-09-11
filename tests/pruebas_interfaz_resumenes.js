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
    const helper = path.join(__dirname, 'pruebas_resumenes.php');
    const inicio = spawnSync('php', [helper], {input: sid, encoding: 'utf8', windowsHide: true});
    if (inicio.status !== 0) throw new Error('No se pudo preparar sesión de interfaz.');
    const expected = JSON.parse(inicio.stdout);
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
        let seq = 0; const pending = new Map(), errors = [], requests = [];
        ws.onmessage = e => {
            const m = JSON.parse(e.data);
            if (m.id) { const p = pending.get(m.id); if (p) { pending.delete(m.id); m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result); } }
            if (m.method === 'Network.requestWillBeSent') requests.push(m.params.request);
            if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text);
        };
        const call = (method, params = {}) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); });
        const evaluate = async expression => { const r = await call('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true}); if (r.exceptionDetails) throw new Error('Error al evaluar interfaz'); return r.result.value; };
        const wait = async expression => { for (let i = 0; i < 100; i++) { if (await evaluate(expression)) return; await sleep(100); } throw new Error('La interfaz no completó la carga esperada: ' + expression); };
        await call('Runtime.enable'); await call('Network.enable'); await call('Page.enable');
        await call('Network.setCookie', {name: 'PHPSESSID', value: sid, url: 'http://localhost:8000'});

        const views = [['presupuestos_resumen.html','presupuestos'],['empleados_proyectos.html','empleados'],['avance_proyectos.html','avance']];
        for (const endpoint of ['api/proyectos_listar.php','api/proyectos_presupuestos_listar.php?id_proyecto=1','api/proyectos_empleados_listar.php?id_proyecto=1','api/avances.php?accion=listar&id_proyecto=1']) {
            assert.equal((await fetch('http://localhost:8000/'+endpoint)).status,401,'Endpoint protegido');
        }
        for (const width of [1440,390]) {
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            for (const [page,type] of views) {
                await call('Page.navigate',{url:'http://localhost:8000/'+page});
                await wait('location.pathname==='+JSON.stringify('/'+page));
                await wait('document.querySelector("#tablaResumen")?.getAttribute("aria-busy")==="false"');
                const rows=await evaluate('Array.from(document.querySelectorAll("#filas tr"),tr=>({cells:Array.from(tr.cells,c=>c.textContent),links:Array.from(tr.querySelectorAll("a"),a=>a.href),progress:tr.querySelector("progress")?.value ?? null}))');
                assert.equal(rows.length,expected[type].length,type+' incluye todos los registros');
                for (let i=0;i<rows.length;i++) {
                    const r=rows[i],e=expected[type][i];
                    assert.equal(new URL(r.links[0]).searchParams.get('id'),String(e.id_proyecto));
                    if (type==='presupuestos') {
                        assert.deepEqual(r.cells.slice(1,4),[String(e.version),e.fecha_registro,e.estado]);
                        assert.equal(r.cells[4],'Q\u00a0'+Number(e.total).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}));
                        assert.equal(new URL(r.links[1]).searchParams.get('presupuesto'),String(e.id_presupuesto));
                    } else if(type==='empleados') {
                        assert.deepEqual(r.cells.slice(1,5),[e.nombre,e.funcion_en_proyecto ?? '\u2014',e.fecha_asignacion,e.estado]);
                    } else {
                        assert.deepEqual(r.cells.slice(1,4),[e.estado,String(e.total),String(e.finalizadas)]);
                        const percent=Number(e.total)===0?null:Math.round(Number(e.finalizadas)/Number(e.total)*1000)/10;
                        assert.equal(r.progress,percent);
                        if(percent===null) assert.equal(r.cells[4],'Sin actividades');
                    }
                }
                assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
                assert.deepEqual(await evaluate('Array.from(document.querySelectorAll(".menu nav a"),a=>a.getAttribute("href"))'),['principal.html','clientes.html','proveedores.html','materiales.html','proyectos.html']);
                if(rows.length) {
                    const link=type==='presupuestos'?1:0;
                    const destination=rows[0].links[link];
                    await evaluate('document.querySelectorAll("#filas tr:first-child a")['+link+'].click()');
                    await wait('location.href==='+JSON.stringify(destination));
                    await wait('document.querySelector("#centroProyecto")?.hidden===false');
                    if(type==='presupuestos'){
                        await wait('document.querySelector("#detallePresupuesto")?.hidden===false');
                        assert(await evaluate('document.querySelector("#tituloPresupuesto").textContent.endsWith('+JSON.stringify(String(expected[type][0].version))+')'));
                    }
                }
                console.log('OK: '+type+' coincide con PostgreSQL, enlaces y tabla a '+width+'px');
            }
        }
        await call('Page.navigate',{url:'http://localhost:8000/principal.html'});
        await wait('document.querySelectorAll("a.tarjeta").length===6');
        assert.deepEqual(await evaluate('Array.from(document.querySelectorAll("a.tarjeta"),a=>a.getAttribute("href"))'),['clientes.html','proyectos.html','presupuestos_resumen.html','materiales.html','empleados_proyectos.html','avance_proyectos.html']);
        // Casos de interfaz simulados: no se alteran registros para generar vacios o fallos.
        for (const failure of [false,true]) {
            const source='const realFetch=window.fetch;window.fetch=(url,options)=>String(url).startsWith("api/proyectos_listar.php")?Promise.resolve(new Response(JSON.stringify('+JSON.stringify(failure?{ok:false,mensaje:'Fallo temporal de prueba'}:{ok:true,datos:[]})+'),{status:'+(failure?503:200)+'})):realFetch(url,options);';
            const injected=await call('Page.addScriptToEvaluateOnNewDocument',{source});
            for(const [page] of views){
                await call('Page.navigate',{url:'http://localhost:8000/'+page});
                await wait('location.pathname==='+JSON.stringify('/'+page));
                await wait('document.querySelector("#tablaResumen")?.getAttribute("aria-busy")==="false"');
                assert.equal(await evaluate('document.querySelectorAll("#filas tr").length'),0);
                assert(await evaluate('document.querySelector("#filasEstado").textContent.includes('+JSON.stringify(failure?'No se pudo':'No hay registros')+')'));
                assert.equal(await evaluate('document.querySelector("#actualizarResumen").disabled'),false);
                await evaluate('window.fetch=realFetch;document.querySelector("#actualizarResumen").click()');
                await wait('document.querySelector("#tablaResumen")?.getAttribute("aria-busy")==="false"');
                assert.equal(await evaluate('document.querySelectorAll("#filas tr").length'),expected[views.find(v=>v[0]===page)[1]].length);
            }
            await call('Page.removeScriptToEvaluateOnNewDocument',{identifier:injected.identifier});
        }
        await call('Network.clearBrowserCookies');
        await call('Page.navigate',{url:'http://localhost:8000/presupuestos_resumen.html'});
        await wait('location.pathname==="/index.html"');
        assert(!requests.some(r=>r.method!=='GET'),'Las vistas solo realizan lecturas');
        console.log('OK: panel, vacios, errores, acceso sin sesion; solo GET');
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
