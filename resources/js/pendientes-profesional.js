/**
 * Pantalla Consulta: botones "Entre pacientes (N)" y "Urgencias (N)", aviso de urgencia nueva y sus popups.
 *
 * - Contadores: cada 15 s pide solo conteos y la última urgencia (id de turno y nombre del paciente), sin contenido
 *   clínico ni registro de lecturas. Se pausa con la pestaña oculta y se detiene ante sesión vencida o falta de
 *   permiso (redirección, 401, 403, 419), como refresco-periodico.js.
 * - Aviso: cuando aparece una urgencia que no estaba, un cartel fijo "Nueva urgencia: NOMBRE" (solo el nombre) con
 *   "Ver urgencias". Se cierra al abrir el popup o con la X y no reaparece por la misma urgencia (los ids vistos
 *   quedan solo en memoria: nada se guarda en el navegador). Sin sonido. El título de la pestaña lleva
 *   "(N) Urgencias" mientras haya.
 * - Popups: se cargan por fetch solo al hacer clic; el HTML viene del servidor ya escapado y se inserta con
 *   fragmento-inerte.js. Diálogo modal accesible: foco adentro, Esc, y el foco vuelve al botón al cerrar; al cerrar
 *   se vacía (lo clínico no queda en la página).
 */
import fragmentoInerte from './fragmento-inerte';

export default ({ urls, iniciales, intervalo = 15000 }) => ({
    entrePacientes: iniciales.entre_pacientes,
    urgencias: iniciales.urgencias,
    vistas: new Set(iniciales.ultima ? [iniciales.ultima.id] : []),
    aviso: null, // { id, nombre } de la urgencia nueva
    popup: null, // 'entre-pacientes' | 'urgencias'
    estado: 'cargando', // cargando | listo | error
    detenido: false,
    temporizador: null,
    peticion: null,
    peticionPopup: null,
    origen: null,
    tituloBase: document.title,

    init() {
        this.actualizarTitulo();
        this.temporizador = setInterval(() => this.actualizar(), intervalo);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.actualizar();
            }
        });
    },

    destroy() {
        clearInterval(this.temporizador);
        document.title = this.tituloBase;
    },

    get tituloPopup() {
        return this.popup === 'urgencias' ? 'Urgencias' : 'Entre pacientes';
    },

    actualizarTitulo() {
        document.title = this.urgencias > 0 ? `(${this.urgencias}) Urgencias · ${this.tituloBase}` : this.tituloBase;
    },

    detener() {
        this.detenido = true;
        clearInterval(this.temporizador);
    },

    async actualizar() {
        if (this.detenido || document.hidden) {
            return;
        }
        this.peticion?.abort();
        const peticion = new AbortController();
        this.peticion = peticion;

        try {
            const respuesta = await fetch(urls.contadores, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
                signal: peticion.signal,
            });
            if (respuesta.redirected || [401, 403, 419].includes(respuesta.status)) {
                this.detener();
                return;
            }
            if (!respuesta.ok || this.peticion !== peticion) {
                return;
            }
            const datos = await respuesta.json();
            this.entrePacientes = Number(datos.entre_pacientes) || 0;
            this.urgencias = Number(datos.urgencias) || 0;
            this.actualizarTitulo();
            if (datos.ultima && !this.vistas.has(datos.ultima.id)) {
                this.vistas.add(datos.ultima.id);
                if (this.popup !== 'urgencias') {
                    this.aviso = { id: datos.ultima.id, nombre: String(datos.ultima.nombre) };
                }
            }
        } catch (error) {
            // Red caída o pedido cancelado: se reintenta en el próximo intervalo.
        }
    },

    cerrarAviso() {
        this.aviso = null;
    },

    abrir(cual, evento) {
        this.origen = evento?.currentTarget ?? null;
        this.popup = cual;
        if (cual === 'urgencias') {
            this.aviso = null;
        }
        document.documentElement.style.overflow = 'hidden';
        this.$nextTick(() => this.$refs.dialogo.focus());
        this.cargar();
    },

    cerrar() {
        if (!this.popup) {
            return;
        }
        this.peticionPopup?.abort();
        this.peticionPopup = null;
        this.popup = null;
        this.$refs.contenido.replaceChildren();
        document.documentElement.style.overflow = '';
        this.origen?.focus();
    },

    async cargar() {
        this.peticionPopup?.abort();
        const peticion = new AbortController();
        this.peticionPopup = peticion;
        this.estado = 'cargando';
        this.$refs.contenido.replaceChildren();

        try {
            const respuesta = await fetch(this.popup === 'urgencias' ? urls.urgencias : urls.entrePacientes, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                credentials: 'same-origin',
                signal: peticion.signal,
            });
            if (this.peticionPopup !== peticion) {
                return;
            }
            // Sesión vencida o sin permiso: la página completa muestra el login o el 403.
            if (respuesta.redirected || [401, 403, 419].includes(respuesta.status)) {
                window.location.assign(urls.pagina);
                return;
            }
            if (!respuesta.ok) {
                this.estado = 'error';
                return;
            }
            const html = await respuesta.text();
            if (this.peticionPopup !== peticion) {
                return;
            }
            this.$refs.contenido.replaceChildren(fragmentoInerte(html));
            this.estado = 'listo';
        } catch (error) {
            if (error.name !== 'AbortError' && this.peticionPopup === peticion) {
                this.estado = 'error';
            }
        }
    },

    teclado(evento) {
        if (this.popup && evento.key === 'Escape') {
            evento.preventDefault();
            this.cerrar();
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
