const nombreUsuario = document.getElementById("nombreUsuario");
const botonCerrarSesion = document.getElementById("botonCerrarSesion");

async function cargarSesion() {
    try {
        const respuesta = await fetch("api/sesion.php", {
            credentials: "same-origin",
            cache: "no-store"
        });
        const datos = await respuesta.json();
        if (!respuesta.ok || datos.activa !== true) {
            window.location.replace("index.html");
            return;
        }
        nombreUsuario.textContent = datos.usuario.nombre;
    } catch (error) {
        window.location.replace("index.html");
    }
}

window.addEventListener("pageshow", cargarSesion);

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