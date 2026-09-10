const formularioLogin = document.getElementById("formularioLogin");
const mensaje = document.getElementById("mensaje");

formularioLogin.addEventListener("submit", function (evento) {

    evento.preventDefault();

    const correo = document.getElementById("correo").value.trim();
    const contrasena = document.getElementById("contrasena").value;

    mensaje.className = "mensaje error";

    if (correo === "" || contrasena === "") {
        mensaje.textContent = "Completa todos los campos.";
        return;
    }

    const usuarios =
        JSON.parse(localStorage.getItem("usuarios")) || [];

    const usuarioEncontrado = usuarios.find(function (usuario) {

        return (
            usuario.correo === correo &&
            usuario.contrasena === contrasena
        );

    });

    if (!usuarioEncontrado) {
        mensaje.textContent = "Correo o contraseña incorrectos.";
        return;
    }

    localStorage.setItem(
        "usuarioActivo",
        JSON.stringify(usuarioEncontrado)
    );

    mensaje.className = "mensaje correcto";
    mensaje.textContent = "Inicio de sesión correcto.";

    setTimeout(function () {
        window.location.href = "principal.html";
    }, 1000);

});