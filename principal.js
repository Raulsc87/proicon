const nombreUsuario = document.getElementById("nombreUsuario");
const botonCerrarSesion = document.getElementById("botonCerrarSesion");

const usuarioActivo =
    JSON.parse(localStorage.getItem("usuarioActivo"));

if (usuarioActivo === null) {

    window.location.href = "index.html";

} else {

    nombreUsuario.textContent = usuarioActivo.nombre;

}

botonCerrarSesion.addEventListener("click", function () {

    localStorage.removeItem("usuarioActivo");

    window.location.href = "index.html";

});