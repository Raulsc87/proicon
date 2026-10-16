(async () => {
    if (!await Autorizacion.lista) return;
    const el=id=>document.getElementById(id);
    const crear=(tag,texto,clase='')=>{const e=document.createElement(tag);e.textContent=texto;e.className=clase;return e;};
    const porcentaje=v=>Number(v).toLocaleString('es-GT',{maximumFractionDigits:2})+'%';
    function dinero(valor) {
        // El endpoint devuelve NUMERIC como texto; no se pierde precisión monetaria.
        const [entero,decimal='']=String(valor).split('.');
        return 'Q\u00a0'+entero.replace(/\B(?=(\d{3})+(?!\d))/g,',')+'.'+decimal.padEnd(2,'0');
    }
    function enlace(p) {const a=crear('a',p.codigo+' · '+p.nombre);a.href='proyecto_detalle.html?'+new URLSearchParams({id:p.id_proyecto});return a;}
    function badge(p) {
        const colores={VERDE:'verde',AMARILLO:'amarillo',ROJO:'rojo',SIN_DATOS:'neutro'};
        return crear('span',p.semaforo==='SIN_DATOS'?'Sin información suficiente':p.semaforo,'badge-estado badge-'+colores[p.semaforo]);
    }
    function limpiar() {
        for(const id of ['indicadorProyectos','indicadorClientes','indicadorEmpleados','indicadorPresupuesto','avanceGeneralTexto'])el(id).textContent='—';
        el('avanceGeneral').removeAttribute('value');el('riesgosDashboard').hidden=true;el('estadoProyectosDashboard').hidden=true;
        el('filasDashboard').replaceChildren();el('listaRiesgos').replaceChildren();el('notaPresupuesto').textContent='';
    }
    async function cargar() {
        el('actualizarDashboard').disabled=true;el('dashboardDatos').setAttribute('aria-busy','true');
        el('dashboardMensaje').textContent='Cargando indicadores…';el('dashboardMensaje').className='mensaje';limpiar();
        try {
            const r=await fetch('api/dashboard.php',{credentials:'same-origin',cache:'no-store'});
            if(r.status===401){location.replace('index.html');return;}
            const respuesta=await r.json();if(!r.ok||!respuesta.ok)throw Error(respuesta.mensaje||'No se pudieron cargar los indicadores.');
            const d=respuesta.datos,i=d.indicadores;
            el('indicadorProyectos').textContent=i.proyectos_activos;
            el('indicadorClientes').textContent=i.clientes;
            el('indicadorEmpleados').textContent=i.empleados_activos;
            el('indicadorPresupuesto').textContent=dinero(i.presupuestado);
            el('notaPresupuesto').textContent=d.regla_presupuesto+' '+i.presupuestos_incluidos+' proyectos contabilizados.'+(i.presupuestos_ambiguos?' '+i.presupuestos_ambiguos+' proyectos excluidos por versiones aprobadas duplicadas.':'');
            el('avanceGeneral').value=i.avance_general;el('avanceGeneralTexto').textContent=porcentaje(i.avance_general);
            el('avanceGeneralNota').textContent=i.proyectos_activos?'Promedio del avance real de los '+i.proyectos_activos+' proyectos EN_PROCESO.':'No hay proyectos activos para calcular un promedio.';
            el('dashboardFecha').textContent='Calculado al '+d.fecha_calculo+' · Guatemala';
            if(d.puede_ver_proyectos&&Autorizacion.puede('VER_PROYECTOS')) {
                el('estadoProyectosDashboard').hidden=false;
                for(const p of d.proyectos) {
                    const tr=document.createElement('tr'),nombre=document.createElement('td');nombre.append(enlace(p));
                    const real=crear('td',porcentaje(p.avance_real));if(!p.total)real.append(crear('small','Sin actividades'));
                    const esperado=crear('td',p.avance_esperado===null?'—':porcentaje(p.avance_esperado));
                    const color=document.createElement('td');color.append(badge(p),crear('small',p.motivo));
                    tr.append(nombre,real,esperado,color);el('filasDashboard').append(tr);
                }
                el('notaProyectos').textContent=d.total_proyectos?'Mostrando '+d.proyectos.length+' de '+d.total_proyectos+' proyectos.':'No hay proyectos registrados.';
                el('riesgosDashboard').hidden=!d.riesgos.length;
                for(const p of d.riesgos) {const li=document.createElement('li');li.append(badge(p),enlace(p),crear('span',porcentaje(p.avance_real)+' / '+porcentaje(p.avance_esperado)+' esperado'));el('listaRiesgos').append(li);}
                el('notaRiesgos').textContent='Mostrando '+d.riesgos.length+' de '+d.total_riesgos+' proyectos activos que requieren atención.';
            }
            el('dashboardMensaje').textContent='';
        } catch(e) {el('dashboardMensaje').textContent=e.message||'No se pudo conectar con el servidor.';el('dashboardMensaje').className='mensaje error';}
        finally {el('actualizarDashboard').disabled=false;el('dashboardDatos').setAttribute('aria-busy','false');}
    }
    el('actualizarDashboard').addEventListener('click',cargar);cargar();
})();
