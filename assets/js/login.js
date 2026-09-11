const formularioLogin = document.getElementById("formularioLogin");
const mensaje = document.getElementById("mensaje");
const botonLogin = formularioLogin.querySelector('button[type="submit"]');

formularioLogin.addEventListener("submit", async function (evento) {
    evento.preventDefault();
    if (botonLogin.disabled) return;
    const usuario = document.getElementById("usuario").value.trim();
    const contrasena = document.getElementById("contrasena").value;
    mensaje.className = "mensaje error";
    if (usuario === "" || contrasena === "") {
        mensaje.textContent = "Completa todos los campos.";
        return;
    }
    botonLogin.disabled = true;
    mensaje.textContent = "";
    try {
        const respuesta = await fetch("api/login.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ usuario, contrasena })
        });
        const datos = await respuesta.json();
        if (!respuesta.ok || datos.ok !== true) {
            mensaje.textContent = datos.mensaje || "No se pudo iniciar sesión.";
            return;
        }
        mensaje.className = "mensaje correcto";
        mensaje.textContent = "Inicio de sesión correcto.";
        window.location.href = "principal.html";
    } catch (error) {
        mensaje.textContent = "No se pudo conectar con el servidor. Intenta nuevamente.";
    } finally {
        botonLogin.disabled = false;
    }
});