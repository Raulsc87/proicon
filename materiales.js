const configuracionMateriales = {
    modulo: "materiales",
    singular: "material",
    id: "id_material",
    columnas: ["codigo", "nombre", "descripcion", "id_unidad", "estado"],
    etiquetas: { id_unidad: {} },
    async preparar(solicitar) {
        const resultado = await solicitar("unidades");
        const selector = document.getElementById("id_unidad");
        for (const unidad of resultado.datos) {
            const etiqueta = unidad.nombre + " (" + unidad.abreviatura + ")";
            configuracionMateriales.etiquetas.id_unidad[unidad.id_unidad] = etiqueta;
            const opcion = document.createElement("option");
            opcion.value = unidad.id_unidad;
            opcion.textContent = etiqueta;
            selector.append(opcion);
        }
        if (!resultado.datos.length) {
            document.getElementById("nuevo").disabled = true;
            throw new Error("No hay unidades de medida disponibles. No se pueden crear materiales.");
        }
    }
};
iniciarMantenimiento(configuracionMateriales);
