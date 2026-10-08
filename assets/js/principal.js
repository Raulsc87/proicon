const nombreUsuario = document.getElementById("nombreUsuario");
const botonCerrarSesion = document.getElementById("botonCerrarSesion");
const Autorizacion = (() => {
    let permisos = new Set();
    const modulos = {
        'clientes.html': 'CLIENTES', 'proveedores.html': 'PROVEEDORES', 'materiales.html': 'MATERIALES',
        'proyectos.html': 'PROYECTOS', 'proyecto_detalle.html': 'PROYECTOS',
        'presupuestos_resumen.html': 'PRESUPUESTOS', 'empleados_proyectos.html': 'EMPLEADOS_PROYECTO',
        'avance_proyectos.html': 'ACTIVIDADES', 'solicitud_detalle.html': 'SOLICITUDES',
        'compra_detalle.html': 'COMPRAS', 'factura_detalle.html': 'FACTURAS'
    };
    const puede = permiso => permisos.has(permiso);
    function marcar(elemento, permiso) {
        if (!elemento) return;
        elemento.dataset.permiso = permiso;
        elemento.classList.toggle('sin-permiso', !puede(permiso));
    }
    function aplicar(usuario) {
        permisos = new Set(usuario.permisos || []);
        document.querySelectorAll('.menu nav a, a.tarjeta, [data-permiso]').forEach(e => {
            const modulo = e.matches('a') ? modulos[new URL(e.href, location.href).pathname.split('/').pop()] : null;
            const permiso = e.dataset.permiso || (modulo && 'VER_' + modulo);
            if (permiso) marcar(e, permiso);
        });
        const modulo = modulos[location.pathname.split('/').pop()];
        if (modulo && !puede('VER_' + modulo)) {
            const main = document.querySelector('main'); main.replaceChildren();
            const h = document.createElement('h1'); h.textContent = 'Acceso no autorizado';
            const a = document.createElement('a'); a.href = 'principal.html'; a.textContent = 'Volver al Panel de Control'; main.append(h, a);
            document.documentElement.classList.add('acceso-listo'); return false;
        }
        document.documentElement.classList.add('acceso-listo'); return true;
    }
    return {puede, marcar, aplicar, lista: null};
})();

async function cargarSesion() {
    try {
        const respuesta = await fetch("api/sesion.php", {
            credentials: "same-origin",
            cache: "no-store"
        });
        const datos = await respuesta.json();
        if (!respuesta.ok || datos.activa !== true) {
            window.location.replace("index.html");
            return false;
        }
        nombreUsuario.textContent = datos.usuario.nombre;
        return Autorizacion.aplicar(datos.usuario);
    } catch (error) {
        window.location.replace("index.html");
        return false;
    }
}

Autorizacion.lista = cargarSesion();
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

botonCerrarSesion.addEventListener("click", async function () {
    if (botonCerrarSesion.disabled) return;
    botonCerrarSesion.disabled = true;
    try {
        const respuesta = await fetch("api/logout.php", {
            method: "POST",
            credentials: "same-origin"
        });
        const datos = await respuesta.json();
        if (!respuesta.ok || datos.ok !== true) {
            throw new Error("No se pudo cerrar la sesión.");
        }
        window.location.replace("index.html");
    } catch (error) {
        alert("No se pudo cerrar la sesión. Intenta nuevamente.");
    } finally {
        botonCerrarSesion.disabled = false;
    }
});
