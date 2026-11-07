@use('App\Support\Fecha')
@use('App\Support\LiquidacionesBeneficiarios\ArmarLiquidacionBeneficiario')
@use('App\Models\LineaLiquidacionBeneficiario')

{{--
    Armar una liquidación a un beneficiario (LiquidacionBeneficiarioController::create y store), en una sola pantalla: el beneficiario y la
    fecha de corte; con los dos, la vista previa (ordinarias, ajustes en contra y saldo anterior) y "Confirmar". Sin JavaScript:
    "Ver la vista previa" es un formulario GET y "Confirmar" lleva la cantidad y el total (si la lista cambió, el servidor pide revisarla).
--}}
<x-admin.page title="Armar liquidación a beneficiario">
    <div class="space-y-6 p-6 text-sm">
        <p class="text-gray-600 dark:text-gray-400">
            Entran todas las líneas del reparto del beneficiario ya percibidas (cobradas a la paciente y, si hay aseguradora, con su
            liquidación pagada del todo) con fecha de servicio hasta el corte; también lo pendiente anterior. Además, los ajustes en contra
            de lo ya aprobado que dejó de corresponder y el saldo en contra de la liquidación anterior. Las líneas en cero (funcionarios) no
            entran. Una línea se posterga quitándola, con motivo, de la liquidación en preparación.
        </p>

        <form method="GET" action="{{ route('admin.liquidaciones-beneficiarios.create') }}" novalidate class="flex flex-wrap items-end gap-3">
            <div class="w-96"><x-admin.select name="beneficiario" label="Beneficiario" :options="$opciones" :value="$beneficiarioTexto" required /></div>
            <div class="w-44"><x-admin.fecha name="corte" label="Fecha de corte" :value="$corteTexto" hasta-hoy /></div>
            <x-secondary-button type="submit">Ver la vista previa</x-secondary-button>
            <a href="{{ route('admin.liquidaciones-beneficiarios.index') }}" class="pb-2 text-gray-600 hover:underline dark:text-gray-400">Volver a Liquidaciones a beneficiarios</a>
        </form>

        @if ($error)
            <p role="alert" class="rounded-md bg-red-50 p-3 text-red-800 dark:bg-red-900/30 dark:text-red-200">{{ $error }}</p>
        @endif

        @if ($previa !== null)
            @if ($abierta)
                <p role="alert" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {{ ArmarLiquidacionBeneficiario::YA_ABIERTA }}
                    <a href="{{ route('admin.liquidaciones-beneficiarios.show', $abierta) }}" class="font-medium underline">Ver la liquidación N.º {{ $abierta->id }} ({{ $abierta->etiquetaEstado() }})</a>
                </p>
            @endif

            <section aria-labelledby="vista-previa" class="space-y-3">
                <h3 id="vista-previa" class="font-semibold text-gray-900 dark:text-gray-100">
                    Vista previa: {{ $previa['cantidad'] }} {{ $previa['cantidad'] === 1 ? 'línea' : 'líneas' }}, total {{ $moneda->formatear($previa['total']) }}
                </h3>
                @if ($previa['total'] < 0)
                    <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                        El total da negativo: la liquidación se aprueba igual, no se paga y su saldo en contra pasa a la siguiente.
                    </p>
                @endif
                @include('admin.liquidaciones-beneficiarios._lineas', ['filas' => $previa['lineas'], 'conPaciente' => $deProfesional, 'enVistaPrevia' => true])

                @if ($previa['cantidad'] > 0 && ! $abierta)
                    <form method="POST" action="{{ route('admin.liquidaciones-beneficiarios.store') }}" novalidate
                        x-data x-on:submit="if (! confirm('¿Armar la liquidación con estas {{ $previa['cantidad'] }} líneas?')) $event.preventDefault()">
                        @csrf
                        <input type="hidden" name="beneficiario" value="{{ $beneficiarioTexto }}">
                        <input type="hidden" name="fecha_corte" value="{{ $corteTexto }}">
                        <input type="hidden" name="cantidad" value="{{ $previa['cantidad'] }}">
                        <input type="hidden" name="total" value="{{ $previa['total'] }}">
                        <x-primary-button>Confirmar y armar la liquidación</x-primary-button>
                    </form>
                @endif
            </section>
        @endif
    </div>
</x-admin.page>
