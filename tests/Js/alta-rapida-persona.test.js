// @vitest-environment jsdom
import { readFileSync } from 'node:fs';
import { beforeAll, describe, expect, it } from 'vitest';
import Alpine from 'alpinejs';
import altaRapidaPersona from '../../resources/js/alta-rapida-persona';
import selectorPersona from '../../resources/js/selector-persona';

// Toma las expresiones reales de las vistas Blade, para que el test falle si se desfasan del JS.
const atributo = (archivo, nombre) => {
    const html = readFileSync(archivo, 'utf8');
    const m = html.match(new RegExp(`${nombre}="([^"]*)"`));
    if (!m) throw new Error(`No se encontró ${nombre} en ${archivo}`);
    return m[1];
};
const ALTA = 'resources/views/components/admin/alta-rapida-persona.blade.php';
const SEL = 'resources/views/components/admin/selector-persona.blade.php';

describe('alta rápida de persona (clic real en "Crear")', () => {
    let abiertos;
    beforeAll(() => {
        window.Alpine = Alpine;
        Alpine.data('altaRapidaPersona', altaRapidaPersona);
        Alpine.data('selectorPersona', selectorPersona);
        abiertos = [];
        window.addEventListener('open-modal', (e) => abiertos.push(e.detail));
        document.body.innerHTML = `
            <div id="sel" x-data="selectorPersona({ url: '/x', inicial: null, minimo: 2, campo: 'paciente_id' })"
                 x-on:persona-creada.window="${atributo(SEL, 'x-on:persona-creada.window')}">
                <input type="hidden" name="paciente_id" x-bind:value="elegida ? elegida.id : ''">
                <span id="elegida" x-text="elegida?.texto"></span>
                <input id="q" x-model="q">
                <button id="crear" type="button" x-on:click="$dispatch('abrir-alta-rapida-persona', { campo, numero: q.trim() })">Crear</button>
            </div>
            <form id="alta" x-data="altaRapidaPersona({ url: '/alta', campo: 'paciente_id', tipoInicial: 'FISICA' })"
                  x-on:abrir-alta-rapida-persona.window="${atributo(ALTA, 'x-on:abrir-alta-rapida-persona.window').replace(/&quot;/g, '"')}">
                <input name="nro_documento"><input name="ruc">
            </form>
            <form id="otra" x-data="altaRapidaPersona({ url: '/alta', campo: 'proveedor_persona_id' })"
                  x-on:abrir-alta-rapida-persona.window="${atributo(ALTA, 'x-on:abrir-alta-rapida-persona.window')}">
                <input name="nro_documento"><input name="ruc">
            </form>`;
        Alpine.start();
    });

    it('al tocar Crear abre la ventana del mismo campo, con el número precargado', async () => {
        document.getElementById('q').value = '1234567';
        document.getElementById('q').dispatchEvent(new Event('input'));
        await Alpine.nextTick(() => {});
        document.getElementById('crear').click();
        expect(abiertos).toEqual(['alta-rapida-persona-paciente_id']);
        expect(document.querySelector('#alta [name="nro_documento"]').value).toBe('1234567');
    });

    it('no abre la ventana de otro campo', () => {
        expect(document.querySelector('#otra [name="nro_documento"]').value).toBe('');
        expect(abiertos).not.toContain('alta-rapida-persona-proveedor_persona_id');
    });

    it('persona-creada con el campo correcto elige la persona; con otro, no', async () => {
        window.dispatchEvent(new CustomEvent('persona-creada', { detail: { campo: 'otro', persona: { id: 9, texto: 'X' } } }));
        await Alpine.nextTick(() => {});
        expect(document.getElementById('elegida').textContent).toBe('');
        window.dispatchEvent(new CustomEvent('persona-creada', { detail: { campo: 'paciente_id', persona: { id: 7, texto: 'Ana Prueba' } } }));
        await Alpine.nextTick(() => {});
        expect(document.getElementById('elegida').textContent).toBe('Ana Prueba');
    });
});
