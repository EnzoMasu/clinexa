// Alta de turno: con profesional y fecha elegidos, trae los horarios libres (endpoint
// turnos/horarios-disponibles) sin recargar la página y los muestra como botones. El elegido va en
// el input oculto hora_inicio; el consultorio lo resuelve el servidor a partir de ese horario.
//
// Escucha los eventos de los otros componentes del formulario: "elegido" (buscador de profesional)
// y "fecha-cambiada" (campo de fecha).

export default ({ url, profesionalId = null, fecha = '', hora = '' }) => ({
    profesionalId,
    fecha,
    hora, // hora_inicio elegida ("08:30")
    horarios: [],
    cargando: false,
    cargado: false,
    error: false,
    peticion: null,

    init() {
        this.cargar();
    },

    alElegir(evento) {
        if (evento.detail.campo !== 'profesional_id') return;
        this.profesionalId = evento.detail.id;
        this.cargar();
    },

    alCambiarFecha(evento) {
        if (evento.detail.campo !== 'fecha') return;
        this.fecha = evento.detail.valor;
        this.cargar();
    },

    get listoParaBuscar() {
        return Boolean(this.profesionalId) && /^\d{2}\/\d{2}\/\d{4}$/.test(this.fecha ?? '');
    },

    async cargar() {
        this.peticion?.abort();

        if (! this.listoParaBuscar) {
            this.horarios = [];
            this.cargado = false;
            this.hora = '';
            return;
        }

        const peticion = new AbortController();
        this.peticion = peticion;
        this.cargando = true;
        this.error = false;

        try {
            const respuesta = await fetch(`${url}?${new URLSearchParams({ profesional_id: this.profesionalId, fecha: this.fecha })}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: peticion.signal,
            });
            if (this.peticion !== peticion) return;

            this.horarios = respuesta.ok ? await respuesta.json() : [];
            this.error = ! respuesta.ok;
            this.cargado = true;
            // Si el horario elegido ya no está libre (cambió el profesional o la fecha), se descarta.
            if (! this.horarios.some((horario) => horario.hora_inicio === this.hora)) this.hora = '';
        } catch (error) {
            if (error.name !== 'AbortError' && this.peticion === peticion) {
                this.horarios = [];
                this.error = true;
                this.cargado = true;
            }
        } finally {
            if (this.peticion === peticion) this.cargando = false;
        }
    },

    elegir(horario) {
        this.hora = horario.hora_inicio;
    },
});
