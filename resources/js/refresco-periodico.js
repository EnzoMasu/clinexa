/**
 * Listas que se actualizan solas (pantallas Consulta y Preparación): cada 30 s pide el fragmento al mismo
 * endpoint (X-Requested-With) y reemplaza el elemento [data-refresco] (la raíz del fragmento _tabla) con el
 * HTML ya renderizado y escapado por el servidor. Sin url, pide la URL actual: así respeta los filtros del
 * listado en vivo, que la mantiene al día. Se pausa con la pestaña oculta y se detiene ante sesión vencida o falta de
 * permiso (para no insistir; al recargar se ve el login o la pantalla 403). Estas actualizaciones no
 * registran lecturas en la auditoría.
 */
import fragmentoInerte from './fragmento-inerte';

export default ({ url = null, intervalo = 30000 } = {}) => ({
    detenido: false,
    abiertos: {}, // secciones plegables abiertas: se mantienen al reemplazar el fragmento
    temporizador: null,
    peticion: null,

    init() {
        this.temporizador = setInterval(() => this.actualizar(), intervalo);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.actualizar();
            }
        });
    },

    destroy() {
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
            const respuesta = await fetch(url ?? window.location.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                credentials: 'same-origin',
                signal: peticion.signal,
            });
            if (respuesta.redirected || [401, 403, 419].includes(respuesta.status)) {
                this.detenido = true;
                clearInterval(this.temporizador);
                return;
            }
            if (respuesta.ok && this.peticion === peticion) {
                // Sin scripts ni atributos on* (fragmento-inerte.js); reemplaza al fragmento anterior.
                this.$root.querySelector('[data-refresco]')?.replaceWith(fragmentoInerte(await respuesta.text()));
            }
        } catch (error) {
            // Red caída o pedido cancelado: se reintenta en el próximo intervalo.
        }
    },
});
