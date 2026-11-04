// Alto de las grillas (x-admin.table): el contenedor con scroll ocupa, como máximo, lo que queda de la pantalla
// debajo de lo que tiene encima y arriba de la paginación, así su barra horizontal queda siempre visible abajo.
// Una lista corta no se estira: solo se fija el máximo. Sin datos clínicos ni nada guardado en el navegador.
const MARGEN = 16;
const MINIMO = 240;

export function ajustarGrillas() {
    for (const grilla of document.querySelectorAll('[data-grilla-scroll]')) {
        const arriba = grilla.getBoundingClientRect().top + window.scrollY;
        const paginacion = grilla.parentElement?.querySelector('[data-grilla-paginacion]');
        const abajo = (paginacion?.offsetHeight ?? 0) + MARGEN;
        grilla.style.maxHeight = `${Math.max(MINIMO, window.innerHeight - arriba - abajo)}px`;
    }
}

export default function iniciarAlturaGrillas() {
    ajustarGrillas();
    window.addEventListener('resize', ajustarGrillas);
    window.addEventListener('load', ajustarGrillas);
    // La tabla se reemplaza en vivo (búsqueda, orden, paginación): se ajusta la nueva.
    document.addEventListener('grilla-reemplazada', ajustarGrillas);
}
