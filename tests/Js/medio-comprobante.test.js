// @vitest-environment jsdom
import { readFileSync } from 'node:fs';
import { beforeAll, describe, expect, it } from 'vitest';
import Alpine from 'alpinejs';
import medioComprobante from '../../resources/js/medio-comprobante';

const VISTAS = [
    'resources/views/admin/liquidaciones-beneficiarios/show.blade.php',
    'resources/views/admin/cobros/show.blade.php',
];

describe('medio y comprobante', () => {
    beforeAll(() => {
        window.Alpine = Alpine;
        Alpine.data('medioComprobante', medioComprobante);
        document.body.innerHTML = `
            <form id="f" x-data="medioComprobante()">
                <select data-medio x-on:change="actualizar()">
                    <option value="1">Efectivo</option>
                    <option value="2" data-comprobante="Voucher del POS">Tarjeta (POS)</option>
                </select>
                <label data-etiqueta-comprobante>x</label>
                <input data-comprobante>
            </form>`;
        Alpine.start();
    });

    it('cambia la etiqueta y lo obligatorio según el medio', async () => {
        const select = document.querySelector('select');
        const campo = document.querySelector('input');
        const etiqueta = document.querySelector('label');
        await Alpine.nextTick();
        expect(campo.required).toBe(false);
        expect(etiqueta.textContent).toBe('Comprobante (opcional)');
        select.value = '2';
        select.dispatchEvent(new Event('change'));
        expect(campo.required).toBe(true);
        expect(etiqueta.textContent).toBe('Voucher del POS (obligatorio)');
    });

    it.each(VISTAS)('la vista %s no pone "(pide …)" en el desplegable', (archivo) => {
        const html = readFileSync(archivo, 'utf8');
        expect(html).not.toMatch(/\(pide /);
        expect(html).toContain('data-etiqueta-comprobante');
    });
});
