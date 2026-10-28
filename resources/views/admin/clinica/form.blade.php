{{-- Datos de la clínica como contribuyente emisor (ClinicaController, docs/facturacion.md): una sola fila; la editan Gerencia y el
     Administrador. La factura copia la razón social, el RUC y la dirección fiscal al emitirse. --}}
<x-admin.page title="Datos de la clínica">
    <div class="px-6 pt-6 text-sm text-gray-600 dark:text-gray-400 max-w-2xl space-y-2">
        <p>
            Son los datos del emisor de las facturas. Puede ser unipersonal (una persona física con RUC) o una sociedad. La dirección y el
            teléfono de cada sucursal (los del establecimiento) se cargan en <a href="{{ route('admin.sucursales.index') }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Sucursales</a>.
            Sin estos datos no se emiten facturas. Las facturas ya emitidas no cambian.
        </p>
    </div>
    @if ($puedeEditar)
        <x-admin.form :action="route('admin.clinica.update')" method="PUT" :cancel="route('admin.sucursales.index')" width="max-w-2xl" enctype="multipart/form-data">
            <x-admin.select name="tipo_contribuyente" label="Tipo de contribuyente" :options="App\Models\Clinica::TIPOS" :value="$clinica->tipo_contribuyente" required />
            <x-admin.input name="razon_social" label="Nombre o razón social" :value="$clinica->razon_social" maxlength="200" required />
            <x-admin.input name="nombre_fantasia" label="Nombre de fantasía (opcional)" :value="$clinica->nombre_fantasia" maxlength="200" />
            <x-admin.input name="ruc" label="RUC (con el dígito verificador, p. ej. 80012345-1)" :value="$clinica->ruc" maxlength="12" required />
            <x-admin.input name="actividad_economica" label="Actividad económica" :value="$clinica->actividad_economica" maxlength="200" required />
            <x-admin.input name="direccion_fiscal" label="Dirección fiscal" :value="$clinica->direccion_fiscal" maxlength="200" required />
            <x-geografia.selector-ciudad :value="$clinica->ciudad_id" />
            <x-admin.input name="telefono" label="Teléfono (opcional)" :value="$clinica->telefono" maxlength="20" />
            <x-admin.input name="correo" label="Correo (opcional)" type="email" :value="$clinica->correo" maxlength="150" />
            <div>
                <x-input-label for="logotipo" value="Logotipo (PNG o JPG, hasta 1 MB; vacío: el actual)" />
                <input id="logotipo" name="logotipo" type="file" accept="image/png,image/jpeg" class="mt-1 block w-full text-sm">
                <x-input-error class="mt-2" :messages="$errors->get('logotipo')" />
            </div>
        </x-admin.form>
    @elseif ($clinica->exists)
        <dl class="grid max-w-2xl grid-cols-1 gap-x-6 gap-y-3 p-6 text-sm sm:grid-cols-2">
            @foreach (['Tipo de contribuyente' => $clinica->etiquetaTipo(), 'Nombre o razón social' => $clinica->razon_social, 'Nombre de fantasía' => $clinica->nombre_fantasia,
                'RUC' => $clinica->ruc, 'Actividad económica' => $clinica->actividad_economica, 'Dirección fiscal' => $clinica->direccion_fiscal,
                'Ciudad' => $clinica->ciudad?->nombre, 'Teléfono' => $clinica->telefono, 'Correo' => $clinica->correo] as $etiqueta => $valor)
                <div><dt class="text-gray-500 dark:text-gray-400">{{ $etiqueta }}</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $valor ?: '—' }}</dd></div>
            @endforeach
        </dl>
    @else
        <p class="p-6 text-sm text-gray-600 dark:text-gray-400">Todavía no se cargaron los datos de la clínica.</p>
    @endif
</x-admin.page>
