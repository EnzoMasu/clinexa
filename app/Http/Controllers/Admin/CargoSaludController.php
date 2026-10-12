<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CargoSalud;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CargoSaludController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.cargos-salud', [
            'cargosSalud' => $this->buscarEn(CargoSalud::query(), $busqueda, ['nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.cargos-salud.form', ['cargoSalud' => new CargoSalud]);
    }

    public function store(Request $request): RedirectResponse
    {
        CargoSalud::create($this->validar($request));

        return redirect()->route('admin.cargos-salud.index')->with('status', 'Cargo de salud creado.');
    }

    public function edit(CargoSalud $cargoSalud): View
    {
        return view('admin.cargos-salud.form', ['cargoSalud' => $cargoSalud]);
    }

    public function update(Request $request, CargoSalud $cargoSalud): RedirectResponse
    {
        $cargoSalud->update($this->validar($request, $cargoSalud));

        return redirect()->route('admin.cargos-salud.index')->with('status', 'Cargo de salud actualizado.');
    }

    public function desactivar(CargoSalud $cargoSalud): RedirectResponse
    {
        $cargoSalud->desactivar();

        return redirect()->route('admin.cargos-salud.index')->with('status', 'Cargo de salud desactivado.');
    }

    private function validar(Request $request, ?CargoSalud $cargoSalud = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('cargos_salud')->ignore($cargoSalud)],
        ];

        if ($cargoSalud) {
            $reglas['estado_id'] = CargoSalud::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
