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

        await call('Page.navigate',{url:'http://localhost:8000/empleados.html'});
        await wait('document.querySelector("#filtroEstado") && document.documentElement.classList.contains("acceso-listo")');
        const reales=await evaluate(`(async()=>{
            const leer=async q=>(await (await fetch('api/empleados_listar.php?'+q)).json()).datos;
            const todos=await leer('estado=TODOS'), activos=await leer('estado=ACTIVO'), inactivos=await leer('estado=INACTIVO');
            const buscar=todos[0]?.nombres || 'Sin coincidencias';
            const combinados=await leer('estado=ACTIVO&buscar='+encodeURIComponent(buscar));
            const invalido=(await fetch('api/empleados_listar.php?estado=INVALIDO')).status;
            return {todos,activos,inactivos,combinados,buscar,invalido};
        })()`);
        assert(reales.activos.every(e=>e.estado==='ACTIVO'));assert(reales.inactivos.every(e=>e.estado==='INACTIVO'));
        assert.equal(reales.todos.length,reales.activos.length+reales.inactivos.length);
        const esperado=reales.todos.filter(e=>e.estado==='ACTIVO'&&[e.nombres,e.apellidos,e.correo].some(v=>(v||'').toLowerCase().includes(reales.buscar.toLowerCase())));
        assert.deepEqual(reales.combinados,esperado);assert.equal(reales.invalido,400);
        console.log('OK: filtros reales del endpoint y búsqueda combinada, sólo SELECT');

        // Simula exclusivamente los datos de empleados/asignaciones en memoria del navegador.
        // Nunca se envían POST reales ni se modifican tablas durante esta prueba.
        function instalarSimulacion() {
            const nativo=window.fetch.bind(window);
            const personal=[
                {id_empleado:9001,nombres:'Carlos',apellidos:'Activo',correo:'carlos@example.test',telefono:'1234',estado:'ACTIVO'},
                {id_empleado:9002,nombres:'Carlos',apellidos:'Anterior',correo:'anterior@example.test',telefono:'1234',estado:'INACTIVO'},
                {id_empleado:9003,nombres:'Ana',apellidos:'Nueva',correo:'ana@example.test',telefono:'1234',estado:'ACTIVO'}
            ];
            const asignados=[
                {id_empleado:9001,nombre:'Carlos Activo',funcion_en_proyecto:'Albañil',fecha_asignacion:'2026-10-01',estado:'ACTIVO'},
                {id_empleado:9002,nombre:'Carlos Anterior',funcion_en_proyecto:'Supervisor de acabados',fecha_asignacion:'2026-10-01',estado:'INACTIVO'}
            ];
            window.envios=[];window.confirm=()=>true;
            const respuesta=(datos,advertencia=null)=>new Response(JSON.stringify({ok:true,datos,gestionar:true,mensaje:'Prueba en memoria',advertencia}),{headers:{'Content-Type':'application/json'}});
            window.fetch=async (url,op={})=>{
                const u=new URL(url,location.href),nombre=u.pathname.split('/').pop();
                if(nombre==='empleados_listar.php') {
                    const estado=u.searchParams.get('estado')||'TODOS',buscar=(u.searchParams.get('buscar')||'').toLowerCase();
                    return respuesta(personal.filter(e=>(estado==='TODOS'||e.estado===estado)&&[e.nombres,e.apellidos,e.correo].some(v=>v.toLowerCase().includes(buscar))));
                }
                if(nombre==='empleados_estado.php') {
                    const d=JSON.parse(op.body);window.envios.push(d);const e=personal.find(e=>e.id_empleado===d.id);e.estado=d.estado;return respuesta(e,d.estado==='INACTIVO'?'Este empleado tiene una cuenta de usuario activa. Revise Administración de Usuarios si también desea retirar su acceso al sistema.':null);
                }
                if(nombre==='proyectos_catalogos.php') {
                    const r=await (await nativo(url,op)).json();r.datos.empleados=personal.map(e=>({...e,nombre:e.nombres+' '+e.apellidos}));return respuesta(r.datos);
                }
                if(nombre==='proyectos_empleados_listar.php')return respuesta(asignados.map(a=>({...a,estado_global_empleado:personal.find(e=>e.id_empleado==a.id_empleado).estado})));
                if(['proyectos_empleados_crear.php','proyectos_empleados_editar.php'].includes(nombre)) {
                    const d=JSON.parse(op.body);window.envios.push(d);const e=asignados.find(e=>e.id_empleado==d.id_empleado);
                    if(e)Object.assign(e,d);else asignados.push({...d,nombre:'Ana Nueva'});return respuesta(d);
                }
                if(op.method==='POST')throw Error('POST real bloqueado por la prueba');
                return nativo(url,op);
            };
        }
        await call('Page.addScriptToEvaluateOnNewDocument',{source:'('+instalarSimulacion.toString()+')()'});
        for(const width of [1440,390]) {
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            await call('Page.navigate',{url:'http://localhost:8000/empleados.html'});
            await wait('document.querySelectorAll("#filas tr").length===2');
            assert.equal(await evaluate('document.getElementById("filtroEstado").value'),'ACTIVO');
            for(const [estado,cantidad] of [['INACTIVO',1],['TODOS',3],['ACTIVO',2]]) {
                await evaluate(`document.getElementById('filtroEstado').value='${estado}';document.getElementById('filtroEstado').dispatchEvent(new Event('change'))`);
                await wait(`document.querySelectorAll('#filas tr').length===${cantidad}`);
            }
            await evaluate(`document.getElementById('buscar').value='Carlos';document.getElementById('busqueda').requestSubmit()`);
            await wait('document.querySelectorAll("#filas tr").length===1');
            assert(await evaluate('document.querySelector("#filas tr").textContent.includes("CarlosActivo")'));
            await evaluate(`[...document.querySelectorAll('#filas button')].find(b=>b.textContent==='Dar de baja').click()`);
            await wait('document.querySelectorAll("#filas tr").length===0');
            assert.equal(await evaluate('envios.at(-1).estado'),'INACTIVO');
            assert(await evaluate('document.getElementById("mensajeModulo").textContent.includes("cuenta de usuario activa")'));
            await evaluate(`document.getElementById('filtroEstado').value='INACTIVO';document.getElementById('filtroEstado').dispatchEvent(new Event('change'))`);
            await wait('document.querySelectorAll("#filas tr").length===2');
            await evaluate(`[...document.querySelectorAll('#filas button')].find(b=>b.textContent==='Reactivar').click()`);
            await wait('document.querySelectorAll("#filas tr").length===1');
            assert.equal(await evaluate('envios.at(-1).estado'),'ACTIVO');
            assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
            console.log('OK: '+width+'px, filtro por defecto, estados, búsqueda, baja/reactivación simuladas');
            const listaCaptura=await call('Page.captureScreenshot',{format:'png'});
            fs.writeFileSync(path.join(os.tmpdir(),'proicon-filtro-'+width+'.png'),Buffer.from(listaCaptura.data,'base64'));

            await call('Page.navigate',{url:'http://localhost:8000/proyecto_detalle.html?id='+ids.id_proyecto});
            await wait('document.querySelectorAll("#empleados tr").length===2 && !document.getElementById("centroProyecto").hidden');
            assert(await evaluate(`document.getElementById('empleados').closest('table').textContent.includes('Estado empleado') && document.getElementById('empleados').closest('table').textContent.includes('Estado asignación')`));
            assert.deepEqual(await evaluate(`[...document.querySelectorAll('#empleados tr')[1].cells].slice(4,6).map(c=>c.textContent)`),['INACTIVO','INACTIVO']);
            await evaluate('document.getElementById("asignar").click()');
            assert(await evaluate(`![...document.getElementById('id_empleado').options].some(o=>o.value==='9002')`));
            assert.equal(await evaluate('document.getElementById("funcion_en_proyecto").value'),'');
            await evaluate(`document.querySelector('#empleadoForm [data-cerrar]').click();document.querySelector('#empleados tr button').click()`);
            await wait(`document.getElementById('funcion_en_proyecto').value==='Albañil'`);
            await evaluate(`document.querySelector('#empleadoForm [data-cerrar]').click();document.querySelectorAll('#empleados tr button')[1].click()`);
            await wait(`document.getElementById('funcion_en_proyecto').value==='Otro'`);
            assert.equal(await evaluate('document.getElementById("funcionOtra").value'),'Supervisor de acabados');
            assert.equal(await evaluate('document.getElementById("id_empleado").value'),'9002');
            for(const funcion of ['Albañil','Ingeniero encargado','Bodeguero']) {
                await evaluate(`document.getElementById('funcion_en_proyecto').value=${JSON.stringify(funcion==='Bodeguero'?'Otro':funcion)};document.getElementById('funcion_en_proyecto').dispatchEvent(new Event('change'))`);
                if(funcion==='Bodeguero') {
                    assert(await evaluate(`!document.getElementById('funcionOtraGrupo').hidden && document.getElementById('funcionOtra').required`));
                    await evaluate(`document.getElementById('funcionOtra').value='';document.getElementById('funcionOtra').dispatchEvent(new Event('input'))`);
                    assert.equal(await evaluate('document.getElementById("empleadoForm").checkValidity()'),false);
                    await evaluate(`document.getElementById('funcionOtra').value='Bodeguero';document.getElementById('funcionOtra').dispatchEvent(new Event('input'))`);
                }
                await evaluate(`document.getElementById('empleadoForm').requestSubmit()`);
                await wait('document.getElementById("empleadoEditor").hidden');
                assert.equal(await evaluate('envios.at(-1).funcion_en_proyecto'),funcion);
                await evaluate(`document.querySelectorAll('#empleados tr button')[1].click()`);
                await wait('!document.getElementById("empleadoEditor").hidden');
            }
            assert.equal(await evaluate('document.getElementById("funcion_en_proyecto").value'),'Otro');
            assert.equal(await evaluate('document.getElementById("funcionOtra").value'),'Bodeguero');
            assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth+1'),false);
            await sleep(800);
            const captura=await call('Page.captureScreenshot',{format:'png'});
            fs.writeFileSync(path.join(os.tmpdir(),'proicon-funcion-'+width+'.png'),Buffer.from(captura.data,'base64'));
            console.log('OK: '+width+'px, nuevas opciones activas, historial inactivo, funciones estándar y Otro, edición y envíos simulados');
        }
        const after=spawnSync('php',[helper],{input:sid,encoding:'utf8',windowsHide:true});assert.equal(after.status,0);assert.deepEqual(JSON.parse(after.stdout).tablas,ids.tablas);
        assert.deepEqual(errors,[]);console.log('OK: sin excepciones JavaScript; todas las tablas intactas');
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
