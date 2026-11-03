<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoBloqueAnamnesis;
use App\Support\Grilla\Grilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TipoBloqueAnamnesisController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $grilla = Grilla::de($request)
            ->texto('nombre', 'Nombre', 'nombre')
            ->estado('estado', 'Estado', 'estado_id', TipoBloqueAnamnesis::class);

        return $this->listado($request, 'admin.tipos-bloque-anamnesis', [
            'tiposBloque' => $grilla->aplicar($this->buscarEn(TipoBloqueAnamnesis::query(), $busqueda, ['nombre']), fn ($query) => $query->orderBy('nombre'))
                ->paginate(15)->withQueryString(),
            'grilla' => $grilla,
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.tipos-bloque-anamnesis.form', ['tipoBloque' => new TipoBloqueAnamnesis]);
    }

    public function store(Request $request): RedirectResponse
    {
        TipoBloqueAnamnesis::create($this->validar($request));

        return redirect()->route('admin.tipos-bloque-anamnesis.index')->with('status', 'Tipo de bloque de anamnesis creado.');
    }

    public function edit(TipoBloqueAnamnesis $tipoBloqueAnamnesis): View
    {
        return view('admin.tipos-bloque-anamnesis.form', ['tipoBloque' => $tipoBloqueAnamnesis]);
    }

    public function update(Request $request, TipoBloqueAnamnesis $tipoBloqueAnamnesis): RedirectResponse
    {
        $tipoBloqueAnamnesis->update($this->validar($request, $tipoBloqueAnamnesis));

        return redirect()->route('admin.tipos-bloque-anamnesis.index')->with('status', 'Tipo de bloque de anamnesis actualizado.');
    }

    public function desactivar(TipoBloqueAnamnesis $tipoBloqueAnamnesis): RedirectResponse
    {
        $tipoBloqueAnamnesis->desactivar();

        return redirect()->route('admin.tipos-bloque-anamnesis.index')->with('status', 'Tipo de bloque de anamnesis desactivado.');
    }

    private function validar(Request $request, ?TipoBloqueAnamnesis $tipoBloque = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('tipos_bloque_anamnesis')->ignore($tipoBloque)],
        ];

        if ($tipoBloque) {
            $reglas['estado_id'] = TipoBloqueAnamnesis::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
