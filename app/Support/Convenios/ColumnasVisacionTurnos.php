<?php

namespace App\Support\Convenios;

use App\Models\Estado;
use App\Models\ResponsablePago;
use App\Support\Fecha;
use App\Support\Grilla\Grilla;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Las columnas "Seguro" y "Visación" de la grilla de Turnos: el mismo criterio que EstadoVisacionTurnos (cobertura abierta y vigente
 * hoy; turno no cancelado; visación vigente del turno o de su consulta), pero en SQL, para que ordenen y filtren en la base.
 * Solo se declaran con VER sobre VISACIONES. El seguro filtra por el id real del responsable de pago.
 */
class ColumnasVisacionTurnos
{
    public const FALTA = 'FALTA';

    public const VISADO = 'VISADO';

    public const SIN_SEGURO = 'SIN_SEGURO';

    public const ESTADOS = [self::FALTA => 'Falta visar', self::VISADO => 'Visado', self::SIN_SEGURO => 'Sin seguro'];

    public static function agregar(Grilla $grilla): void
    {
        if (! EstadoVisacionTurnos::puedeVer()) {
            return;
        }
        $grilla
            ->opciones('seguro', 'Seguro', 'turnos.id', self::aseguradoras(),
                orden: fn ($query) => self::cobertura($query->from('coberturas_paciente as cp'))
                    ->join('responsables_pago as rp', 'rp.id', '=', 'conv.responsable_pago_id')->join('personas as pe', 'pe.id', '=', 'rp.persona_id')
                    ->selectRaw("COALESCE(pe.apellidos || ', ' || pe.nombres, pe.apellidos, pe.razon_social, pe.nombres)"),
                filtro: fn ($query, string $id) => $query->whereExists(fn ($sub) => self::cobertura($sub->from('coberturas_paciente as cp'))->where('conv.responsable_pago_id', (int) $id)))
            ->opciones('visacion', 'Visación', 'turnos.id', self::ESTADOS,
                orden: fn ($query) => $query->selectRaw('CASE WHEN NOT EXISTS ('.self::sqlCobertura().') THEN 0 WHEN EXISTS ('.self::sqlVisada().') THEN 2 ELSE 1 END'),
                filtro: fn ($query, string $estado) => match ($estado) {
                    self::SIN_SEGURO => $query->whereRaw('NOT EXISTS ('.self::sqlCobertura().')'),
                    self::VISADO => $query->whereRaw('EXISTS ('.self::sqlVisada().')'),
                    default => $query->whereRaw('EXISTS ('.self::sqlCobertura().')')->whereRaw('NOT EXISTS ('.self::sqlVisada().')'),
                });
    }

    /** responsable_pago_id => nombre, de las aseguradoras con convenio. */
    private static function aseguradoras(): array
    {
        return ResponsablePago::query()->whereIn('id', fn ($q) => $q->select('responsable_pago_id')->from('convenios'))
            ->with('persona')->get()->sortBy(fn ($r) => $r->persona->nombre_completo)->mapWithKeys(fn ($r) => [$r->id => $r->persona->nombre_completo])->all();
    }

    /** Cobertura abierta y vigente hoy de la paciente del turno (alias cp, planes pl, convenios conv), si el turno no está cancelado. */
    private static function cobertura(Builder $query): Builder
    {
        $hoy = Fecha::hoy()->format('Y-m-d');

        return $query->join('planes_convenio as pl', 'pl.id', '=', 'cp.plan_convenio_id')->join('convenios as conv', 'conv.id', '=', 'pl.convenio_id')
            ->whereColumn('cp.paciente_id', 'turnos.paciente_id')->whereNull('cp.cerrada_en')
            ->where('cp.vigente_desde', '<=', $hoy)->where(fn ($q) => $q->whereNull('cp.vigente_hasta')->orWhere('cp.vigente_hasta', '>=', $hoy))
            ->where('turnos.estado_id', '<>', Estado::idDe(Estado::CANCELADO))->limit(1);
    }

    private static function sqlCobertura(): string
    {
        return self::cobertura(\Illuminate\Support\Facades\DB::table('coberturas_paciente as cp')->select('cp.id'))->toRawSql();
    }

    private static function sqlVisada(): string
    {
        return self::cobertura(\Illuminate\Support\Facades\DB::table('coberturas_paciente as cp')->select('cp.id'))
            ->join('visaciones as vi', 'vi.cobertura_paciente_id', '=', 'cp.id')->where('vi.estado_id', Estado::idDe(Estado::VIGENTE))
            ->where(fn ($q) => $q->whereColumn('vi.turno_id', 'turnos.id')
                ->orWhereIn('vi.consulta_id', fn ($c) => $c->select('id')->from('consultas')->whereColumn('consultas.turno_id', 'turnos.id')))
            ->toRawSql();
    }
}
