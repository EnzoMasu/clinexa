/*
 * Pantalla de cobro (resources/views/admin/cobros/create.blade.php, docs/cobros-caja.md): suma en vivo las prestaciones tildadas y
 * los montos de los pagos, muestra cuánto falta o sobra y, al tildar, completa el primer pago si es el único con monto. Solo
 * ayuda: el servidor vuelve a verificar todo (sin JavaScript el formulario funciona igual). No guarda nada en el navegador.
 */
const entero = (texto) => {
    const limpio = String(texto ?? '').trim().replace(/\./g, '');
    return /^\d+$/.test(limpio) ? parseInt(limpio, 10) : 0;
};

const formatear = (monto) => '₲ ' + new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(monto).replace(/,/g, '.');

export default () => ({
    total: 0,
    pagos: 0,

    init() {
        this.recalcular();
    },

    recalcular() {
        this.total = [...this.$root.querySelectorAll('input[data-monto]:checked')].reduce((suma, casilla) => suma + entero(casilla.dataset.monto), 0);
        this.pagos = [...this.$root.querySelectorAll('input[data-pago]')].reduce((suma, campo) => suma + entero(campo.value), 0);
    },

    /** Al cambiar las prestaciones tildadas: si hay un solo pago con monto (o ninguno), pasa a ser el total. */
    alTildar() {
        const campos = [...this.$root.querySelectorAll('input[data-pago]')];
        const conMonto = campos.filter((campo) => entero(campo.value) > 0);
        this.recalcular();
        if (campos.length > 0 && conMonto.length <= 1) {
            const campo = conMonto[0] ?? campos[0];
            campo.value = this.total > 0 ? String(this.total) : '';
        }
        this.recalcular();
    },

    get totalTexto() {
        return formatear(this.total);
    },

    get pagosTexto() {
        return formatear(this.pagos);
    },

    get diferenciaTexto() {
        const diferencia = this.total - this.pagos;
        if (diferencia === 0) {
            return this.total > 0 ? 'Los pagos cubren el total.' : '';
        }

        return diferencia > 0 ? `Faltan ${formatear(diferencia)}.` : `Sobran ${formatear(-diferencia)}: el vuelto no se registra, cargue el monto exacto.`;
    },

    get cuadra() {
        return this.total > 0 && this.total === this.pagos;
    },
});
