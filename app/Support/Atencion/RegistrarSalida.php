<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\SalidaSinAtencion;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\ContextoAuditoria;
use App\Support\Fecha;
use Illuminate\Support\Facades\DB;

/**
 * Registrar que un paciente se retiró (RETIRO) o rechazó la atención (RECHAZO) sin ser atendido: el turno PENDIENTE,
 * CONFIRMADO o SALTADO pasa a RETIRADO (su consulta EN_PREPARACION a ANULADO, con lo cargado intacto) y queda la
 * SalidaSinAtencion. Si el triaje estaba terminado, queda además un cargo pendiente (una marca: sin monto ni tarifa).
 * Lo hace recepción desde Turnos (ejecutar) o el cierre de jornada (registrar). Con el turno bloqueado; nada se borra.
 */
final class RegistrarSalida
{
    public const FECHA_FUTURA = 'Solo se registra la salida de turnos de hoy o de días anteriores.';

    public static function ejecutar(User $usuario, Turno $turno, string $tipo): SalidaSinAtencion
    {
        return DB::transaction(function () use ($usuario, $turno, $tipo) {
            $turno = Turno::with('estado')->whereKey($turno->id)->lockForUpdate()->firstOrFail();
            if ($turno->fecha->format('Y-m-d') > Fecha::hoy()->format('Y-m-d')) {
                throw new AccionRechazada(self::FECHA_FUTURA);
            }

            return self::registrar($usuario, $turno, $tipo, SalidaSinAtencion::ORIGEN_RECEPCION);
        });
    }

    /** Con el turno ya bloqueado y dentro de la transacción del llamador. */
    public static function registrar(User $usuario, Turno $turno, string $tipo, string $origen): SalidaSinAtencion
    {
        if (! array_key_exists($tipo, SalidaSinAtencion::TIPOS)) {
            throw new AccionRechazada('El tipo de salida no es válido.');
        }
        if (! $turno->puedePasarA(Estado::RETIRADO)) {
            throw new AccionRechazada(sprintf('No se puede registrar el %s de un turno %s.', mb_strtolower(SalidaSinAtencion::TIPOS[$tipo]), mb_strtolower((string) $turno->estado?->nombre)));
        }
        // Con la consulta bloqueada (no se termina el triaje en el medio) y antes de pasarA, que la anula.
        $consulta = Consulta::where('turno_id', $turno->id)->lockForUpdate()->first();
        $terminado = $consulta?->enPreparacion() && $consulta->preparada_en !== null;

        // En el cierre de jornada se conserva su detalle ("Cierre de jornada. Urgencias sin atender revisadas: N").
        $previo = app(ContextoAuditoria::class)->detalle;
        $detalle = SalidaSinAtencion::TIPOS[$tipo].' registrado';

        return Auditoria::conDetalle($previo ? "{$previo}. {$detalle}" : $detalle, function () use ($usuario, $turno, $tipo, $origen, $terminado) {
            $salida = SalidaSinAtencion::create([
                'turno_id' => $turno->id, 'tipo' => $tipo, 'origen' => $origen,
                'triaje_terminado' => $terminado, 'cargo_pendiente' => $terminado, 'usuario_id' => $usuario->id,
            ]);
            $turno->pasarA(Estado::RETIRADO);

            return $salida;
        });
    }
}
