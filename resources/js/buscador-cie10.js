/**
 * Buscador de CIE-10 de una fila de diagnóstico (formulario de consulta). Va dentro de la fila
 * (x-for), así que ve y modifica "fila": al elegir un código completa fila.codigo_cie10 y fila.texto.
 *
 * Mismo mecanismo que el selector de personas: debounce de 350 ms (en la vista), cancelación de la
 * petición anterior con AbortController y nada con menos de "minimo" caracteres. El servidor
 * devuelve como máximo 15 códigos ACTIVOS. Los resultados se muestran con x-text (nunca como HTML).
 */
export default ({ url, minimo }) => ({
    q: '',
    resultados: [],
    buscado: false,
    cargando: false,
    peticion: null,

    get faltanCaracteres() {
        return this.q.trim().length < minimo;
    },

    async buscar() {
        this.peticion?.abort();

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
            const codigos = respuesta.ok ? await respuesta.json() : [];
            if (this.peticion === peticion) {
                this.resultados = codigos;
                this.buscado = true;
            }
        } catch (error) {
            if (error.name !== 'AbortError' && this.peticion === peticion) {
                this.resultados = [];
                this.buscado = true;
            }
        } finally {
            if (this.peticion === peticion) {
                this.cargando = false;
            }
        }
    },

    elegir(cie10) {
        this.fila.codigo_cie10 = cie10.codigo;
        this.fila.texto = `${cie10.codigo} — ${cie10.descripcion}`;
        this.q = '';
        this.resultados = [];
        this.buscado = false;
    },

    cambiar() {
        this.fila.codigo_cie10 = '';
        this.fila.texto = '';
    },
});
