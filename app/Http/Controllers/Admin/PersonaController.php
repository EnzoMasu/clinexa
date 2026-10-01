<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PersonaRequest;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\TipoDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PersonaController extends Controller
{
    public function index(Request $request): Response
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

        return $this->listado($request, 'admin.personas', compact('personas', 'busqueda'));
    }

    public function create(): View
    {
        return view('admin.personas.form', [
            // Preseleccionados: el tipo de documento predeterminado del módulo Personas y nacionalidad paraguaya.
            'persona' => new Persona([
                'tipo_persona' => 'FISICA',
                'tipo_documento_id' => TipoDocumento::predeterminadoPara('PERSONAS'),
                'pais_nacionalidad_id' => Pais::idParaguay(),
            ]),
            'tiposDocumento' => $this->tiposDocumento(),
            'paises' => Pais::opciones(),
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
            'paises' => Pais::opciones($persona->pais_nacionalidad_id),
        ]);
    }

    public function update(PersonaRequest $request, Persona $persona): RedirectResponse
    {
        $persona->update($request->validated());

        return redirect()->route('admin.personas.index')->with('status', 'Persona actualizada.');
    }

    public function desactivar(Persona $persona): RedirectResponse
    {
        $persona->desactivar();

        return redirect()->route('admin.personas.index')->with('status', 'Persona desactivada.');
    }

    /**
     * Tipos de documento activos, más el que ya tenga asignado la persona aunque esté inactivo.
     */
    /**
     * Tipos de documento habilitados para Personas (tipo_documento_modulo) y activos, el
     * predeterminado primero. Si la persona ya tiene otro (inactivo o no habilitado), se agrega.
     */
    private function tiposDocumento(?Persona $persona = null)
    {
        $tipos = TipoDocumento::query()->habilitadosPara('PERSONAS')->activos()->get();

        if ($persona && ! $tipos->contains('id', $persona->tipo_documento_id)) {
            $tipos->push($persona->tipoDocumento);
        }

        return $tipos;
    }
}
