@use('App\Support\Auditoria')
@use('App\Support\Fecha')

@php
    // Listas (permisos de un perfil, especialidades, ...) una por renglón. Las secciones de la consulta
    // guardan solo las filas que cambiaron, como "identificación => texto": "Bloque #12: 1. Alergias: …";
    // una fila que no existía (antes) o se quitó, "(no existía)".
    $texto = fn ($item) => $item === null ? '(no existía)' : (is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE));
    $mostrar = fn ($valor) => match (true) {
        $valor === null => '—',
        is_array($valor) && $valor === [] => '(ninguno)',
        is_array($valor) && array_is_list($valor) => implode("\n", array_map($texto, $valor)),
        is_array($valor) => implode("\n", array_map(fn ($fila, $item) => "{$fila}: ".$texto($item), array_keys($valor), $valor)),
        is_bool($valor) => $valor ? 'sí' : 'no',
        default => (string) $valor,
    };
@endphp

<x-admin.page title="Detalle del evento de auditoría">
    <div class="p-6 space-y-6 max-w-4xl">
        <dl class="grid gap-4 sm:grid-cols-3 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Fecha y hora</dt>
                <dd class="font-medium font-mono text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($log->fecha_hora, conHora: true) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Usuario</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $log->usuario?->name ?? 'Sin usuario' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Acción</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $log->accion->etiqueta() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Módulo</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $modulos[Auditoria::modulosPorTabla()[$log->tabla_afectada] ?? ''] ?? $log->tabla_afectada }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Registro afectado</dt>
                <dd class="font-medium font-mono text-gray-900 dark:text-gray-100">{{ $log->tabla_afectada }} {{ $log->registro_afectado_id ? '#'.$log->registro_afectado_id : '' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">IP de origen</dt>
                <dd class="font-medium font-mono text-gray-900 dark:text-gray-100">{{ $log->ip_origen ?? '—' }}</dd>
            </div>
            @if ($log->detalle && ! $contenidoOculto)
                <div class="sm:col-span-3">
                    <dt class="text-gray-500 dark:text-gray-400">Detalle</dt>
                    <dd class="text-gray-900 dark:text-gray-100">{{ $log->detalle }}</dd>
                </div>
            @endif
        </dl>

        @if ($contenidoOculto)
            <p role="status" class="rounded-md bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                Contenido clínico: se requiere permiso de lectura sobre {{ $moduloOculto }}
            </p>
        @endif

        @if ($cambios)
            <div class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Campo</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Valor anterior</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Valor nuevo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                        @foreach ($cambios as $cambio)
                            <tr>
                                <td class="px-4 py-2 font-mono align-top">{{ $cambio['campo'] }}</td>
                                @if ($contenidoOculto)
                                    <td colspan="2" class="px-4 py-2 align-top italic text-gray-500 dark:text-gray-400">Oculto</td>
                                @else
                                    <td class="px-4 py-2 align-top whitespace-pre-line text-gray-600 dark:text-gray-400">{{ $mostrar($cambio['anterior']) }}</td>
                                    <td class="px-4 py-2 align-top whitespace-pre-line">{{ $mostrar($cambio['nuevo']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">Este evento no registra cambios de datos.</p>
        @endif

        <a href="{{ route('admin.auditoria.index') }}" class="inline-block text-sm text-gray-600 dark:text-gray-400 hover:underline">Volver a la auditoría</a>
    </div>
</x-admin.page>
