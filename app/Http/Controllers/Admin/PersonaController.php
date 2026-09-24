<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PersonaRequest;
use App\Models\Persona;
use App\Models\TipoDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PersonaController extends Controller
{
    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->query('q'));

        $personas = Persona::query()
            ->with('tipoDocumento')
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('nro_documento', "{$busqueda}%")
                ->orWhereLike('apellidos', "%{$busqueda}%")
                ->orWhereLike('nombres', "%{$busqueda}%")
                ->orWhereLike('razon_social', "%{$busqueda}%")
                ->orWhereLike('nombre_fantasia', "%{$busqueda}%")))
            ->orderByRaw('COALESCE(apellidos, razon_social)')
            ->orderBy('nombres')
            ->paginate(20)
            ->withQueryString();

        return view('admin.personas.index', compact('personas', 'busqueda'));
    }

    public function create(): View
    {
        return view('admin.personas.form', [
            'persona' => new Persona(['tipo_persona' => 'FISICA']),
            'tiposDocumento' => $this->tiposDocumento(),
        ]);
    }

    public function store(PersonaRequest $request): RedirectResponse
    {
        Persona::create($request->validated());

        return redirect()->route('admin.personas.index')->with('status', 'Persona creada.');
    }

    public function edit(Persona $persona): View
    {
        return view('admin.personas.form', [
            'persona' => $persona,
            'tiposDocumento' => $this->tiposDocumento($persona),
        ]);
    }

    public function update(PersonaRequest $request, Persona $persona): RedirectResponse
    {
        $persona->update($request->validated());

        return redirect()->route('admin.personas.index')->with('status', 'Persona actualizada.');
    }

    public function desactivar(Persona $persona): RedirectResponse
    {
        $persona->update(['estado' => 'INACTIVO']);

        return redirect()->route('admin.personas.index')->with('status', 'Persona desactivada.');
    }

    /**
     * Tipos de documento activos, más el que ya tenga asignado la persona aunque esté inactivo.
     */
    private function tiposDocumento(?Persona $persona = null)
    {
        return TipoDocumento::query()
            ->where('estado', 'ACTIVO')
            ->when($persona, fn ($query) => $query->orWhere('id', $persona->tipo_documento_id))
            ->orderBy('nombre')
            ->get();
    }
}
