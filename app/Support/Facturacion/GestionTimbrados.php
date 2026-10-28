<?php

namespace App\Support\Facturacion;

use App\Exceptions\AccionRechazada;
use App\Models\Clinica;
use App\Models\Estado;
use App\Models\Sucursal;
use App\Models\Timbrado;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Los timbrados (simulados, docs/facturacion.md), que carga Gerencia o el Administrador. Nada se borra ni se edita:
 *
 * - Cargar: de la clínica y de una sucursal suya, con número, vigencia, establecimiento, punto de expedición y rango de numeración. Si
 *   ya hay uno vigente para esa sucursal, establecimiento y punto, pasa a HISTORICO en la misma transacción (uno vigente:
 *   `timbrados_uno_vigente`). La numeración empieza en el número inicial.
 * - Pasar a histórico: deja de emitir (sus facturas siguen como están).
 *
 * En Auditoría: CREAR del timbrado ("Alta del timbrado N.º X") y EDITAR del que pasa a histórico.
 */
final class GestionTimbrados
{
    /** @param  array{sucursal_id: int, numero: string, vigente_desde: string, vigente_hasta: string, establecimiento: string, punto_expedicion: string, numero_inicial: int, numero_final: int}  $datos */
    public static function cargar(User $usuario, array $datos): Timbrado
    {
        $clinica = Clinica::actual() ?? throw new AccionRechazada('Cargue primero los datos de la clínica (Sucursales → Datos de la clínica).');
        $sucursal = Sucursal::find($datos['sucursal_id']);
        if ($sucursal === null || (int) $sucursal->clinica_id !== (int) $clinica->id) {
            throw new AccionRechazada('La sucursal no es de la clínica: asígnela en Sucursales.');
        }
        if ($datos['vigente_hasta'] < $datos['vigente_desde']) {
            throw new AccionRechazada('La vigencia debe terminar el mismo día en que empieza o después.');
        }
        if ((int) $datos['numero_final'] < (int) $datos['numero_inicial']) {
            throw new AccionRechazada('El número final debe ser igual o mayor que el inicial.');
        }

        try {
            return DB::transaction(function () use ($usuario, $datos, $clinica, $sucursal) {
                $anterior = Timbrado::query()->vigentes()->where('sucursal_id', $sucursal->id)->where('establecimiento', $datos['establecimiento'])
                    ->where('punto_expedicion', $datos['punto_expedicion'])->lockForUpdate()->first();
                if ($anterior !== null) {
                    Auditoria::conDetalle("Reemplazado por el timbrado N.º {$datos['numero']}", fn () => self::historico($usuario, $anterior));
                }

                return Auditoria::conDetalle("Alta del timbrado N.º {$datos['numero']}", function () use ($usuario, $datos, $clinica, $sucursal) {
                    $timbrado = new Timbrado([
                        'clinica_id' => $clinica->id, 'sucursal_id' => $sucursal->id, 'numero' => $datos['numero'],
                        'vigente_desde' => $datos['vigente_desde'], 'vigente_hasta' => $datos['vigente_hasta'],
                        'establecimiento' => $datos['establecimiento'], 'punto_expedicion' => $datos['punto_expedicion'],
                        'numero_inicial' => (int) $datos['numero_inicial'], 'numero_final' => (int) $datos['numero_final'],
                        'numero_actual' => (int) $datos['numero_inicial'] - 1, 'cargado_por' => $usuario->id,
                    ]);
                    $timbrado->estado_id = Estado::idDe(Estado::VIGENTE);
                    $timbrado->save();

                    return $timbrado;
                });
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'timbrados_uno_vigente') || str_contains($e->getMessage(), 'timbrados.sucursal_id')) {
                throw new AccionRechazada('Ya hay otro timbrado vigente para ese establecimiento y punto de expedición.');
            }
            throw $e;
        }
    }

    public static function pasarAHistorico(User $usuario, Timbrado $timbrado): Timbrado
    {
        return DB::transaction(function () use ($usuario, $timbrado) {
            $actual = Timbrado::query()->whereKey($timbrado->getKey())->lockForUpdate()->firstOrFail();
            if ($actual->historico()) {
                throw new AccionRechazada('Este timbrado ya es histórico.');
            }

            return Auditoria::conDetalle('Timbrado pasado a histórico', fn () => self::historico($usuario, $actual));
        });
    }

    private static function historico(User $usuario, Timbrado $timbrado): Timbrado
    {
        $timbrado->forceFill(['estado_id' => Estado::idDe(Estado::HISTORICO), 'historico_en' => now(), 'historico_por' => $usuario->id])->save();
        $timbrado->unsetRelation('estado');

        return $timbrado;
    }
}
