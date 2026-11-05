/*
 * Formulario de cierre de caja (resources/views/admin/cajas/show.blade.php, docs/cobros-caja.md): los montos declarados vienen
 * precargados con lo esperado; muestra en vivo la diferencia (declarado - esperado) por medio de pago y en total, y bloquea y vacía
 * la observación cuando no hay diferencia. Solo ayuda: el servidor verifica todo (sin JavaScript funciona igual y descarta la
 * observación sin diferencia). No guarda nada en el navegador.
 */
const entero = (texto) => {
    const limpio = String(texto ?? '').trim().replace(/\./g, '');
    return /^-?\d+$/.test(limpio) ? parseInt(limpio, 10) : null;
};

const formatear = (monto) => (monto < 0 ? '-' : '') + '₲ ' + new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(Math.abs(monto)).replace(/,/g, '.');

export default () => ({
    conDiferencia: false,
    totalDeclarado: '—',
    totalDiferencia: '—',

    init() {
        this.recalcular();
    },

    recalcular() {
        let declarado = 0;
        let diferencia = 0;
        let valido = true;
        this.$root.querySelectorAll('[data-fila-arqueo]').forEach((fila) => {
            const esperado = parseInt(fila.dataset.esperado, 10) || 0;
            const monto = entero(fila.querySelector('[data-declarado]').value);
            const celda = fila.querySelector('[data-diferencia]');
            if (monto === null) {
                valido = false;
                celda.textContent = '—';
                return;
            }
            declarado += monto;
            diferencia += monto - esperado;
            celda.textContent = formatear(monto - esperado);
            celda.classList.toggle('font-semibold', monto !== esperado);
            celda.classList.toggle('text-red-700', monto !== esperado);
        });
        this.totalDeclarado = valido ? formatear(declarado) : '—';
        this.totalDiferencia = valido ? formatear(diferencia) : '—';
        // Con algún monto ilegible no se bloquea: el servidor dirá qué falta.
        this.conDiferencia = !valido || [...this.$root.querySelectorAll('[data-diferencia]')].some((celda) => celda.classList.contains('text-red-700'));
        const observacion = this.$root.querySelector('[data-observacion]');
        if (observacion) {
            observacion.disabled = !this.conDiferencia;
            if (!this.conDiferencia) {
                observacion.value = '';
            }
        }
    },
});
