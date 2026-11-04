/**
 * Búsqueda y paginación en vivo de los listados de /admin (componente x-admin.listado).
 *
 * El formulario de búsqueda es un GET normal (sin JavaScript sigue funcionando con el botón).
 * Con JavaScript: busca sola al dejar de escribir y al paginar, pide al mismo endpoint con
 * X-Requested-With y el controlador devuelve solo la tabla (_tabla.blade.php), que reemplaza
 * el contenido de x-ref="resultados" sin recargar el resto de la página.
 *
 * Además del texto (q), toma todos los campos del formulario: los filtros que agregue el listado
 * (selects, fechas) buscan al cambiar. Los campos vacíos no van en la URL.
 *
 * Grillas (docs/grillas.md): la fila de filtros va dentro de la tabla, pero sus campos son del formulario
 * (atributo "form"), así que FormData los incluye. Los títulos que ordenan y "Limpiar" se cargan en vivo
 * como la paginación. Al reemplazar la tabla se conserva el campo que se estaba escribiendo.
 */
// Clave para comparar búsquedas: sin la página y con los parámetros ordenados (el orden de la URL no importa).
const normalizar = (consulta) => {
    const parametros = new URLSearchParams(consulta);
    parametros.delete('page');
    parametros.sort();

    return parametros.toString();
};

export default () => ({
    cargando: false,
    ultimaBusqueda: null,
    peticion: null,

    init() {
        this.ultimaBusqueda = normalizar(this.consulta());
    },

    // Texto y filtros del formulario como query string, sin los vacíos.
    consulta() {
        const parametros = new URLSearchParams();
        for (const [nombre, valor] of new FormData(this.$refs.formulario)) {
            const texto = String(valor).trim();
            if (texto !== '') {
                parametros.append(nombre, texto);
            }
        }

        return parametros.toString();
    },

    buscar() {
        const consulta = this.consulta();
        if (normalizar(consulta) === this.ultimaBusqueda) {
            return;
        }
        this.ultimaBusqueda = normalizar(consulta);

        // Una búsqueda nueva siempre vuelve a la página 1.
        const url = new URL(this.$refs.formulario.action);
        url.search = consulta;
        this.cargar(url.toString());
    },

    // Un filtro de fecha busca cuando queda vacío o completo (dd/mm/aaaa), no mientras se escribe.
    alCambiarFecha(evento) {
        if (/^(\d{2}\/\d{2}\/\d{4})?$/.test(evento.detail.valor ?? '')) {
            this.buscar();
        }
    },

    // Fila de filtros de la grilla: los textos buscan al dejar de escribir; las fechas, con alCambiarFecha.
    alEscribirFiltro(evento) {
        if (evento.target.form === this.$refs.formulario && !evento.target.hasAttribute('data-grilla-fecha')) {
            this.buscar();
        }
    },

    alCambiarFiltro(evento) {
        if (evento.target.form === this.$refs.formulario && evento.target.tagName === 'SELECT') {
            this.buscar();
        }
    },

    // Clicks dentro de los resultados: paginación, títulos que ordenan y "Limpiar" de la grilla.
    paginar(evento) {
        const link = evento.target.closest('nav[role="navigation"] a[href], a[data-grilla-orden], a[data-grilla-limpiar]');
        if (!link || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.button !== 0) {
            return;
        }
        evento.preventDefault();
        this.cargar(link.href, { subir: true });
    },

    // Cambia la tabla. Si se estaba escribiendo en un filtro de la grilla, el campo nuevo recibe el foco,
    // lo escrito hasta ahora (pudo seguir escribiéndose durante la carga) y la posición del cursor.
    reemplazar(html) {
        const activo = document.activeElement;
        const enFiltro = activo?.name && this.$refs.resultados.contains(activo) && activo.form === this.$refs.formulario;
        const anterior = enFiltro ? { nombre: activo.name, valor: activo.value, inicio: activo.selectionStart, fin: activo.selectionEnd } : null;

        // Desplegables con la lista solo en la página entera (data-grilla-diferida): se conserva el que ya estaba.
        const diferidos = [...this.$refs.resultados.querySelectorAll('select[data-grilla-diferida]')];

        this.$refs.resultados.innerHTML = html;

        for (const anteriorSelect of diferidos) {
            this.$refs.resultados.querySelector(`select[data-grilla-diferida][name="${CSS.escape(anteriorSelect.name)}"]`)?.replaceWith(anteriorSelect);
        }

        if (anterior) {
            const nuevo = this.$refs.resultados.querySelector(`[name="${CSS.escape(anterior.nombre)}"]`);
            if (nuevo) {
                nuevo.value = anterior.valor;
                nuevo.focus();
                if (anterior.inicio !== null && typeof nuevo.setSelectionRange === 'function') {
                    try { nuevo.setSelectionRange(anterior.inicio, anterior.fin); } catch { /* type sin selección */ }
                }
            }
        }
    },

    async cargar(url, { subir = false } = {}) {
        this.peticion?.abort(); // descarta una búsqueda anterior que todavía no respondió
        const peticion = new AbortController();
        this.peticion = peticion;
        this.cargando = true;

        try {
            const respuesta = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                signal: peticion.signal,
            });

            // Sesión vencida, sin permiso o error: se navega normalmente para que el usuario vea qué pasó.
            if (respuesta.redirected || !respuesta.ok) {
                window.location.assign(url);
                return;
            }

            this.ultimaBusqueda = normalizar(new URL(url, window.location.href).search); // lo que muestra la tabla nueva
            this.reemplazar(await respuesta.text());
            window.history.replaceState(null, '', url); // la URL refleja búsqueda y página (recargar mantiene el estado)
            this.buscar(); // por si se siguió escribiendo en un filtro mientras se cargaba

            if (subir) {
                this.$refs.resultados.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                window.location.assign(url);
            }
        } finally {
            // Solo la petición vigente apaga el indicador (una cancelada no debe hacerlo).
            if (this.peticion === peticion) {
                this.cargando = false;
            }
        }
    },
});
