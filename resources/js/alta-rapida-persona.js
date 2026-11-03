/**
 * Alta rápida de persona en el formulario de Proveedor (popup x-modal "alta-rapida-persona"). Se abre con
 * el evento de ventana "abrir-alta-rapida-persona" ({ numero }) desde el selector cuando no hay
 * resultados: el número buscado se precarga como RUC (si tiene guion) o como número de documento.
 *
 * Envía los mismos campos que el alta de Personas (las validaciones son las del servidor). Si se crea,
 * avisa con "persona-creada" ({ campo, persona: { id, texto } }) para que el selector la elija, y cierra.
 * No guarda nada en el navegador.
 */
export default ({ url, campo = 'persona_id', tipoInicial = 'FISICA' }) => ({
    tipo: tipoInicial,
    errores: [],
    guardando: false,

    abrir(numero = '') {
        this.$root.reset();
        this.tipo = tipoInicial;
        this.errores = [];
        const valor = String(numero ?? '').trim();
        const destino = valor.includes('-') ? 'ruc' : 'nro_documento';
        const entrada = this.$root.querySelector(`[name="${destino}"]`);
        if (entrada && valor !== '') {
            entrada.value = valor;
        }
        this.$dispatch('open-modal', 'alta-rapida-persona');
    },

    cerrar() {
        this.$dispatch('close-modal', 'alta-rapida-persona');
    },

    async guardar() {
        if (this.guardando) return;
        this.guardando = true;
        this.errores = [];

        try {
            const respuesta = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(this.$root),
            });

            if (respuesta.status === 201) {
                const persona = await respuesta.json();
                window.dispatchEvent(new CustomEvent('persona-creada', { detail: { campo, persona } }));
                this.cerrar();
            } else if (respuesta.status === 422) {
                const datos = await respuesta.json();
                this.errores = Object.values(datos.errors ?? {}).flat();
            } else if (respuesta.status === 429) {
                this.errores = ['Hizo demasiados intentos seguidos. Espere un minuto y vuelva a intentar.'];
            } else {
                this.errores = ['No se pudo guardar la persona. Intente de nuevo.'];
            }
        } catch {
            this.errores = ['No se pudo guardar la persona. Revise la conexión e intente de nuevo.'];
        } finally {
            this.guardando = false;
        }
    },
});
