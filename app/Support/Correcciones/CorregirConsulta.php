<?php

namespace App\Support\Correcciones;

use App\Exceptions\AccionRechazada;
use App\Models\ConsultaVersion;
use App\Models\DesbloqueoConsulta;
use App\Models\Estado;
use App\Models\User;
use App\Support\Atencion\Apoyo;
use App\Support\Atencion\FormularioConsulta;
use App\Support\Atencion\GuardarSecciones;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * La corrección de una consulta cerrada con un desbloqueo VIGENTE, en UN solo guardado y una transacción: con la
 * consulta y el desbloqueo bloqueados se revalida todo (consulta finalizada, desbloqueo de esa consulta, vigente y sin
 * vencer, supervisor que puede usarlo); se guarda la VERSIÓN con lo de antes; se escribe dentro de la ventana
 * (VentanaDeCorreccion: solo motivo, anamnesis, hallazgos, diagnósticos, indicaciones e informes de estudio) y el desbloqueo pasa a
 * REALIZADO. Si no cambió nada, no se consume. Queda en la auditoría como EDITAR de la consulta con "Corrección
 * autorizada (desbloqueo #N)", antes y después.
 */
final class CorregirConsulta
{
    public const GRUPOS = [FormularioConsulta::CLINICO, FormularioConsulta::ANAMNESIS];

    public const SIN_CAMBIOS = 'No hay cambios para guardar. El desbloqueo sigue vigente.';

    public const NO_VIGENTE = 'Este desbloqueo ya no está vigente (se usó, venció o se canceló).';

    /** @param array<string, mixed> $datos ya validados con FormularioConsulta (grupos GRUPOS, reglas finales) */
    public static function ejecutar(User $usuario, DesbloqueoConsulta $desbloqueo, array $datos): ConsultaVersion
    {
        DesbloqueoConsulta::vencerLosVencidos($desbloqueo->consulta_id);

        return DB::transaction(function () use ($usuario, $desbloqueo, $datos) {
            $consulta = Apoyo::consultaBloqueada($desbloqueo->consulta);
            $desbloqueo = DesbloqueoConsulta::whereKey($desbloqueo->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($usuario)->authorize('desbloquear', $consulta);
            if (! $desbloqueo->estaVigente()) {
                throw new AccionRechazada(self::NO_VIGENTE);
            }

            $antes = Instantanea::de($consulta);
            $version = ConsultaVersion::create([
                'consulta_id' => $consulta->id, 'numero' => (int) ConsultaVersion::where('consulta_id', $consulta->id)->max('numero') + 1,
                'snapshot' => $antes, 'desbloqueo_id' => $desbloqueo->id, 'usuario_id' => $usuario->id,
            ]);

            Auditoria::conDetalle("Corrección autorizada (desbloqueo #{$desbloqueo->id})", fn () => VentanaDeCorreccion::dentro($desbloqueo,
                fn () => GuardarSecciones::guardar($consulta, $datos, $usuario, self::GRUPOS)));

            if (Instantanea::de($consulta->fresh()) == $antes) {
                throw new AccionRechazada(self::SIN_CAMBIOS);
            }

            Auditoria::conDetalle("Desbloqueo #{$desbloqueo->id} usado", fn () => $desbloqueo->forceFill(['estado_id' => Estado::idDe(Estado::REALIZADO), 'usado_en' => now()])->save());

            return $version;
        });
    }
}
