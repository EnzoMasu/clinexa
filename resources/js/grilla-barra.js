// Barra horizontal duplicada arriba de cada grilla (x-admin.table), sincronizada con la de abajo. Se muestra solo
// si la tabla desborda. Se rehace al cambiar el tamaño de la ventana y al recargarse la tabla en vivo.
// No guarda nada en el navegador.
const enlazadas = new WeakSet();

function ajustar(barra, tabla) {
    const ancho = tabla.scrollWidth;
    barra.firstElementChild.style.width = `${ancho}px`;
    barra.hidden = ancho <= tabla.clientWidth + 1;
    tabla.toggleAttribute('data-desborda', !barra.hidden);
}

export function ajustarBarras() {
    for (const tabla of document.querySelectorAll('[data-grilla-scroll]')) {
        const barra = tabla.previousElementSibling;
        if (!barra?.hasAttribute('data-grilla-barra')) continue;

        ajustar(barra, tabla);

        if (enlazadas.has(tabla)) continue;
        enlazadas.add(tabla);
        let moviendo = false;
        const sincronizar = (origen, destino) => () => {
            if (moviendo) { moviendo = false; return; }
            if (destino.scrollLeft === origen.scrollLeft) return;
            moviendo = true;
            destino.scrollLeft = origen.scrollLeft;
        };
        barra.addEventListener('scroll', sincronizar(barra, tabla), { passive: true });
        tabla.addEventListener('scroll', sincronizar(tabla, barra), { passive: true });
        // Cambia el ancho de la tabla (columnas, filtros) sin que cambie la ventana.
        if ('ResizeObserver' in window) {
            new ResizeObserver(() => ajustar(barra, tabla)).observe(tabla.firstElementChild);
        }
    }
}

export default function iniciarBarraGrillas() {
    ajustarBarras();
    window.addEventListener('resize', ajustarBarras);
    window.addEventListener('load', ajustarBarras);
    document.addEventListener('grilla-reemplazada', ajustarBarras);
}
