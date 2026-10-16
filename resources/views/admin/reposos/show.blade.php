{{--
    Detalle de un certificado de reposo (ReposoMedicoController::show), dibujado desde su snapshot. El PDF se genera al vuelo
    (otra pestaña) y solo si la huella coincide con el contenido. Anular: el profesional que lo emitió o el Administrador.
--}}
@use('App\Support\Fecha')
@use('App\Support\Reposo\AnularReposo')

<x-admin.page :title="'Certificado de reposo '.$reposo->numero">
    <div class="space-y-5 p-6 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="font-medium">{{ $reposo->numero }} · {{ $reposo->etiquetaEstado() }} · Emitido el {{ Fecha::mostrar($hoja['fecha']) }} por {{ $hoja['emitido_por'] }}</p>
            <div class="flex flex-wrap items-center gap-4">
                @if ($integro)
                    <a href="{{ route('admin.reposos.pdf', $reposo) }}" target="_blank" rel="noopener" data-pdf-reposo
                        class="inline-flex items-center rounded-md bg-gray-800 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white">Abrir PDF</a>
                @endif
                @if ($puedeVerConsulta)
                    <a href="{{ route('admin.reposos.consulta', $reposo->consulta) }}" class="text-gray-600 hover:underline dark:text-gray-400">Certificados de la consulta</a>
                @endif
            </div>
        </div>

        @unless ($integro)
            <p role="alert" class="rounded-md bg-red-50 p-3 text-red-800 dark:bg-red-900/40 dark:text-red-200">
                La huella de este certificado no coincide con su contenido. No se genera el PDF: no lo imprima ni lo entregue y avise a Administración.
            </p>
        @endunless
        @if ($reposo->estaAnulado())
            <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                Certificado anulado el {{ Fecha::mostrar($reposo->anulada_en, conHora: true) }}. Motivo: {{ $reposo->motivo_anulacion }}
            </p>
        @endif

        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="inline font-medium">Paciente:</dt> <dd class="inline">{{ $hoja['paciente']['nombre'] }} ({{ $hoja['paciente']['documento'] }}) · Ficha {{ $hoja['paciente']['ficha'] }}</dd></div>
            <div><dt class="inline font-medium">Profesional:</dt> <dd class="inline">{{ $hoja['profesional']['nombre'] }} · Mat. {{ $hoja['profesional']['matricula'] }}</dd></div>
            <div><dt class="inline font-medium">Fecha de atención:</dt> <dd class="inline">{{ Fecha::mostrar($hoja['fecha_atencion']) }}</dd></div>
            <div><dt class="inline font-medium">Reposo:</dt> <dd class="inline">{{ $hoja['dias'] }} {{ $hoja['dias'] === 1 ? 'día' : 'días' }}, del {{ Fecha::mostrar($hoja['desde']) }} al {{ Fecha::mostrar($hoja['hasta']) }}</dd></div>
            <div><dt class="inline font-medium">Modalidad:</dt> <dd class="inline">{{ $hoja['modalidad']['texto'] }}{{ $hoja['modalidad']['detalle'] ? ': '.$hoja['modalidad']['detalle'] : '' }}</dd></div>
            @if ($hoja['empleador'])
                <div><dt class="inline font-medium">Empleador o institución:</dt> <dd class="inline">{{ $hoja['empleador'] }}</dd></div>
            @endif
            @if ($hoja['prorroga_de'])
                <div><dt class="inline font-medium">Prórroga de:</dt> <dd class="inline">{{ $hoja['prorroga_de'] }}</dd></div>
            @endif
            <div><dt class="inline font-medium">Diagnóstico impreso:</dt> <dd class="inline">{{ $hoja['diagnosticos'] ? collect($hoja['diagnosticos'])->map(fn ($d) => $d['codigo'].' — '.$d['descripcion'])->implode('; ') : 'No' }}</dd></div>
            @if ($hoja['observaciones'])
                <div class="sm:col-span-2"><dt class="font-medium">Observaciones y recomendaciones:</dt> <dd class="whitespace-pre-line">{{ $hoja['observaciones'] }}</dd></div>
            @endif
        </dl>

        @if ($puedeAnular)
            <details class="max-w-xl rounded-md border border-red-200 p-3 dark:border-red-900">
                <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">Anular el certificado</summary>
                <form method="POST" action="{{ route('admin.reposos.anular', $reposo) }}" class="mt-3 space-y-2">
                    @csrf
                    <label for="motivo" class="block font-medium text-gray-700 dark:text-gray-300">Motivo de la anulación (obligatorio)</label>
                    <textarea id="motivo" name="motivo" rows="3" minlength="{{ AnularReposo::MOTIVO_MINIMO }}" maxlength="{{ AnularReposo::MOTIVO_MAXIMO }}" required
                        class="block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ old('motivo') }}</textarea>
                    <x-input-error :messages="$errors->get('motivo')" />
                    <button type="submit" class="rounded-md border border-red-600 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30">Anular</button>
                </form>
            </details>
        @endif
    </div>
</x-admin.page>
