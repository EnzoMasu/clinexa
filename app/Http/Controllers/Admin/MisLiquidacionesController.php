<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\LiquidacionBeneficiario;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\LiquidacionesBeneficiarios\PlanillaLiquidacionBeneficiario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Mis liquidaciones" (docs/liquidacion-beneficiarios.md): quien tiene su usuario vinculado a su persona ve SOLO las suyas, como profesional
 * y como dueño de equipo (si es las dos cosas, las dos, cada una con su rol), y SOLO las aprobadas (con o sin pagos), de solo lectura, con
 * su planilla. Ni las que están en preparación, ni las de otros, ni las anuladas: para esas responde 404. Sin acciones. La del dueño del
 * equipo no lleva la paciente.
 */
class MisLiquidacionesController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.mis-liquidaciones.index', [
            'liquidaciones' => self::propias($request->user())->with('moneda')->withSum('pagosVigentes', 'monto')
                ->orderByDesc('fecha_corte')->orderByDesc('id')->paginate(20),
            'esBeneficiario' => self::profesionales($request->user()) !== [] || self::proveedores($request->user()) !== [],
        ]);
    }

    public function show(Request $request, LiquidacionBeneficiario $liquidacion): View
    {
        $liquidacion = self::propia($request->user(), $liquidacion);
        $liquidacion->load(['profesional.persona', 'proveedor.persona', 'moneda', 'lineas', 'pagosVigentes.medioPago']);

        return view('admin.mis-liquidaciones.show', [
            'liquidacion' => $liquidacion,
            'lineas' => LiquidacionBeneficiarioController::ordenarLineas($liquidacion->lineas->whereNull('liberada_en')),
        ]);
    }

    public function planilla(Request $request, LiquidacionBeneficiario $liquidacion): Response
    {
        try {
            return PlanillaLiquidacionBeneficiario::ejecutar(self::propia($request->user(), $liquidacion));
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Los profesionales de la persona del usuario (normalmente uno). */
    private static function profesionales(User $usuario): array
    {
        return $usuario->persona_id ? Profesional::where('persona_id', $usuario->persona_id)->pluck('id')->all() : [];
    }

    /** Los proveedores (dueños de equipo) de la persona del usuario. */
    private static function proveedores(User $usuario): array
    {
        return $usuario->persona_id ? Proveedor::where('persona_id', $usuario->persona_id)->pluck('id')->all() : [];
    }

    private static function propias(User $usuario): Builder
    {
        $profesionales = self::profesionales($usuario);
        $proveedores = self::proveedores($usuario);

        return LiquidacionBeneficiario::query()->aprobadas()->where(fn (Builder $query) => $query
            ->where(fn (Builder $q) => $q->where('beneficiario', LiquidacionBeneficiario::PROFESIONAL)->whereIn('profesional_id', $profesionales))
            ->orWhere(fn (Builder $q) => $q->where('beneficiario', LiquidacionBeneficiario::EQUIPO)->whereIn('proveedor_id', $proveedores)));
    }

    private static function propia(User $usuario, LiquidacionBeneficiario $liquidacion): LiquidacionBeneficiario
    {
        abort_unless(self::propias($usuario)->whereKey($liquidacion->id)->exists(), 404);

        return $liquidacion;
    }
}
