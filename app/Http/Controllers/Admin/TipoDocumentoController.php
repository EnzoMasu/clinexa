<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class TipoDocumentoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.tipos-documento', [
            'tiposDocumento' => $this->buscarEn(TipoDocumento::with('modulos'), $busqueda, ['codigo', 'nombre'])
                ->orderBy('codigo')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return $this->formulario(new TipoDocumento);
    }

    public function store(Request $request): RedirectResponse
    {
        $tipoDocumento = new TipoDocumento;
        $datos = $this->validar($request, $tipoDocumento);

        DB::transaction(function () use ($tipoDocumento, $datos) {
            $tipoDocumento->fill(Arr::except($datos, ['modulos', 'predeterminado']))->save();
            $this->guardarModulos($tipoDocumento, $datos['modulos'], $datos['predeterminado']);
        });

        return $this->volverAlListado($tipoDocumento, 'Tipo de documento creado.', []);
    }

    public function edit(TipoDocumento $tipoDocumento): View
    {
        return $this->formulario($tipoDocumento->load('modulos'));
    }

    public function update(Request $request, TipoDocumento $tipoDocumento): RedirectResponse
    {
        $datos = $this->validar($request, $tipoDocumento);
        $eraPredeterminadoEn = $tipoDocumento->modulos()->wherePivot('es_predeterminado', true)->pluck('modulos_sistema.id')->all();

        DB::transaction(function () use ($tipoDocumento, $datos) {
            $tipoDocumento->update(Arr::except($datos, ['modulos', 'predeterminado']));
            $this->guardarModulos($tipoDocumento, $datos['modulos'], $datos['predeterminado']);
        });

        return $this->volverAlListado($tipoDocumento, 'Tipo de documento actualizado.', array_diff($eraPredeterminadoEn, $datos['predeterminado']));
    }

    public function desactivar(TipoDocumento $tipoDocumento): RedirectResponse
    {
        // Conserva sus habilitaciones y el predeterminado: mientras esté inactivo no se ofrece ni se
        // preelige en ningún módulo, y si se reactiva vuelve a quedar como estaba.
        $tipoDocumento->desactivar();

        return redirect()->route('admin.tipos-documento.index')->with('status', 'Tipo de documento desactivado.');
    }

    private function formulario(TipoDocumento $tipoDocumento): View
    {
        return view('admin.tipos-documento.form', [
            'tipoDocumento' => $tipoDocumento,
            'modulos' => $this->modulosElegibles($tipoDocumento),
        ]);
    }

    /**
     * Módulos en los que se puede habilitar el tipo: los marcados con usa_tipos_documento, más
     * los que este tipo ya tenga habilitados (para poder verlos y quitarlos). Cada uno trae en
     * tiposDocumento su predeterminado actual, si tiene.
     *
     * @return Collection<int, ModuloSistema>
     */
    private function modulosElegibles(TipoDocumento $tipoDocumento): Collection
    {
        return ModuloSistema::query()
            ->where(fn ($query) => $query->usanTiposDocumento()
                ->when($tipoDocumento->exists, fn ($query) => $query
                    ->orWhereHas('tiposDocumento', fn ($query) => $query->whereKey($tipoDocumento->id))))
            ->with(['tiposDocumento' => fn ($query) => $query->wherePivot('es_predeterminado', true)])
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Devuelve codigo, nombre, estado_id (al editar) y las listas de ids "modulos" (habilitados) y
     * "predeterminado" (módulos donde es el predeterminado).
     */
    private function validar(Request $request, TipoDocumento $tipoDocumento): array
    {
        $elegibles = $this->modulosElegibles($tipoDocumento)->modelKeys();

        $reglas = [
            'codigo' => ['required', 'string', 'max:10', Rule::unique('tipos_documento')->ignore($tipoDocumento)],
            'nombre' => ['required', 'string', 'max:50'],
            // Ninguno tildado es válido: el tipo queda sin uso (el formulario lo advierte).
            'modulos' => ['array'],
            'modulos.*' => ['integer', 'distinct', Rule::in($elegibles)],
            'predeterminado' => ['array'],
            'predeterminado.*' => ['integer', 'distinct', Rule::in($elegibles)],
        ];

        if ($tipoDocumento->exists) {
            $reglas['estado_id'] = TipoDocumento::reglaEstado();
        }

        $validador = validator($request->all(), $reglas, [
            'modulos.*.in' => 'Uno de los módulos elegidos no admite tipos de documento.',
            'predeterminado.*.in' => 'Uno de los módulos elegidos no admite tipos de documento.',
        ], ['codigo' => 'código']);

        $validador->after(function (Validator $validador) use ($request, $tipoDocumento) {
            if ($validador->errors()->isNotEmpty()) {
                return;
            }

            $predeterminado = (array) $request->input('predeterminado', []);
            if (array_diff($predeterminado, (array) $request->input('modulos', []))) {
                $validador->errors()->add('predeterminado', 'Solo puede ser el predeterminado de un módulo en el que esté habilitado.');
            }

            // Al crear nace ACTIVO; al editar, cuenta el estado que se está guardando.
            $quedaInactivo = $tipoDocumento->exists && (int) $request->input('estado_id') !== Estado::idDe(Estado::ACTIVO);
            if ($predeterminado && $quedaInactivo) {
                $validador->errors()->add('predeterminado', 'Un tipo de documento inactivo no puede ser el predeterminado de ningún módulo. Quite la marca de predeterminado o déjelo ACTIVO.');
            }
        });

        $datos = $validador->validate();

        return [
            ...$datos,
            'modulos' => array_map('intval', $datos['modulos'] ?? []),
            'predeterminado' => array_map('intval', $datos['predeterminado'] ?? []),
        ];
    }

    /**
     * Deja en tipo_documento_modulo exactamente los módulos tildados. Donde pasa a ser el
     * predeterminado, se le quita la marca al anterior (uno solo por módulo). No toca a las
     * personas (ni otros registros) que ya usan el tipo: la habilitación solo decide qué se ofrece
     * en las altas y ediciones nuevas.
     */
    private function guardarModulos(TipoDocumento $tipoDocumento, array $modulos, array $predeterminado): void
    {
        if ($predeterminado) {
            DB::table('tipo_documento_modulo')
                ->whereIn('modulo_sistema_id', $predeterminado)
                ->where('tipo_documento_id', '!=', $tipoDocumento->id)
                ->update(['es_predeterminado' => false]);
        }

        $tipoDocumento->modulos()->sync(collect($modulos)->mapWithKeys(fn (int $id) => [
            $id => ['es_predeterminado' => in_array($id, $predeterminado, true)],
        ])->all());
    }

    /**
     * @param  list<int>  $modulosSinPredeterminado  módulos donde este tipo dejó de ser el predeterminado
     */
    private function volverAlListado(TipoDocumento $tipoDocumento, string $mensaje, array $modulosSinPredeterminado): RedirectResponse
    {
        $avisos = [];

        if (! $tipoDocumento->modulos()->exists()) {
            $avisos[] = "El tipo de documento {$tipoDocumento->codigo} quedó sin uso: no está habilitado en ningún módulo, así que no se ofrece al cargar datos. Habilítelo en algún módulo cuando lo necesite.";
        }

        $sinPredeterminado = ModuloSistema::whereKey($modulosSinPredeterminado)
            ->whereDoesntHave('tiposDocumento', fn ($query) => $query->where('tipo_documento_modulo.es_predeterminado', true))
            ->orderBy('nombre')->pluck('nombre');
        if ($sinPredeterminado->isNotEmpty()) {
            $avisos[] = 'Sin tipo de documento predeterminado en: '.$sinPredeterminado->join(', ').'. Al cargar datos ahí no vendrá ninguno preelegido; puede marcar otro como predeterminado desde su formulario.';
        }

        return redirect()->route('admin.tipos-documento.index')
            ->with('status', $mensaje)
            ->with('aviso', $avisos ? implode(' ', $avisos) : null);
    }
}
