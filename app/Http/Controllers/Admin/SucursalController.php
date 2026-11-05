<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ciudad;
use App\Models\Clinica;
use App\Models\Sucursal;
use App\Support\Grilla\Grilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SucursalController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $grilla = Grilla::de($request)
            ->texto('nombre', 'Nombre', 'nombre')
            ->texto('direccion', 'Dirección', 'direccion')
            ->texto('telefono', 'Teléfono', 'telefono')
            ->estado('estado', 'Estado', 'estado_id', Sucursal::class);

        return $this->listado($request, 'admin.sucursales', [
            'sucursales' => $grilla->aplicar($this->buscarEn(Sucursal::query(), $busqueda, ['nombre', 'direccion', 'telefono']), fn ($query) => $query->orderBy('nombre'))
                ->paginate(15)->withQueryString(),
            'grilla' => $grilla,
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.sucursales.form', ['sucursal' => new Sucursal]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Una sucursal nueva es de la clínica de la instalación (docs/facturacion.md).
        Sucursal::create([...$this->validar($request), 'clinica_id' => Clinica::actual()?->id]);

        return redirect()->route('admin.sucursales.index')->with('status', 'Sucursal creada.');
    }

    public function edit(Sucursal $sucursal): View
    {
        return view('admin.sucursales.form', compact('sucursal'));
    }

    public function update(Request $request, Sucursal $sucursal): RedirectResponse
    {
        $sucursal->update($this->validar($request, $sucursal));

        return redirect()->route('admin.sucursales.index')->with('status', 'Sucursal actualizada.');
    }

    public function desactivar(Sucursal $sucursal): RedirectResponse
    {
        $sucursal->desactivar();

        return redirect()->route('admin.sucursales.index')->with('status', 'Sucursal desactivada.');
    }

    private function validar(Request $request, ?Sucursal $sucursal = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('sucursales')->ignore($sucursal)],
            'direccion' => ['required', 'string', 'max:200'],
            'telefono' => ['required', 'string', 'max:20'],
            'ciudad_id' => Ciudad::reglaOpcional($sucursal?->ciudad_id),
        ];

        if ($sucursal) {
            $reglas['estado_id'] = Sucursal::reglaEstado();
        }

        return $request->validate($reglas, [], ['ciudad_id' => 'ciudad']);
    }
}
