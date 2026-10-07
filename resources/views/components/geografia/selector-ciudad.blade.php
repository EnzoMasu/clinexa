{{--
    Selección en cascada País → Departamento → Ciudad (resources/js/selector-ciudad.js). Las opciones
    iniciales se arman acá, así el formulario llega completo (al editar, con lo actual ya elegido) y el
    fetch solo interviene al cambiar de país o departamento.

    - Por defecto elige una ciudad opcional y solo envía ciudad_id ("name").
    - Con hasta="departamento" se detiene en el departamento, obligatorio, y envía departamento_id
      (p. ej. el alta de una ciudad, que necesita saber a qué departamento pertenece).
--}}
@props(['name' => 'ciudad_id', 'value' => null, 'hasta' => 'ciudad'])

@php
    use App\Models\Ciudad;
    use App\Models\Departamento;
    use App\Models\Pais;

    $soloDepartamento = $hasta === 'departamento';
    $valor = old($name, $value);

    // Lo elegido: la ciudad (y de ahí departamento y país) o, en modo departamento, el departamento.
    $actual = $valor && ! $soloDepartamento ? Ciudad::with('departamento')->find($valor) : null;
    $departamentoActual = $soloDepartamento ? ($valor ? Departamento::find($valor) : null) : $actual?->departamento;

    // Solo países con departamentos cargados (el catálogo tiene todos, para la nacionalidad).
    $paises = Pais::activos()->whereHas('departamentos')->orderBy('nombre')->get();
    // Sin nada elegido: si hay un solo país (Paraguay), ya viene seleccionado.
    $paisId = $departamentoActual?->pais_id ?? ($paises->count() === 1 ? $paises->first()->id : null);
    $departamentoId = $departamentoActual?->id;

    $departamentos = $paisId ? Departamento::activos()->where('pais_id', $paisId)->orderBy('nombre')->get() : collect();
    $ciudades = ! $soloDepartamento && $departamentoId ? Ciudad::activos()->where('departamento_id', $departamentoId)->orderBy('nombre')->get() : collect();
    // Lo actual se muestra aunque se haya desactivado después.
    if ($departamentoActual && ! $departamentos->contains('id', $departamentoActual->id)) {
        $departamentos->push($departamentoActual);
    }
    if ($actual && ! $ciudades->contains('id', $actual->id)) {
        $ciudades->push($actual);
    }

    $idBase = $soloDepartamento ? $name : "{$name}_departamento";
    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm disabled:opacity-60';
@endphp

<div x-data="selectorCiudad({ urlDepartamentos: @js(route('geografia.departamentos')), urlCiudades: @js(route('geografia.ciudades')) })"
    {{ $attributes->merge(['class' => 'grid gap-6 '.($soloDepartamento ? 'sm:grid-cols-2' : 'sm:grid-cols-3')]) }}>
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
        <x-input-label for="{{ $idBase }}" value="Departamento" />
        <select id="{{ $idBase }}" x-ref="departamento" x-on:change="cambiarDepartamento()" class="{{ $clases }}"
            @if ($soloDepartamento) name="{{ $name }}" required @endif
            @disabled($departamentos->isEmpty())>
            <option value="">—</option>
            @foreach ($departamentos as $departamento)
                <option value="{{ $departamento->id }}" @selected($departamentoId === $departamento->id)>{{ $departamento->nombre }}</option>
            @endforeach
        </select>
        @if ($soloDepartamento)
            <x-input-error class="mt-2" :messages="$errors->get($name)" />
        @endif
    </div>

    @unless ($soloDepartamento)
        <div>
            <x-input-label for="{{ $name }}" value="Ciudad (opcional)" />
            <select id="{{ $name }}" name="{{ $name }}" x-ref="ciudad" class="{{ $clases }}" @disabled($ciudades->isEmpty())>
                <option value="">—</option>
                @foreach ($ciudades as $ciudad)
                    <option value="{{ $ciudad->id }}" @selected((string) $valor === (string) $ciudad->id)>{{ $ciudad->nombre }}</option>
                @endforeach
            </select>
            <x-input-error class="mt-2" :messages="$errors->get($name)" />
        </div>
    @endunless
</div>
