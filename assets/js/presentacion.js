// Decoración visual únicamente: conserva textos, valores, eventos y autorización.
(() => {
    const estados = {
        VERDE:'verde', ACTIVO:'verde', APROBADO:'verde', APROBADA:'verde', FINALIZADO:'verde', RECIBIDA:'verde',
        AMARILLO:'amarillo', PENDIENTE:'amarillo', EN_PROCESO:'amarillo',
        ROJO:'rojo', RECHAZADO:'rojo', INACTIVO:'neutro', BORRADOR:'neutro', REGISTRADA:'neutro'
    };
    function decorar(root) {
        if (!(root instanceof Element)) return;
        const elementos = [root, ...root.querySelectorAll('button, td, dd')];
        for (const e of elementos) {
            if (e.matches('button')) {
                const texto = e.textContent.trim();
                e.classList.toggle('boton-secundario', /^(Consultar|Ver\b|Volver|Cancelar|Cerrar|Abrir|Descargar|Archivo|Comprobante|Factura \/)/.test(texto));
                e.classList.toggle('boton-peligro', /^(Eliminar|Quitar|Desactivar)/.test(texto));
            }
            if (e.matches('td, dd') && e.childElementCount === 0) {
                const estado = e.textContent.trim();
                if (Object.hasOwn(estados, estado)) {
                    const badge = document.createElement('span');
                    badge.className = 'badge-estado badge-' + estados[estado]; badge.textContent = e.textContent;
                    e.replaceChildren(badge);
                }
            }
        }
    }
    decorar(document.body);
    new MutationObserver(cambios => {
        for (const cambio of cambios) for (const n of cambio.addedNodes) {
            decorar(n.nodeType === Node.TEXT_NODE ? n.parentElement : n);
        }
    }).observe(document.body, {childList: true, subtree: true});
})();
