<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoRedSocial;
use App\Support\Grilla\Grilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TipoRedSocialController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $grilla = Grilla::de($request)
            ->texto('nombre', 'Nombre', 'nombre')
            ->estado('estado', 'Estado', 'estado_id', TipoRedSocial::class);

        return $this->listado($request, 'admin.tipos-red-social', [
            'tiposRedSocial' => $grilla->aplicar($this->buscarEn(TipoRedSocial::query(), $busqueda, ['nombre']), fn ($query) => $query->orderBy('nombre'))
                ->paginate(15)->withQueryString(),
            'grilla' => $grilla,
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.tipos-red-social.form', ['tipoRedSocial' => new TipoRedSocial]);
    }

    public function store(Request $request): RedirectResponse
    {
        TipoRedSocial::create($this->validar($request));

        return redirect()->route('admin.tipos-red-social.index')->with('status', 'Tipo de red social creado.');
    }

    public function edit(TipoRedSocial $tipoRedSocial): View
    {
        return view('admin.tipos-red-social.form', compact('tipoRedSocial'));
    }

    public function update(Request $request, TipoRedSocial $tipoRedSocial): RedirectResponse
    {
        $tipoRedSocial->update($this->validar($request, $tipoRedSocial));

        return redirect()->route('admin.tipos-red-social.index')->with('status', 'Tipo de red social actualizado.');
    }

    public function desactivar(TipoRedSocial $tipoRedSocial): RedirectResponse
    {
        $tipoRedSocial->desactivar();

        return redirect()->route('admin.tipos-red-social.index')->with('status', 'Tipo de red social desactivado.');
    }

    private function validar(Request $request, ?TipoRedSocial $tipoRedSocial = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('tipos_red_social')->ignore($tipoRedSocial)],
        ];

        if ($tipoRedSocial) {
            $reglas['estado_id'] = TipoRedSocial::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
