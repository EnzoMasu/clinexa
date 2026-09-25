/**
 * Búsqueda y paginación en vivo de los listados de /admin (componente x-admin.listado).
 *
 * El formulario de búsqueda es un GET normal (sin JavaScript sigue funcionando con el botón).
 * Con JavaScript: busca sola al dejar de escribir y al paginar, pide al mismo endpoint con
 * X-Requested-With y el controlador devuelve solo la tabla (_tabla.blade.php), que reemplaza
 * el contenido de x-ref="resultados" sin recargar el resto de la página.
 */
export default () => ({
    cargando: false,
    ultimaBusqueda: null,
    peticion: null,

    init() {
        this.ultimaBusqueda = this.$refs.q.value.trim();
    },

    buscar() {
        const q = this.$refs.q.value.trim();
        if (q === this.ultimaBusqueda) {
            return;
        }
        this.ultimaBusqueda = q;

        // Una búsqueda nueva siempre vuelve a la página 1.
        const url = new URL(this.$refs.formulario.action);
        if (q !== '') {
            url.searchParams.set('q', q);
        }
        this.cargar(url.toString());
    },

    // Clicks dentro de los resultados: solo se interceptan los links de la paginación.
    paginar(evento) {
        const link = evento.target.closest('nav[role="navigation"] a[href]');
        if (!link || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.button !== 0) {
            return;
        }
        evento.preventDefault();
        this.cargar(link.href, { subir: true });
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

            this.$refs.resultados.innerHTML = await respuesta.text();
            window.history.replaceState(null, '', url); // la URL refleja búsqueda y página (recargar mantiene el estado)

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
