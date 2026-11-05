/**
 * Formulario de compra (docs/compras-gastos.md): muestra u oculta lo que pide el tipo de comprobante elegido (número, timbrado, fecha del
 * comprobante, desglose del IVA por tasa o un único total) y lo que lleva la condición (vencimiento y anticipo solo a crédito). Las
 * casillas de cada tipo vienen del servidor; el script nunca mira el nombre del tipo. Lo oculto se deshabilita (no se envía) y el
 * servidor vuelve a validar todo. Sin datos en el navegador: solo suma lo que se escribe, en pantalla.
 */
const entero = (texto) => parseInt(String(texto ?? '').replace(/\./g, ''), 10) || 0;

export default ({ casillas = {}, tipo = '', condicion = 'CONTADO', monto10 = '', monto5 = '', montoExento = '' } = {}) => ({
    casillas,
    tipo: String(tipo ?? ''),
    condicion,
    monto10,
    monto5,
    montoExento,

    get reglas() {
        return this.casillas[this.tipo] ?? {};
    },

    get conTipo() {
        return this.tipo !== '';
    },

    get esCredito() {
        return this.condicion === 'CREDITO';
    },

    get totalDesglose() {
        return (entero(this.monto10) + entero(this.monto5) + entero(this.montoExento)).toLocaleString('es-PY');
    },
});
