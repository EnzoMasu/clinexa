/**
 * Selector de ciudad en cascada (componente x-geografia.selector-ciudad): al elegir País se piden
 * sus Departamentos, al elegir Departamento sus Ciudades, por fetch y sin recargar la página.
 * Solo el select de ciudad tiene name (ciudad_id): país y departamento sirven para filtrar.
 */
export default ({ urlDepartamentos, urlCiudades }) => ({
    pedido: 0,

    async cambiarPais() {
        this.llenar(this.$refs.departamento, []);
        this.llenar(this.$refs.ciudad, []);

        const pais = this.$refs.pais.value;
        if (pais) {
            this.llenar(this.$refs.departamento, await this.pedir(urlDepartamentos, { pais_id: pais }));
        }
    },

    async cambiarDepartamento() {
        this.llenar(this.$refs.ciudad, []);

        const departamento = this.$refs.departamento.value;
        if (departamento) {
            this.llenar(this.$refs.ciudad, await this.pedir(urlCiudades, { departamento_id: departamento }));
        }
    },

    async pedir(url, parametros) {
        const numero = ++this.pedido; // si el usuario cambia rápido, solo vale la última respuesta
        try {
            const respuesta = await fetch(`${url}?${new URLSearchParams(parametros)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const opciones = respuesta.ok ? await respuesta.json() : [];

            return numero === this.pedido ? opciones : [];
        } catch {
            return [];
        }
    },

    llenar(select, opciones) {
        select.replaceChildren(new Option('—', ''), ...opciones.map((opcion) => new Option(opcion.nombre, opcion.id)));
        select.disabled = opciones.length === 0;
    },
});
