{{-- Los campos de la cobertura: los usa el formulario de la cobertura y el de la visación (un solo envío). Requiere $planes. --}}
@use('App\Models\CoberturaPaciente')
@use('App\Support\Fecha')

<x-admin.select name="plan_convenio_id" label="Aseguradora, convenio y plan" :options="$planes" required />
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-admin.input name="numero_asegurado" label="N.º de asegurado" maxlength="50" required />
    <x-admin.select name="tipo_afiliado" label="Titular o beneficiario" :options="CoberturaPaciente::TIPOS" required />
    <x-admin.fecha name="vigente_desde" label="Vigente desde" :value="Fecha::hoy()" />
    <div>
        <x-admin.fecha name="vigente_hasta" label="Vence (opcional)" />
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Vacío: sin vencimiento conocido.</p>
    </div>
</div>
