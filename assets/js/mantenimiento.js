function iniciarMantenimiento(config) {
    const formulario = document.getElementById("formulario");
    const editor = document.getElementById("editor");
    const mensaje = document.getElementById("mensajeModulo");
    const filas = document.getElementById("filas");
    const estadoLista = document.getElementById("estadoLista");
    const guardar = document.getElementById("guardar");
    let idActual = null;
    let ocupado = false;
    let versionLista = 0;
    const campos = [...formulario.querySelectorAll("[name]")];

    function informar(texto, error = false) {
        mensaje.className = "mensaje " + (error ? "error" : "correcto");
        mensaje.textContent = texto;
    }

    async function solicitar(accion, datos, consulta = "") {
        const respuesta = await fetch("api/" + config.modulo + "_" + accion + ".php" + consulta, {
            method: datos ? "POST" : "GET",
            credentials: "same-origin",
            cache: "no-store",
            ...(datos ? { headers: { "Content-Type": "application/json" }, body: JSON.stringify(datos) } : {})
        });
        if (respuesta.status === 401) {
            window.location.replace("index.html");
            throw new Error("La sesión ha terminado.");
        }
        let resultado;
        try { resultado = await respuesta.json(); }
        catch { throw new Error("El servidor no devolvió una respuesta válida."); }
        if (!respuesta.ok || !resultado.ok) throw new Error(resultado.mensaje || "No se pudo completar la operación.");
        return resultado;
    }

    async function listar() {
        const version = ++versionLista;
        estadoLista.textContent = "Cargando…";
        try {
            const resultado = await solicitar("listar", null, "?buscar=" + encodeURIComponent(document.getElementById("buscar").value.trim()));
            if (version !== versionLista) return;
            filas.replaceChildren();
            for (const registro of resultado.datos) {
                const fila = document.createElement("tr");
                for (const campo of config.columnas) {
                    const celda = document.createElement("td");
                    celda.textContent = config.etiquetas?.[campo]?.[registro[campo]] ?? registro[campo] ?? "—";
                    fila.append(celda);
                }
                const acciones = document.createElement("td");
                acciones.className = "acciones";
                for (const accion of ["Ver", "Editar", ...(config.columnas.includes("estado") ? [registro.estado === "ACTIVO" ? "Desactivar" : "Reactivar"] : [])]) {
                    const boton = document.createElement("button");
                    boton.type = "button";
                    boton.textContent = accion;
                    boton.addEventListener("click", () => operar(async () => {
                        if (accion === "Ver" || accion === "Editar") {
                            const resultado = await solicitar("obtener", null, "?id=" + encodeURIComponent(registro[config.id]));
                            abrir(resultado.datos, accion === "Ver");
                        } else {
                            if (!confirm(accion + " este registro?")) return;
                            const resultado = await solicitar("estado", { id: registro[config.id], estado: accion === "Desactivar" ? "INACTIVO" : "ACTIVO" });
                            editor.hidden = true;
                            informar(resultado.mensaje);
                            await listar();
                        }
                    }));
                    acciones.append(boton);
                }
                fila.append(acciones);
                filas.append(fila);
            }
            estadoLista.textContent = resultado.datos.length ? resultado.datos.length + " registros." : "No se encontraron registros.";
        } catch (error) {
            if (version !== versionLista) return;
            filas.replaceChildren();
            estadoLista.textContent = "No se pudo cargar la lista.";
            informar(error.message || "No se pudo conectar con el servidor.", true);
        }
    }

    function abrir(registro = null, lectura = false) {
        formulario.reset();
        idActual = registro ? registro[config.id] : null;
        campos.forEach(campo => {
            campo.disabled = lectura;
            if (registro) campo.value = registro[campo.name] ?? "";
        });
        guardar.hidden = lectura;
        guardar.textContent = registro && !lectura ? 'Guardar cambios' : 'Guardar';
        document.getElementById('cancelar').textContent = lectura ? 'Cerrar' : 'Cancelar';
        document.getElementById("tituloEditor").textContent = (lectura ? "Ver " : registro ? "Editar " : "Nuevo ") + config.singular;
        editor.hidden = false;
        (lectura ? document.getElementById('tituloEditor') : campos.find(c => !c.disabled)).focus({preventScroll: true});
        editor.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
        informar("");
    }

    async function operar(operacion) {
        if (ocupado) return;
        ocupado = true;
        guardar.disabled = true;
        try { await operacion(); }
        catch (error) { informar(error.message || "No se pudo conectar con el servidor.", true); }
        finally { ocupado = false; guardar.disabled = false; }
    }

    document.getElementById("nuevo").addEventListener("click", () => { if (!ocupado) abrir(); });
    document.getElementById("cancelar").addEventListener("click", () => { if (!ocupado) editor.hidden = true; });
    document.getElementById("busqueda").addEventListener("submit", evento => { evento.preventDefault(); listar(); });
    formulario.addEventListener("submit", evento => {
        evento.preventDefault();
        operar(async () => {
            const datos = Object.fromEntries(new FormData(formulario));
            if (idActual !== null) datos.id = idActual;
            const resultado = await solicitar(idActual === null ? "crear" : "editar", datos);
            editor.hidden = true;
            informar(resultado.mensaje);
            await listar();
        });
    });
    async function iniciar() {
        if (config.preparar) await config.preparar(solicitar);
        await listar();
    }
    iniciar().catch(error => {
        estadoLista.textContent = "No se pudieron cargar los datos.";
        informar(error.message, true);
    });
}
