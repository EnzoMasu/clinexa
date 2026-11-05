/**
 * Pantalla "Factura lista para imprimir": intenta imprimir el PDF embebido; si el navegador no lo permite (visor de PDF propio,
 * origen distinto), abre el PDF en una pestaña nueva. No guarda nada ni pide nada más que el PDF.
 */
export default ({ url }) => ({
    imprimir() {
        try {
            const marco = this.$refs.pdf;
            marco.contentWindow.focus();
            marco.contentWindow.print();
        } catch (error) {
            window.open(url, '_blank', 'noopener');
        }
    },
});
