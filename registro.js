const formularioRegistro = document.getElementById("formularioRegistro");
const mensaje = document.getElementById("mensaje");

formularioRegistro.addEventListener("submit", function (evento) {

    evento.preventDefault();

    const nombre = document.getElementById("nombre").value.trim();
    const correo = document.getElementById("correo").value.trim();
    const contrasena = document.getElementById("contrasena").value;
    const confirmarContrasena =
        document.getElementById("confirmarContrasena").value;

    mensaje.className = "mensaje error";

    if (
        nombre === "" ||
        correo === "" ||
        contrasena === "" ||
        confirmarContrasena === ""
    ) {
        mensaje.textContent = "Todos los campos son obligatorios.";
        return;
    }

    if (!correo.includes("@") || !correo.includes(".")) {
        mensaje.textContent = "Ingresa un correo válido.";
        return;
    }

    if (contrasena.length < 6) {
        mensaje.textContent =
            "La contraseña debe tener mínimo 6 caracteres.";
        return;
    }

    if (contrasena !== confirmarContrasena) {
        mensaje.textContent = "Las contraseñas no coinciden.";
        return;
    }

    let usuarios = JSON.parse(localStorage.getItem("usuarios")) || [];

    const correoExiste = usuarios.some(function (usuario) {
        return usuario.correo === correo;
    });

    if (correoExiste) {
        mensaje.textContent = "Este correo ya está registrado.";
        return;
    }

    const nuevoUsuario = {
        nombre: nombre,
        correo: correo,
        contrasena: contrasena
    };

    usuarios.push(nuevoUsuario);

    localStorage.setItem("usuarios", JSON.stringify(usuarios));

    mensaje.className = "mensaje correcto";
    mensaje.textContent = "Usuario registrado correctamente.";

    formularioRegistro.reset();

    setTimeout(function () {
        window.location.href = "index.html";
    }, 1500);

});