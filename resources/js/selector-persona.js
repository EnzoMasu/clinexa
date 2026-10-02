/**
 * Buscador de persona para dar de alta un rol (componente x-admin.selector-persona): busca por
 * nombre o documento mientras se escribe (fetch al endpoint "personas-disponibles" del rol, que
 * ya excluye a las que tienen el rol) y guarda la elegida en un input oculto persona_id.
 *
 * Mismo mecanismo que el buscador en vivo de los listados: debounce de 350 ms (en la vista) y
 * cancelación de la petición anterior con AbortController. No busca al abrir el formulario ni con
 * menos de "minimo" caracteres (el servidor tampoco responde con menos).
 *
 * Al elegir o cambiar avisa con el evento "elegido" ({ campo, id }), para que el formulario que
 * lo contiene pueda reaccionar (p. ej. el alta de turno carga los horarios del profesional).
 */
export default ({ url, inicial, minimo, campo = 'persona_id' }) => ({
    q: '',
    resultados: [],
    elegida: inicial, // { id, texto } o null
    buscado: false,
    cargando: false,
    peticion: null,

    get faltanCaracteres() {
        return this.q.trim().length < minimo;
    },

    async buscar() {
        this.peticion?.abort(); // descarta una búsqueda anterior que todavía no respondió

        const texto = this.q.trim();
        if (texto.length < minimo) {
            this.peticion = null;
            this.resultados = [];
            this.buscado = false;
            this.cargando = false;
            return;
        }

        const peticion = new AbortController();
        this.peticion = peticion;
        this.cargando = true;

        try {
            const respuesta = await fetch(`${url}?${new URLSearchParams({ q: texto })}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: peticion.signal,
            });
            const personas = respuesta.ok ? await respuesta.json() : [];
            if (this.peticion === peticion) {
                this.resultados = personas;
                this.buscado = true;
            }
        } catch (error) {
            if (error.name !== 'AbortError' && this.peticion === peticion) {
                this.resultados = [];
                this.buscado = true;
            }
        } finally {
            // Solo la petición vigente apaga el indicador (una cancelada no debe hacerlo).
            if (this.peticion === peticion) {
                this.cargando = false;
            }
        }
    },

    elegir(persona) {
        this.elegida = persona;
        this.$dispatch('elegido', { campo, id: persona.id });
    },

    cambiar() {
        this.elegida = null;
        this.$dispatch('elegido', { campo, id: null });
        this.$nextTick(() => this.$refs.busqueda.focus());
    },
});
