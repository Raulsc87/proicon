// La pantalla antigua no tenía registro en PostgreSQL. No persistir contraseñas en el navegador.
const formularioRegistro = document.getElementById('formularioRegistro');
formularioRegistro.addEventListener('submit', evento => evento.preventDefault());
