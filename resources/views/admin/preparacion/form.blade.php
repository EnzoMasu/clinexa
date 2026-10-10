@use('App\Support\Fecha')

{{--
    Formulario de preparación de una consulta EN_PREPARACION o EN_CURSO: anamnesis y signos vitales (grupo
    PREPARACIÓN), con autoguardado (resources/js/consulta-autoguardado.js). Nunca lleva campos clínicos.
--}}
@php
    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm';
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
    $volver = auth()->user()->tienePermiso('PREPARACION', 'VER') ? route('admin.preparacion.index') : route('admin.atencion.index');
@endphp

<x-admin.page title="Preparación" sin-recorte>
    <div x-data="consultaAutoguardado({ url: {{ Js::from(route('admin.consultas.autoguardado', $consulta)) }}, autoguardado: true })" class="space-y-6 p-6">
        <div class="sticky top-0 z-30 -mx-6 -mt-6 space-y-2 rounded-t-lg border-b border-gray-200 bg-white px-6 py-3 dark:border-gray-700 dark:bg-gray-800">
            @include('admin.consultas._barra-paciente')
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="flex items-center gap-2 text-sm">
                    @include('admin.preparacion._estado', ['consulta' => $consulta])
                    @if ($consulta->enCurso())
                        <x-admin.estado-badge :estado="$consulta->estado" />
                    @endif
                </span>
                @include('admin.consultas._guardado')
            </div>
        </div>

        <form x-ref="formulario" method="POST" action="{{ route('admin.preparacion.lista', $consulta) }}" novalidate
            x-on:input="cambio()" x-on:change="cambio()" x-on:click="$event.target.closest('button[type=button]') && cambio()"
            x-on:submit="enviar($event)" class="max-w-5xl space-y-8">
            @csrf
            {{-- Control de concurrencia: el autoguardado la actualiza en cada guardado. --}}
            <input type="hidden" name="version" value="{{ $consulta->version() }}">

            @include('admin.consultas.secciones._anamnesis')
            @include('admin.consultas.secciones._signos')

            <noscript><p class="text-sm text-red-600">Para cargar la preparación se necesita JavaScript habilitado.</p></noscript>

            <div class="flex flex-wrap items-center gap-4">
                @if ($puedeMarcar)
                    @if ($consulta->preparada_en)
                        <button type="submit" formaction="{{ route('admin.preparacion.reabrir', $consulta) }}" class="{{ $boton }}">Reabrir</button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Marcada como lista el {{ Fecha::mostrar($consulta->preparada_en, conHora: true) }}.</span>
                    @else
                        <x-primary-button>Marcar como lista</x-primary-button>
                    @endif
                @endif
                <a href="{{ $volver }}" class="text-sm text-gray-600 hover:underline dark:text-gray-400">Volver</a>
            </div>
        </form>

        @include('admin.consultas._cartel-guardado')
    </div>
</x-admin.page>
