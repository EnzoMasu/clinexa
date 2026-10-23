{{--
    Corrección de una consulta finalizada con un desbloqueo vigente (CorreccionController::corregir). Un solo
    guardado: la consulta vuelve a cerrarse. Solo las secciones corregibles: anamnesis, hallazgos, motivo y diagnósticos,
    indicaciones, informes de estudio (los signos vitales, el triaje y las recetas no se tocan). Sin autoguardado. Todo escapado.
--}}
@use('App\Support\Fecha')

@php
    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm';
    $secciones = ['anamnesis' => 'Anamnesis', 'examen' => 'Hallazgos', 'motivo' => 'Motivo y diagnósticos', 'indicaciones' => 'Indicaciones', 'estudios' => 'Informe de estudio'];
    $panelInicial = $errors->hasAny(['motivo_consulta', 'diagnostico*']) ? 'motivo' : 'anamnesis';
@endphp

<x-admin.page title="Corregir consulta">
    <div x-data="consultaAutoguardado({ autoguardado: false, panel: {{ Js::from($panelInicial) }} })" class="space-y-4 p-4 sm:p-6">
        @include('admin.consultas._barra-paciente')

        <p role="status" class="rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
            Desbloqueada hasta el {{ Fecha::mostrar($desbloqueo->vence_en, conHora: true) }}, para un solo guardado.
            Motivo: {{ $desbloqueo->motivo }}
        </p>
        @if (session('error'))
            <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
        @endif
        <x-input-error :messages="$errors->get('correccion')" />

        <nav class="flex flex-wrap gap-2" aria-label="Secciones de la consulta">
            @foreach ($secciones as $clave => $nombre)
                <button type="button" x-on:click="ver('{{ $clave }}')" x-bind:aria-pressed="(panel === '{{ $clave }}').toString()"
                    x-bind:class="{ 'bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-800 border-transparent': panel === '{{ $clave }}' }"
                    class="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $nombre }}</button>
            @endforeach
        </nav>

        <form id="form-consulta" x-ref="formulario" method="POST" novalidate action="{{ route('admin.correcciones.corregir.guardar', $desbloqueo) }}"
            x-on:input="cambio()" x-on:change="cambio()" x-on:click="$event.target.closest('button[type=button]') && cambio()" x-on:submit="enviar($event)" class="space-y-6">
            @csrf
            @foreach (['anamnesis' => ['secciones._anamnesis'], 'examen' => ['secciones._hallazgos'], 'motivo' => ['secciones._diagnosticos'], 'indicaciones' => ['secciones._indicaciones'], 'estudios' => ['secciones._informes']] as $clave => $vistas)
                <section data-panel="{{ $clave }}" x-show="panel === '{{ $clave }}'" class="space-y-6" aria-label="{{ $secciones[$clave] }}">
                    @foreach ($vistas as $vista)
                        @include('admin.consultas.'.$vista)
                    @endforeach
                </section>
            @endforeach

            <div class="flex flex-wrap items-center gap-4">
                <x-primary-button>Guardar corrección</x-primary-button>
                <a href="{{ route('admin.consultas.show', $consulta) }}" class="text-sm text-gray-600 hover:underline dark:text-gray-400">Volver sin guardar</a>
            </div>
            <noscript><p class="text-sm text-red-600">Para corregir la consulta se necesita JavaScript habilitado.</p></noscript>
        </form>

        <form method="POST" action="{{ route('admin.correcciones.cancelar', $desbloqueo) }}" x-data x-on:submit="if (! confirm('¿Cancelar el desbloqueo? La consulta sigue cerrada.')) $event.preventDefault()">
            @csrf
            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Cancelar el desbloqueo</button>
        </form>
    </div>
</x-admin.page>
