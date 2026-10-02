<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultorio;
use App\Models\Estado;
use App\Models\Sucursal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConsultorioController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        $consultorios = Consultorio::query()
            ->select('consultorios.*')
            ->join('sucursales', 'sucursales.id', '=', 'consultorios.sucursal_id')
            ->with('sucursal')
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('consultorios.nombre', "%{$busqueda}%")
                ->orWhereLike('sucursales.nombre', "%{$busqueda}%")))
            ->orderBy('sucursales.nombre')
            ->orderBy('consultorios.nombre')
            ->paginate(15)
            ->withQueryString();

        return $this->listado($request, 'admin.consultorios', compact('consultorios', 'busqueda'));
    }

    public function create(): View
    {
        return $this->formulario(new Consultorio);
    }

    public function store(Request $request): RedirectResponse
    {
        Consultorio::create($this->validar($request));

        return redirect()->route('admin.consultorios.index')->with('status', 'Consultorio creado.');
    }

    public function edit(Consultorio $consultorio): View
    {
        return $this->formulario($consultorio);
    }

    public function update(Request $request, Consultorio $consultorio): RedirectResponse
    {
        $consultorio->update($this->validar($request, $consultorio));

        return redirect()->route('admin.consultorios.index')->with('status', 'Consultorio actualizado.');
    }

    public function desactivar(Consultorio $consultorio): RedirectResponse
    {
        $consultorio->desactivar();

        return redirect()->route('admin.consultorios.index')->with('status', 'Consultorio desactivado.');
    }

    private function formulario(Consultorio $consultorio): View
    {
        return view('admin.consultorios.form', [
            'consultorio' => $consultorio,
            // Sucursales activas, más la que ya tenga aunque esté inactiva.
            'sucursales' => Sucursal::query()
                ->where(fn ($query) => $query->activos()->when($consultorio->sucursal_id, fn ($query) => $query->orWhere('id', $consultorio->sucursal_id)))
                ->orderBy('nombre')->pluck('nombre', 'id')->all(),
        ]);
    }

    private function validar(Request $request, ?Consultorio $consultorio = null): array
    {
        $reglas = [
            'sucursal_id' => ['required', 'integer', Rule::exists('sucursales', 'id')->where(fn ($query) => $query
                ->where('estado_id', Estado::idDe(Estado::ACTIVO))
                ->when($consultorio, fn ($query) => $query->orWhere('id', $consultorio->sucursal_id)))],
            // El nombre se repite entre sucursales ("Consultorio 1"), no dentro de la misma.
            'nombre' => ['required', 'string', 'max:100', Rule::unique('consultorios')
                ->where('sucursal_id', $request->input('sucursal_id'))->ignore($consultorio)],
        ];

        if ($consultorio) {
            $reglas['estado_id'] = Consultorio::reglaEstado();
        }

        return $request->validate($reglas, [
            'nombre.unique' => 'Esa sucursal ya tiene un consultorio con ese nombre.',
        ], ['sucursal_id' => 'sucursal']);
    }
}
