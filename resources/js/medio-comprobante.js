/*
 * Medio de pago y comprobante (pago de liquidaciones a beneficiarios, corrección del comprobante de un cobro): el desplegable
 * muestra solo el nombre del medio; al elegirlo, el campo "Comprobante" toma el nombre que el medio le da ("Voucher del POS",
 * "Número del cheque") y queda obligatorio si el medio lo exige. Cada <option> trae data-comprobante cuando el medio lo exige.
 * Solo ayuda: el servidor valida igual (sin JavaScript el formulario funciona). No guarda nada en el navegador.
 */
export default (opciones = {}) => ({
    sinMedio: opciones.sinMedio ?? 'Comprobante (obligatorio salvo en efectivo)',
    sinExigir: opciones.sinExigir ?? 'Comprobante (opcional)',

    init() {
        this.actualizar();
    },

    actualizar() {
        const select = this.$root.querySelector('select[data-medio]');
        const campo = this.$root.querySelector('input[data-comprobante]');
        const etiqueta = this.$root.querySelector('[data-etiqueta-comprobante]');
        if (!select || !campo || !etiqueta) {
            return;
        }
        const nombre = select.selectedOptions[0]?.dataset.comprobante;
        campo.required = Boolean(nombre);
        campo.setAttribute('aria-required', nombre ? 'true' : 'false');
        etiqueta.textContent = nombre ? `${nombre} (obligatorio)` : select.value ? this.sinExigir : this.sinMedio;
    },
});
