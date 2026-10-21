<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Equipo;
use App\Models\Estado;
use App\Models\Procedimiento;
use App\Models\Proveedor;
use App\Models\TipoEquipo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Equipos (docs/reparto-economico.md): nombre, tipo, dueño (un proveedor) y los procedimientos que lo usan. En esta fase un
 * procedimiento usa como máximo UN equipo ACTIVO: se valida al guardar, con los procedimientos bloqueados. No se borra: se
 * desactiva. Sin reserva en la agenda ni uso por paciente.
 */
class EquipoController extends Controller
{
    /** Categoría de proveedor que se muestra primero entre los dueños posibles. */
    public const CATEGORIA_DUENOS = 'Equipos médicos';

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.equipos', [
            'equipos' => Equipo::query()
                ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereLike('nombre', "%{$busqueda}%")
                    ->orWhereHas('tipo', fn ($query) => $query->whereLike('nombre', "%{$busqueda}%"))
                    ->orWhereHas('dueno.persona', fn ($query) => $query->whereLike('apellidos', "%{$busqueda}%")
                        ->orWhereLike('nombres', "%{$busqueda}%")->orWhereLike('razon_social', "%{$busqueda}%"))))
                ->with(['tipo', 'dueno.persona', 'procedimientos'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return $this->formulario(new Equipo);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        DB::transaction(function () use ($datos) {
            $this->verificarProcedimientos($datos['procedimientos'], null, true);
            $equipo = Equipo::create(Arr::except($datos, 'procedimientos'));
            $equipo->sincronizarAuditado('procedimientos', $datos['procedimientos']);
        });

        return redirect()->route('admin.equipos.index')->with('status', 'Equipo creado.');
    }

    public function edit(Equipo $equipo): View
    {
        return $this->formulario($equipo);
    }

    public function update(Request $request, Equipo $equipo): RedirectResponse
    {
        $datos = $this->validar($request, $equipo);

        DB::transaction(function () use ($datos, $equipo) {
            $this->verificarProcedimientos($datos['procedimientos'], $equipo, (int) $datos['estado_id'] === Estado::idDe(Estado::ACTIVO));
            $equipo->update(Arr::except($datos, 'procedimientos'));
            $equipo->sincronizarAuditado('procedimientos', $datos['procedimientos']);
        });

        return redirect()->route('admin.equipos.index')->with('status', 'Equipo actualizado.');
    }

    public function desactivar(Equipo $equipo): RedirectResponse
    {
        $equipo->desactivar();

        return redirect()->route('admin.equipos.index')->with('status', 'Equipo desactivado. Sus procedimientos ya no reparten a su dueño.');
    }

    private function formulario(Equipo $equipo): View
    {
        $equipo->loadMissing('procedimientos');

        return view('admin.equipos.form', [
            'equipo' => $equipo,
            'tipos' => TipoEquipo::query()
                ->where(fn ($query) => $query->activos()->when($equipo->tipo_equipo_id, fn ($query) => $query->orWhere('id', $equipo->tipo_equipo_id)))
                ->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'duenos' => $this->duenos($equipo),
            'procedimientos' => Procedimiento::query()
                ->where(fn ($query) => $query->activos()->orWhereIn('id', $equipo->procedimientos->modelKeys()))
                ->orderBy('nombre')->get(),
            // Qué equipo activo (otro) usa ya cada procedimiento, para avisarlo en el formulario.
            'enUso' => DB::table('equipo_procedimiento')
                ->join('equipos', 'equipos.id', '=', 'equipo_procedimiento.equipo_id')
                ->where('equipos.estado_id', Estado::idDe(Estado::ACTIVO))
                ->when($equipo->exists, fn ($query) => $query->where('equipos.id', '!=', $equipo->id))
                ->pluck('equipos.nombre', 'equipo_procedimiento.procedimiento_id')->all(),
        ]);
    }

    /** Proveedores activos (y el dueño actual), los de "Equipos médicos" primero. */
    private function duenos(Equipo $equipo): array
    {
        return Proveedor::query()
            ->where(fn ($query) => $query->activos()->when($equipo->proveedor_id, fn ($query) => $query->orWhere('id', $equipo->proveedor_id)))
            ->with(['persona', 'categorias'])->get()
            ->sortBy(fn (Proveedor $proveedor) => [
                $proveedor->categorias->contains('nombre', self::CATEGORIA_DUENOS) ? 0 : 1,
                mb_strtolower($proveedor->persona->nombre_completo),
            ])
            ->mapWithKeys(fn (Proveedor $proveedor) => [$proveedor->id => $proveedor->persona->nombre_completo
                .($proveedor->categorias->contains('nombre', self::CATEGORIA_DUENOS) ? ' ('.self::CATEGORIA_DUENOS.')' : '')])
            ->all();
    }

    private function validar(Request $request, ?Equipo $equipo = null): array
    {
        $activo = Estado::idDe(Estado::ACTIVO);
        $reglas = [
            'nombre' => ['required', 'string', 'max:150', Rule::unique('equipos')->ignore($equipo)],
            'tipo_equipo_id' => ['required', 'integer', Rule::exists('tipos_equipo', 'id')->where(fn ($query) => $query
                ->where('estado_id', $activo)->when($equipo?->tipo_equipo_id, fn ($query, $actual) => $query->orWhere('id', $actual)))],
            'proveedor_id' => ['required', 'integer', Rule::exists('proveedores', 'id')->where(fn ($query) => $query
                ->where('estado_id', $activo)->when($equipo?->proveedor_id, fn ($query, $actual) => $query->orWhere('id', $actual)))],
            'procedimientos' => ['nullable', 'array'],
            'procedimientos.*' => ['integer', 'distinct', Rule::exists('procedimientos', 'id')],
        ];
        if ($equipo) {
            $reglas['estado_id'] = Equipo::reglaEstado();
        }

        $datos = $request->validate($reglas, [], [
            'tipo_equipo_id' => 'tipo de equipo',
            'proveedor_id' => 'dueño',
            'procedimientos.*' => 'procedimiento',
        ]);
        $datos['procedimientos'] = array_values(array_map('intval', $datos['procedimientos'] ?? []));

        return $datos;
    }

    /**
     * Un procedimiento usa como máximo un equipo ACTIVO: si el que se guarda queda activo, ninguno de sus procedimientos puede
     * estar en otro equipo activo. Bloquea esos procedimientos para que dos guardados simultáneos se ordenen.
     */
    private function verificarProcedimientos(array $procedimientoIds, ?Equipo $equipo, bool $quedaActivo): void
    {
        if (! $quedaActivo || $procedimientoIds === []) {
            return;
        }

        DB::table('procedimientos')->whereIn('id', $procedimientoIds)->lockForUpdate()->get();
        $ocupados = DB::table('equipo_procedimiento')
            ->join('equipos', 'equipos.id', '=', 'equipo_procedimiento.equipo_id')
            ->join('procedimientos', 'procedimientos.id', '=', 'equipo_procedimiento.procedimiento_id')
            ->whereIn('equipo_procedimiento.procedimiento_id', $procedimientoIds)
            ->where('equipos.estado_id', Estado::idDe(Estado::ACTIVO))
            ->when($equipo, fn ($query) => $query->where('equipos.id', '!=', $equipo->id))
            ->orderBy('procedimientos.nombre')
            ->get(['procedimientos.nombre as procedimiento', 'equipos.nombre as equipo']);

        if ($ocupados->isNotEmpty()) {
            throw ValidationException::withMessages(['procedimientos' => $ocupados
                ->map(fn ($fila) => "El procedimiento {$fila->procedimiento} ya usa el equipo {$fila->equipo}. Un procedimiento usa como máximo un equipo activo.")
                ->all()]);
        }
    }
}
