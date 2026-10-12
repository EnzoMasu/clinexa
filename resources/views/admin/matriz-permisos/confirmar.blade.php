{{-- Confirmación de "Guardar cambios" en la Matriz de permisos: la lista de lo que cambia. Nada se guardó todavía.
     Los permisos, la huella y el motivo viajan en campos ocultos; para lo delicado, el servidor exige la casilla. --}}
<x-admin.page title="Confirmar cambios de permisos">
    <div class="p-6 max-w-3xl space-y-5">
        <p class="text-sm text-gray-700 dark:text-gray-300">
            Perfil <strong>{{ $perfil->nombre }}</strong>
            ({{ $usuarios }} {{ $usuarios === 1 ? 'usuario' : 'usuarios' }}). Los cambios valen desde el próximo pedido de cada usuario del perfil.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <section aria-labelledby="titulo-agregados" class="rounded-md border border-gray-200 dark:border-gray-700 p-4">
                <h3 id="titulo-agregados" class="font-medium text-green-700 dark:text-green-400">Se agregan</h3>
                @forelse ($agregadas as $linea)
                    <p class="mt-1 text-sm dato" data-agregado>{{ $linea }}</p>
                @empty
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Nada.</p>
                @endforelse
            </section>
            <section aria-labelledby="titulo-quitados" class="rounded-md border border-gray-200 dark:border-gray-700 p-4">
                <h3 id="titulo-quitados" class="font-medium text-red-700 dark:text-red-400">Se quitan</h3>
                @forelse ($quitadas as $linea)
                    <p class="mt-1 text-sm dato" data-quitado>{{ $linea }}</p>
                @empty
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Nada.</p>
                @endforelse
            </section>
        </div>

        @if ($motivo !== '')
            <p class="text-sm text-gray-700 dark:text-gray-300">Motivo: {{ $motivo }}</p>
        @endif

        <form method="POST" action="{{ route('admin.matriz-permisos.update', $perfil) }}" class="space-y-4">
            @csrf
            @method('PUT')
            <input type="hidden" name="huella" value="{{ $huella }}">
            <input type="hidden" name="motivo" value="{{ $motivo }}">
            <input type="hidden" name="confirmado" value="1">
            @foreach ($permisos as $moduloId => $acciones)
                @foreach ($acciones as $accion)
                    <input type="hidden" name="permisos[{{ $moduloId }}][]" value="{{ $accion }}">
                @endforeach
            @endforeach

            @if ($delicadas !== [])
                <div role="alert" class="rounded-md bg-red-50 dark:bg-red-900/40 p-4 text-sm text-red-800 dark:text-red-200 space-y-2">
                    <p class="font-medium">Atención: se agregan permisos delicados.</p>
                    <p>
                        Son de un módulo sensible, o de escritura sobre Usuarios o Perfiles de acceso. Los permisos de escritura sobre
                        Usuarios o Perfiles de acceso, en la práctica, convierten en administradores a los usuarios de este perfil.
                    </p>
                    <ul class="list-disc ps-5">
                        @foreach ($delicadas as $linea)
                            <li class="dato">{{ $linea }}</li>
                        @endforeach
                    </ul>
                    <label class="flex items-center gap-2 font-medium">
                        <input type="checkbox" name="confirmar_delicados" value="1"
                            class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-red-600 shadow-sm focus:ring-red-500">
                        Entiendo y confirmo estos permisos delicados.
                    </label>
                    @if ($faltaConfirmar)
                        <p class="font-medium">{{ \App\Http\Controllers\Admin\MatrizPermisosController::FALTA_CONFIRMAR }}</p>
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-4">
                <x-primary-button>Confirmar y guardar</x-primary-button>
                <a href="{{ route('admin.matriz-permisos.index', ['perfil' => $perfil->id]) }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">Volver sin guardar</a>
            </div>
        </form>
    </div>
</x-admin.page>
