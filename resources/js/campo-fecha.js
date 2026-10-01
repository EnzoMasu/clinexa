// Campo de fecha en formato dd/mm/aaaa, igual en todos los navegadores (el <input type="date">
// nativo muestra el formato del idioma del navegador y no se puede controlar). Se escribe con las
// barras automáticas o se elige en un calendario desplegable. Lo que se envía es el texto
// dd/mm/aaaa; el servidor lo valida y lo guarda en ISO.
//
// Uso: x-data="campoFecha({ valor: '05/03/1990', max: '2026-10-01' })" con un input
// x-bind:value="valor" x-on:input="escribir($event)" y la vista del calendario
// (componente Blade x-admin.calendario). Con x-modelable="valor" se puede enlazar con x-model.

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const DIAS = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];

const dosDigitos = (n) => String(n).padStart(2, '0');

export const formatear = (fecha) => `${dosDigitos(fecha.getDate())}/${dosDigitos(fecha.getMonth() + 1)}/${fecha.getFullYear()}`;

// "05/03/1990" -> Date (o null si no es una fecha real, p. ej. 31/02/2020).
export const parsear = (texto) => {
    const partes = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(texto ?? '');
    if (! partes) return null;
    const [, dia, mes, anio] = partes.map(Number);
    const fecha = new Date(anio, mes - 1, dia);
    return fecha.getFullYear() === anio && fecha.getMonth() === mes - 1 && fecha.getDate() === dia ? fecha : null;
};

// Lo que se va escribiendo -> dd/mm/aaaa con las barras puestas (solo dígitos, como mucho 8).
export const enmascarar = (texto) => {
    const digitos = (texto ?? '').replace(/\D/g, '').slice(0, 8);
    return [digitos.slice(0, 2), digitos.slice(2, 4), digitos.slice(4)].filter(Boolean).join('/');
};

const mismoDia = (a, b) => a && b && a.toDateString() === b.toDateString();

export default ({ valor = '', max = null } = {}) => ({
    valor: valor ?? '',
    abierto: false,
    mes: 0,
    anio: 0,
    meses: MESES,
    nombresDias: DIAS,
    maximo: max ? new Date(`${max}T00:00:00`) : null,

    escribir(evento) {
        this.valor = enmascarar(evento.target.value);
        evento.target.value = this.valor;
    },

    abrir() {
        const hoy = new Date();
        const base = parsear(this.valor) ?? (this.maximo && this.maximo < hoy ? this.maximo : hoy);
        this.mes = base.getMonth();
        this.anio = base.getFullYear();
        this.abierto = true;
    },

    cerrar() {
        this.abierto = false;
    },

    elegir(dia) {
        if (dia.deshabilitado) return;
        this.valor = formatear(dia.fecha);
        this.abierto = false;
        this.$refs.entrada?.focus();
    },

    moverMes(delta) {
        const fecha = new Date(this.anio, this.mes + delta, 1);
        this.mes = fecha.getMonth();
        this.anio = fecha.getFullYear();
    },

    // Años del selector: de 1900 al año máximo permitido (o 10 años adelante si no hay máximo).
    get anios() {
        const hasta = this.maximo ? this.maximo.getFullYear() : new Date().getFullYear() + 10;
        return Array.from({ length: hasta - 1900 + 1 }, (_, i) => hasta - i);
    },

    // Semanas del mes (de lunes a domingo), con los días del mes anterior/siguiente para completar.
    get dias() {
        const primero = new Date(this.anio, this.mes, 1);
        const inicio = new Date(this.anio, this.mes, 1 - ((primero.getDay() + 6) % 7));
        const elegida = parsear(this.valor);
        const hoy = new Date();

        return Array.from({ length: 42 }, (_, i) => {
            const fecha = new Date(inicio.getFullYear(), inicio.getMonth(), inicio.getDate() + i);
            return {
                clave: fecha.toDateString(),
                numero: fecha.getDate(),
                fecha,
                delMes: fecha.getMonth() === this.mes,
                elegido: mismoDia(fecha, elegida),
                hoy: mismoDia(fecha, hoy),
                deshabilitado: this.maximo !== null && fecha > this.maximo,
            };
        });
    },
});
