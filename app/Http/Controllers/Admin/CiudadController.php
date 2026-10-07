<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ciudad;
use App\Models\Estado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CiudadController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        // Busca por ciudad, departamento o país.
        $ciudades = Ciudad::query()
            ->select('ciudades.*')
            ->join('departamentos', 'departamentos.id', '=', 'ciudades.departamento_id')
            ->join('paises', 'paises.id', '=', 'departamentos.pais_id')
            ->with('departamento.pais')
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('ciudades.nombre', "%{$busqueda}%")
                ->orWhereLike('departamentos.nombre', "%{$busqueda}%")
                ->orWhereLike('paises.nombre', "%{$busqueda}%")))
            ->orderBy('paises.nombre')
            ->orderBy('departamentos.nombre')
            ->orderBy('ciudades.nombre')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.ciudades', compact('ciudades', 'busqueda'));
    }

    public function create(): View
    {
        return view('admin.ciudades.form', ['ciudad' => new Ciudad]);
    }

    public function store(Request $request): RedirectResponse
    {
        Ciudad::create($this->validar($request));

        return redirect()->route('admin.ciudades.index')->with('status', 'Ciudad creada.');
    }

    public function edit(Ciudad $ciudad): View
    {
        return view('admin.ciudades.form', ['ciudad' => $ciudad->load('departamento')]);
    }

    public function update(Request $request, Ciudad $ciudad): RedirectResponse
    {
        $ciudad->update($this->validar($request, $ciudad));

        return redirect()->route('admin.ciudades.index')->with('status', 'Ciudad actualizada.');
    }

    public function desactivar(Ciudad $ciudad): RedirectResponse
    {
        $ciudad->desactivar();

        return redirect()->route('admin.ciudades.index')->with('status', 'Ciudad desactivada.');
    }

    private function validar(Request $request, ?Ciudad $ciudad = null): array
    {
        $reglas = [
            'departamento_id' => ['required', 'integer', Rule::exists('departamentos', 'id')->where(fn ($query) => $query
                ->where('estado_id', Estado::idDe(Estado::ACTIVO))
                ->when($ciudad, fn ($query) => $query->orWhere('id', $ciudad->departamento_id)))],
            // El nombre se repite entre departamentos (hay "San Pedro" en varios), no dentro del mismo.
            'nombre' => ['required', 'string', 'max:100', Rule::unique('ciudades')
                ->where('departamento_id', $request->input('departamento_id'))->ignore($ciudad)],
        ];

        if ($ciudad) {
            $reglas['estado_id'] = Ciudad::reglaEstado();
        }

        return $request->validate($reglas, [
            'nombre.unique' => 'Ese departamento ya tiene una ciudad con ese nombre.',
        ], ['departamento_id' => 'departamento']);
    }
}
