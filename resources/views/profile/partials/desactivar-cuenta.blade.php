{{-- "Desactivar mi cuenta": el usuario pasa a INACTIVO, no se borra (ProfileController::desactivar). --}}
<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            Desactivar mi cuenta
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Al desactivar su cuenta se cierra la sesión y ya no podrá ingresar al sistema.
            Sus datos no se borran: para volver a usar la cuenta, solicite a un administrador que la reactive.
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirmar-desactivar-cuenta')"
    >Desactivar mi cuenta</x-danger-button>

    <x-modal name="confirmar-desactivar-cuenta" :show="$errors->desactivarCuenta->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.desactivar') }}" class="p-6">
            @csrf
            @method('patch')

            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                ¿Está seguro de que desea desactivar su cuenta?
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Ingrese su contraseña para confirmar. Después no podrá ingresar hasta que un administrador reactive su cuenta.
            </p>

            <div class="mt-6">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <x-password-input
                    id="password"
                    name="password"
                    class="mt-1 block w-3/4"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->desactivarCuenta->get('password')" class="mt-2" />
                <x-input-error :messages="$errors->desactivarCuenta->get('cuenta')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    Desactivar mi cuenta
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
