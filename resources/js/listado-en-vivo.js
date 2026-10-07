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
 */
export default () => ({
    cargando: false,
    ultimaBusqueda: null,
    peticion: null,

    init() {
        this.ultimaBusqueda = this.consulta();
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
        if (consulta === this.ultimaBusqueda) {
            return;
        }
        this.ultimaBusqueda = consulta;

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
