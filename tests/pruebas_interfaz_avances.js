// Chrome instalado + CDP nativo de Node, sin dependencias. Invocado por pruebas_avances.php.
const {spawn} = require('node:child_process');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const assert = require('node:assert/strict');
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
    let entrada = ''; for await (const c of process.stdin) entrada += c;
    const {sesion, proyecto, fotografia} = JSON.parse(entrada);
    const ejecutable = ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
    assert(ejecutable, 'Navegador instalado');
    const perfil = fs.mkdtempSync(path.join(os.tmpdir(), 'proicon-avances-'));
    const browser = spawn(ejecutable, ['--headless=new','--remote-debugging-port=0','--user-data-dir='+perfil,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','about:blank'], {windowsHide:true,stdio:'ignore'});
    let ws;
    try {
        const active = path.join(perfil, 'DevToolsActivePort');
        for(let i=0;i<100&&!fs.existsSync(active);i++) await sleep(100);
        const port = fs.readFileSync(active,'utf8').split('\n')[0];
        const pages = await (await fetch('http://127.0.0.1:'+port+'/json/list')).json();
        ws = new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
        let seq=0;const pending=new Map(),errores=[],requests=[];
        ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.id){const p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(new Error(m.error.message)):p.resolve(m.result);}}if(m.method==='Runtime.exceptionThrown')errores.push(m.params.exceptionDetails.text);if(m.method==='Network.requestWillBeSent')requests.push(m.params.request.url);};
        const call=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});
        const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error('Error en interfaz');return r.result.value;};
        const wait=async expression=>{for(let i=0;i<100;i++){if(await evaluate(expression))return;await sleep(100);}throw new Error('La vista no completó la operación esperada: '+expression);};
        await call('Runtime.enable');await call('Network.enable');await call('Network.setCookie',{name:'PHPSESSID',value:sesion,url:'http://localhost:8000'});
        for(const width of [1440,390]){
            requests.length=0;
            await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            await call('Page.navigate',{url:'http://localhost:8000/proyecto_detalle.html?id='+proyecto});
            await wait('document.querySelector("#avanceObra progress") && document.querySelectorAll("#actividadesProyecto tbody tr").length === 2 && !document.getElementById("centroProyecto").hidden');
            assert.equal(await evaluate('document.querySelector("#avanceObra progress").value'),50);
            assert.equal(await evaluate('document.documentElement.scrollWidth > innerWidth+1'),false);
            assert(!requests.some(u=>u.includes('accion=fotos_listar')),'Galería no carga hasta desplegarse');
            await evaluate('document.querySelector("#actividadesProyecto button").click()');
            await wait('!!document.querySelector(".avances dialog[open]")');
            assert(await evaluate('document.querySelector(".avances dialog[open]").getBoundingClientRect().width <= innerWidth'));
            assert(await evaluate('!!document.querySelector(".avances dialog[open] select[name=id_empleado_responsable]")'));
            await evaluate('document.querySelector(".avances dialog[open]").close()');
            await evaluate('Array.from(document.querySelectorAll("#actividadesProyecto tbody button")).find(b=>b.textContent==="Consultar").click()');
            await wait('!!document.querySelector(".avances dialog[open] textarea[name=observaciones]")');
            assert(await evaluate('document.querySelector(".avances dialog[open] textarea").disabled'));
            await evaluate('document.querySelector(".avances dialog[open]").close()');
            await evaluate('document.querySelector("#evidenciaFotografica button").click()');
            await wait('!!document.querySelector(".avances dialog[open] input[type=file]")');
            assert(await evaluate('document.querySelector(".avances dialog[open] input[type=file]").accept.includes(".png")'));
            await evaluate('document.querySelector(".avances dialog[open]").close(); document.querySelector("#evidenciaFotografica details").open=true');
            await wait('document.querySelectorAll(".foto-avance").length===12');
            await evaluate('document.querySelector(".foto-avance").scrollIntoView()');
            await wait('Array.from(document.querySelectorAll(".foto-avance img")).some(i=>i.complete && i.naturalWidth>0)');
            await evaluate('Array.from(document.querySelectorAll(".foto-avance button")).find(b=>b.textContent==="Editar").click()');
            await wait('!!document.querySelector(".avances dialog[open] textarea[name=descripcion]")');
            assert(await evaluate('document.activeElement.name === "descripcion" && document.querySelector(".avances dialog[open] [type=submit]").textContent === "Guardar cambios"'));
            await evaluate('Array.from(document.querySelectorAll(".avances dialog[open] button")).find(b=>b.textContent==="Cancelar").click()');
            await wait('!document.querySelector(".avances dialog[open]")');
            await evaluate('window.confirmOriginal=window.confirm; window.confirm=(texto)=>{window.confirmacionFoto=texto;return false;}; Array.from(document.querySelectorAll(".foto-avance button")).find(b=>b.textContent==="Eliminar").click(); window.confirm=window.confirmOriginal');
            assert.equal(await evaluate('window.confirmacionFoto'),'¿Desea eliminar esta fotografía de avance?');
            assert.equal(await evaluate('document.querySelectorAll(".foto-avance").length'),12);
            await evaluate('document.querySelector(".foto-avance button").click()');
            await wait('document.querySelector(".visor-foto img")?.naturalWidth > 0');
            assert(await evaluate('document.querySelector(".visor-foto").textContent.includes("AV_")'));
            await evaluate('document.querySelector(".visor-foto").close()');
            await evaluate('Array.from(document.querySelectorAll("#evidenciaFotografica button")).find(b=>b.textContent==="Cargar más fotografías").click()');
            await wait('document.querySelectorAll(".foto-avance").length===13');
            assert.equal(await evaluate('document.documentElement.scrollWidth > innerWidth+1'),false);
            console.log('OK: avance 50%, tabla, formularios, galería diferida, imagen y paginación a '+width+'px');
        }
        assert.deepEqual(errores,[]);console.log('OK: navegador sin excepciones JavaScript');
        await call('Browser.close');
    }finally{
        if(ws)ws.close();browser.kill();await sleep(800);
        const resolved=path.resolve(perfil);
        assert(path.dirname(resolved)===path.resolve(os.tmpdir())&&path.basename(resolved).startsWith('proicon-avances-'));
        fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
})().catch(e=>{console.error('FALLO: '+e.message);process.exitCode=1;});
