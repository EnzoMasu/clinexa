/**
 * Convierte HTML que viene del servidor (fragmentos ya escapados por Blade) en un DocumentFragment listo
 * para insertar, como segunda barrera por si algún dato quedara sin escapar:
 * - se parsea en un <template> (inerte: no carga imágenes ni ejecuta nada mientras se arma);
 * - se quitan los <script> y los atributos de evento nativos (onerror, onload, onclick…), que un
 *   <template> no neutraliza: se disparan al insertar el nodo en la página.
 * Los atributos de Alpine (x-on:…, @…) no empiezan con "on" y se mantienen.
 * Lo usan la actualización periódica (Consulta, Preparación) y el popup de consultas.
 */
export default function fragmentoInerte(html) {
    const plantilla = document.createElement('template');
    plantilla.innerHTML = html;
    plantilla.content.querySelectorAll('script').forEach((script) => script.remove());
    plantilla.content.querySelectorAll('*').forEach((elemento) => {
        [...elemento.attributes]
            .filter((atributo) => atributo.name.toLowerCase().startsWith('on'))
            .forEach((atributo) => elemento.removeAttribute(atributo.name));
    });

    return plantilla.content;
}
