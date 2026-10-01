// Verificación "al vuelo" de un campo único (endpoint compartido verificar-unico). Al salir del
// campo (blur, no en cada tecla) pregunta al servidor si el valor ya existe y, si es así, muestra
// el aviso debajo del campo. No bloquea el formulario: la validación al guardar sigue siendo la
// que manda. Se usa desde el componente Blade x-admin.input con unico="...".
//
// - ignorar: id del registro que se está editando (no se compara consigo mismo).
// - con: otros campos del formulario que forman parte de la clave (p. ej. tipo_documento_id); si
//   cambian, se vuelve a verificar.
// - minimo: con menos caracteres no se consulta.

export default ({ url, campo, ignorar = null, con = [], minimo = 2 }) => ({
    mensaje: '',
    verificado: '',
    controlador: null,

    init() {
        const formulario = this.$el.closest('form');
        con.forEach((nombre) => formulario?.elements[nombre]?.addEventListener('change', () => this.verificar(true)));
    },

    // Mientras se corrige, el aviso viejo deja de valer hasta el próximo blur.
    limpiar() {
        if (this.mensaje) this.mensaje = '';
        this.verificado = '';
    },

    async verificar(forzar = false) {
        const entrada = this.$refs.entrada;
        const valor = (entrada?.value ?? '').trim();

        if (valor.length < minimo) {
            this.mensaje = '';
            return;
        }

        const formulario = this.$el.closest('form');
        const parametros = new URLSearchParams({ campo, valor });
        if (ignorar !== null && ignorar !== '') parametros.set('ignorar', ignorar);
        con.forEach((nombre) => parametros.set(nombre, formulario?.elements[nombre]?.value ?? ''));

        const clave = parametros.toString();
        if (! forzar && clave === this.verificado) return;

        this.controlador?.abort();
        this.controlador = new AbortController();

        try {
            const respuesta = await fetch(`${url}?${clave}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.controlador.signal,
            });
            // Sin permiso o error del servidor: no se avisa nada (al guardar se valida igual).
            if (! respuesta.ok) return;

            const datos = await respuesta.json();
            this.mensaje = datos.disponible ? '' : datos.mensaje;
            this.verificado = clave;
        } catch (error) {
            if (error.name !== 'AbortError') this.mensaje = '';
        }
    },
});
