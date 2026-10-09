/**
 * Popup del detalle de una consulta en la historia del paciente (historias-clinicas/show).
 *
 * - Cada fila es un enlace a la página completa. Solo el clic normal abre el popup: Ctrl/Cmd/Shift
 *   + clic, clic con la rueda o "abrir en pestaña nueva" siguen yendo a la página.
 * - El contenido lo pide al servidor de a una consulta (ConsultaController::detalle, que registra
 *   el VER) y lo inserta tal como llega: HTML ya renderizado y escapado por Blade. Acá nunca se arma
 *   HTML con datos clínicos.
 * - Anterior/Siguiente recorren solo las consultas de la página actual ("ids", en el orden mostrado).
 *   Una petición nueva cancela la anterior (AbortController) y las respuestas tardías se descartan.
 * - Sesión vencida o sin permiso: navega a la página completa, que muestra el login o el 403.
 * - Accesibilidad: diálogo modal, foco adentro mientras está abierto (y de vuelta a la fila al
 *   cerrar), Esc, flechas izquierda y derecha, y el fondo no se desplaza.
 */
import fragmentoInerte from './fragmento-inerte';

export default ({ ids, urlDetalle, urlPagina, sinEditar = false }) => ({
    ids,
    urlPagina,
    abierto: false,
    indice: -1,
    estado: 'cargando', // cargando | listo | error
    urlEditar: null,
    peticion: null,
    origen: null,

    urlDe(plantilla) {
        return this.indice < 0 ? '#' : plantilla.replace('__ID__', String(this.ids[this.indice]));
    },

    abrir(id, evento) {
        if (evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey || evento.button !== 0) {
            return; // que el navegador abra la página completa
        }
        evento.preventDefault();

        this.origen = evento.currentTarget;
        this.indice = this.ids.indexOf(id);
        this.abierto = true;
        document.documentElement.style.overflow = 'hidden';
        this.$nextTick(() => this.$refs.dialogo.focus());
        this.cargar();
    },

    cerrar() {
        if (!this.abierto) {
            return;
        }
        this.peticion?.abort();
        this.peticion = null;
        this.abierto = false;
        // El contenido clínico no queda en la página al cerrar.
        this.$refs.contenido.replaceChildren();
        this.urlEditar = null;
        document.documentElement.style.overflow = '';
        this.origen?.focus();
    },

    anterior() {
        if (this.indice > 0) {
            this.indice--;
            this.cargar();
        }
    },

    siguiente() {
        if (this.indice < this.ids.length - 1) {
            this.indice++;
            this.cargar();
        }
    },

    async cargar() {
        this.peticion?.abort(); // descarta la consulta pedida antes si todavía no respondió
        const peticion = new AbortController();
        this.peticion = peticion;
        this.estado = 'cargando';
        this.urlEditar = null;
        this.$refs.contenido.replaceChildren();

        try {
            const respuesta = await fetch(this.urlDe(urlDetalle), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                credentials: 'same-origin',
                signal: peticion.signal,
            });
            if (this.peticion !== peticion) {
                return; // llegó tarde: ya se pidió otra
            }

            // Sesión vencida (redirige al login o 401/419) o sin permiso (403): página completa.
            if (respuesta.redirected || [401, 403, 419].includes(respuesta.status)) {
                window.location.assign(this.urlDe(this.urlPagina));
                return;
            }
            if (!respuesta.ok) {
                this.estado = 'error';
                return;
            }

            const html = await respuesta.text();
            if (this.peticion !== peticion) {
                return;
            }
            // Solo HTML que viene del servidor, ya escapado por Blade; además sin scripts ni atributos on*.
            this.$refs.contenido.replaceChildren(fragmentoInerte(html));
            // sinEditar: el panel de historial de la pantalla de atención abre las consultas anteriores solo para leer.
            this.urlEditar = sinEditar ? null : (this.$refs.contenido.querySelector('[data-url-editar]')?.dataset.urlEditar ?? null);
            this.estado = 'listo';
        } catch (error) {
            if (error.name !== 'AbortError' && this.peticion === peticion) {
                this.estado = 'error';
            }
        }
    },

    teclado(evento) {
        if (!this.abierto) {
            return;
        }
        if (evento.key === 'Escape') {
            evento.preventDefault();
            this.cerrar();
        } else if (evento.key === 'ArrowLeft') {
            evento.preventDefault();
            this.anterior();
        } else if (evento.key === 'ArrowRight') {
            evento.preventDefault();
            this.siguiente();
        }
    },

    // Tab y Shift+Tab no salen del popup.
    atraparFoco(evento) {
        const enfocables = [...this.$refs.dialogo.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            .filter((elemento) => elemento.offsetParent !== null);
        if (enfocables.length === 0) {
            evento.preventDefault();
            return;
        }

        const primero = enfocables[0];
        const ultimo = enfocables[enfocables.length - 1];
        if (evento.shiftKey && (document.activeElement === primero || document.activeElement === this.$refs.dialogo)) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && document.activeElement === ultimo) {
            evento.preventDefault();
            primero.focus();
        }
    },
});
