// Alta de turno: con profesional y fecha elegidos, trae los horarios libres (endpoint
// turnos/horarios-disponibles) sin recargar la página y los muestra como botones. El elegido va en
// el input oculto hora_inicio; el consultorio lo resuelve el servidor a partir de ese horario.
//
// Escucha los eventos de los otros componentes del formulario: "elegido" (buscador de profesional)
// y "fecha-cambiada" (campo de fecha).
//
// Con procedimiento, el turno dura lo del procedimiento (duraciones: id => minutos), ajustable de 5 a 480
// minutos: los horarios se piden para esa duración (el servidor vuelve a validar todo al guardar).

const DURACION_MINIMA = 5;
const DURACION_MAXIMA = 480;
// Un procedimiento sin duración cargada (vacía o 0) no la tiene: el turno dura el bloque, como sin procedimiento.
const limitar = (minutos) => (minutos !== null && Number.isFinite(Number(minutos)) && Number(minutos) > 0
    ? Math.min(DURACION_MAXIMA, Math.max(DURACION_MINIMA, Math.round(Number(minutos)))) : null);

export default ({ url, urlCupo = '', modalidad = 'con_horario', profesionalId = null, fecha = '', hora = '', duraciones = {}, procedimientoId = '', duracion = null }) => ({
    modalidad, // "con_horario" o "entre_pacientes"
    cupo: null, // { cupo, usados } del profesional y la fecha (solo para informar)
    peticionCupo: null,
    profesionalId,
    fecha,
    hora, // hora_inicio elegida ("08:30")
    procedimientoId: procedimientoId ?? '',
    // Minutos, solo con procedimiento: lo enviado antes (tras un error) o la del procedimiento, dentro de los límites.
    duracion: duracion !== null && duracion !== '' ? Number(duracion) : limitar(procedimientoId ? duraciones[procedimientoId] : null),
    horarios: [],
    bloqueMinutos: null, // el bloque normal del día (cabecera X-Bloque-Minutos)
    cargando: false,
    cargado: false,
    error: false,
    peticion: null,

    init() {
        this.cargar();
        this.$watch('modalidad', () => this.cargarCupo());
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

    // Al elegir otro procedimiento, la duración vuelve a la suya (dentro de los límites).
    alCambiarProcedimiento() {
        this.duracion = limitar(this.procedimientoId ? duraciones[this.procedimientoId] : null);
        this.cargar();
    },

    // Los horarios se pidieron para la duración del procedimiento (con horario y procedimiento elegido).
    get conDuracion() {
        return this.modalidad === 'con_horario' && Boolean(this.procedimientoId) && Number.isInteger(this.duracion);
    },

    // Cualquier procedimiento cuya duración pase el bloque normal del día: avisos de turno largo.
    get duracionLarga() {
        return this.conDuracion && this.bloqueMinutos !== null && this.duracion > this.bloqueMinutos;
    },

    get listoParaBuscar() {
        return Boolean(this.profesionalId) && /^\d{2}\/\d{2}\/\d{4}$/.test(this.fecha ?? '');
    },

    async cargar() {
        this.cargarCupo();
        this.peticion?.abort();

        if (! this.listoParaBuscar) {
            this.horarios = [];
            this.bloqueMinutos = null;
            this.cargado = false;
            this.hora = '';
            return;
        }

        const peticion = new AbortController();
        this.peticion = peticion;
        this.cargando = true;
        this.error = false;

        try {
            const parametros = { profesional_id: this.profesionalId, fecha: this.fecha };
            if (this.procedimientoId) {
                parametros.procedimiento_id = this.procedimientoId;
                if (Number.isInteger(this.duracion)) parametros.duracion_minutos = this.duracion;
            }
            const respuesta = await fetch(`${url}?${new URLSearchParams(parametros)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: peticion.signal,
            });
            if (this.peticion !== peticion) return;

            const bloque = Number.parseInt(respuesta.headers.get('X-Bloque-Minutos') ?? '', 10);
            this.bloqueMinutos = respuesta.ok && Number.isInteger(bloque) ? bloque : null;
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

    // Cupo entre pacientes del profesional en esa fecha (turnos/cupo-del-dia): solo con esa modalidad.
    async cargarCupo() {
        this.peticionCupo?.abort();
        if (this.modalidad !== 'entre_pacientes' || ! this.listoParaBuscar || ! urlCupo) {
            this.cupo = null;
            return;
        }

        const peticion = new AbortController();
        this.peticionCupo = peticion;
        try {
            const respuesta = await fetch(`${urlCupo}?${new URLSearchParams({ profesional_id: this.profesionalId, fecha: this.fecha })}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: peticion.signal,
            });
            const datos = respuesta.ok ? await respuesta.json() : null;
            if (this.peticionCupo === peticion) this.cupo = datos && 'cupo' in datos ? datos : null;
        } catch (error) {
            if (error.name !== 'AbortError' && this.peticionCupo === peticion) this.cupo = null;
        }
    },

    elegir(horario) {
        this.hora = horario.hora_inicio;
    },
});
