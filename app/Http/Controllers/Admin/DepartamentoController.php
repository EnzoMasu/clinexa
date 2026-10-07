<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Estado;
use App\Models\Pais;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DepartamentoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        $departamentos = Departamento::query()
            ->select('departamentos.*')
            ->join('paises', 'paises.id', '=', 'departamentos.pais_id')
            ->with('pais')
            ->withCount('ciudades')
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('departamentos.nombre', "%{$busqueda}%")
                ->orWhereLike('paises.nombre', "%{$busqueda}%")))
            ->orderBy('paises.nombre')
            ->orderBy('departamentos.nombre')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.departamentos', compact('departamentos', 'busqueda'));
    }

    public function create(): View
    {
        return $this->formulario(new Departamento(['pais_id' => Pais::idParaguay()]));
    }

    public function store(Request $request): RedirectResponse
    {
        Departamento::create($this->validar($request));

        return redirect()->route('admin.departamentos.index')->with('status', 'Departamento creado.');
    }

    public function edit(Departamento $departamento): View
    {
        return $this->formulario($departamento);
    }

    public function update(Request $request, Departamento $departamento): RedirectResponse
    {
        $departamento->update($this->validar($request, $departamento));

        return redirect()->route('admin.departamentos.index')->with('status', 'Departamento actualizado.');
    }

    public function desactivar(Departamento $departamento): RedirectResponse
    {
        $departamento->desactivar();

        return redirect()->route('admin.departamentos.index')->with('status', 'Departamento desactivado.');
    }

    private function formulario(Departamento $departamento): View
    {
        return view('admin.departamentos.form', [
            'departamento' => $departamento,
            // Todos los países activos (no solo los que ya tienen departamentos), más el actual.
            'paises' => Pais::opciones($departamento->exists ? $departamento->pais_id : null),
        ]);
    }

    private function validar(Request $request, ?Departamento $departamento = null): array
    {
        $reglas = [
            'pais_id' => ['required', 'integer', Rule::exists('paises', 'id')->where(fn ($query) => $query
                ->where('estado_id', Estado::idDe(Estado::ACTIVO))
                ->when($departamento, fn ($query) => $query->orWhere('id', $departamento->pais_id)))],
            // El nombre se repite entre países, no dentro del mismo.
            'nombre' => ['required', 'string', 'max:100', Rule::unique('departamentos')
                ->where('pais_id', $request->input('pais_id'))->ignore($departamento)],
        ];

        if ($departamento) {
            $reglas['estado_id'] = Departamento::reglaEstado();
        }

        return $request->validate($reglas, [
            'nombre.unique' => 'Ese país ya tiene un departamento con ese nombre.',
        ], ['pais_id' => 'país']);
    }
}
