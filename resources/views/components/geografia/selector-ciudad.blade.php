{{--
    Ciudad opcional con selección en cascada País → Departamento → Ciudad (resources/js/selector-ciudad.js).
    Solo se envía ciudad_id. Las opciones iniciales se arman acá, así el formulario llega completo
    (al editar, con la ciudad actual ya elegida) y el fetch solo interviene al cambiar de país o departamento.
--}}
@props(['name' => 'ciudad_id', 'value' => null])

@php
    use App\Models\Ciudad;
    use App\Models\Departamento;
    use App\Models\Pais;

    $ciudadId = old($name, $value);
    $actual = $ciudadId ? Ciudad::with('departamento')->find($ciudadId) : null;

    // Solo países con departamentos cargados (el catálogo tiene todos, para la nacionalidad).
    $paises = Pais::activos()->whereHas('departamentos')->orderBy('nombre')->get();
    // Sin ciudad elegida: si hay un solo país (Paraguay), ya viene seleccionado.
    $paisId = $actual?->departamento->pais_id ?? ($paises->count() === 1 ? $paises->first()->id : null);
    $departamentoId = $actual?->departamento_id;

    $departamentos = $paisId ? Departamento::activos()->where('pais_id', $paisId)->orderBy('nombre')->get() : collect();
    $ciudades = $departamentoId ? Ciudad::activos()->where('departamento_id', $departamentoId)->orderBy('nombre')->get() : collect();
    // La ciudad actual se muestra aunque se haya desactivado después.
    if ($actual && ! $ciudades->contains('id', $actual->id)) {
        $ciudades->push($actual);
    }

    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm disabled:opacity-60';
@endphp

<div x-data="selectorCiudad({ urlDepartamentos: @js(route('geografia.departamentos')), urlCiudades: @js(route('geografia.ciudades')) })"
    {{ $attributes->merge(['class' => 'grid gap-6 sm:grid-cols-3']) }}>
    <div>
        <x-input-label for="{{ $name }}_pais" value="País" />
        <select id="{{ $name }}_pais" x-ref="pais" x-on:change="cambiarPais()" class="{{ $clases }}">
            <option value="">—</option>
            @foreach ($paises as $pais)
                <option value="{{ $pais->id }}" @selected($paisId === $pais->id)>{{ $pais->nombre }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="{{ $name }}_departamento" value="Departamento" />
        <select id="{{ $name }}_departamento" x-ref="departamento" x-on:change="cambiarDepartamento()" class="{{ $clases }}"
            @disabled($departamentos->isEmpty())>
            <option value="">—</option>
            @foreach ($departamentos as $departamento)
                <option value="{{ $departamento->id }}" @selected($departamentoId === $departamento->id)>{{ $departamento->nombre }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="{{ $name }}" value="Ciudad (opcional)" />
        <select id="{{ $name }}" name="{{ $name }}" x-ref="ciudad" class="{{ $clases }}" @disabled($ciudades->isEmpty())>
            <option value="">—</option>
            @foreach ($ciudades as $ciudad)
                <option value="{{ $ciudad->id }}" @selected((string) $ciudadId === (string) $ciudad->id)>{{ $ciudad->nombre }}</option>
            @endforeach
        </select>
        <x-input-error class="mt-2" :messages="$errors->get($name)" />
    </div>
</div>
