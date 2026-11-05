{{--
    Ventana emergente "Nueva persona" con el formulario completo de Personas (admin.personas._campos), para
    crear una persona sin salir del formulario de origen. La dibuja x-admin.selector-persona (prop "ruta-alta-rapida")
    con @push('modales'), fuera del formulario (los formularios no se anidan). Si se crea, el selector la elige.

    "ruta": nombre de la ruta POST que crea (cada rol tiene la suya y su permiso <MÓDULO>,CREAR).
    "campo": el name del selector que la elige. "tipos": los tipos de persona que se ofrecen (los del rol).
    "conRol": si la ruta crea además el registro del rol (p. ej. 'paciente'), para decirlo en la ventana.
--}}
@props(['url', 'campo', 'tipos' => ['FISICA', 'JURIDICA'], 'conRol' => null, 'destino' => 'este formulario'])

@php
    use App\Http\Controllers\Admin\PersonaController;
    use App\Models\Pais;
    use App\Models\Persona;
    use App\Models\TipoDocumento;

    $persona = new Persona([
        'tipo_persona' => in_array('FISICA', $tipos, true) ? 'FISICA' : $tipos[0],
        'tipo_documento_id' => TipoDocumento::predeterminadoPara('PERSONAS'),
        'pais_nacionalidad_id' => Pais::idParaguay(),
    ]);
    $tiposDocumento = PersonaController::tiposDocumento();
    $paises = Pais::opciones();
    $tiposPersona = $tipos;
@endphp

<x-modal :name="'alta-rapida-persona-'.$campo" max-width="2xl" focusable>
    <form x-data="altaRapidaPersona({ url: @js($url), campo: @js($campo), tipoInicial: @js($persona->tipo_persona) })"
        x-on:abrir-alta-rapida-persona.window="if ($event.detail.campo === campo) abrir($event.detail.numero)" x-on:submit.prevent="guardar()"
        class="space-y-6 p-6" novalidate>
        @csrf
        <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Nueva persona</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                @if ($conRol)
                    Se guarda la persona y su ficha de {{ $conRol }} (número de ficha automático, sin cobertura: el seguro se carga después), y queda elegida en {{ $destino }}.
                @else
                    Se guarda como en Personas y queda elegida en {{ $destino }}.
                @endif
                Lo que ya cargó no se pierde.
            </p>
        </div>

        <div x-show="errores.length" style="display: none" role="alert"
            class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
            <p class="font-medium">Revise los datos:</p>
            <ul class="mt-1 list-disc pl-5">
                <template x-for="error in errores"><li x-text="error"></li></template>
            </ul>
        </div>

        @include('admin.personas._campos', ['persona' => $persona])

        <div class="flex justify-end gap-3">
            <x-secondary-button type="button" x-on:click="cerrar()">Cancelar</x-secondary-button>
            <x-primary-button x-bind:disabled="guardando">Guardar persona</x-primary-button>
        </div>
    </form>
</x-modal>
