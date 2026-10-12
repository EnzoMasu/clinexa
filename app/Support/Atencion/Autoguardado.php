<?php

namespace App\Support\Atencion;

use App\Exceptions\ConsultaCerrada;
use App\Models\Consulta;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Autoguardado de una consulta EN_PREPARACION o EN_CURSO (preparación y pantalla de atención).
 *
 * - Solo los grupos que el usuario puede escribir (ConsultaPolicy). Si quien solo escribe la PREPARACIÓN
 *   manda algún campo CLÍNICO: 403, sin guardar nada.
 * - Validación de borrador (FormularioConsulta): nada obligatorio; las filas incompletas no se guardan y
 *   se informan.
 * - Control de concurrencia: la versión (updated_at con microsegundos) tiene que coincidir; si no, 409 y
 *   no se pisa nada. Si la consulta ya no está abierta, 409.
 * - Cada guardado efectivo queda como EDITAR de la consulta con detalle "Borrador (autoguardado)"; uno sin
 *   cambios no genera eventos.
 * - Signos vitales (SignosVitales): un valor muy alterado sin su confirmación exacta no se guarda y vuelve en
 *   "pendientes" (el controlador responde 422 con código requiere_confirmacion); los demás campos sí se guardan.
 */
final class Autoguardado
{
    public const VERSION_VIEJA = 'Esta consulta se modificó desde otra ventana. Recargue la página.';

    /**
     * @return array{version: string, guardado: string, incompletas: array<string, list<string>>, ids: array<string, array<string, int>>, pendientes: array<string, array<string, string>>}
     */
    public static function ejecutar(User $usuario, Consulta $consulta, Request $request): array
    {
        // Solo EN_PREPARACION y EN_CURSO; cerrada (FINALIZADA o ANULADA): 409 (también lo frena antes el
        // middleware consulta.abierta, y de fondo los modelos).
        if (! $consulta->abierta()) {
            throw new ConsultaCerrada;
        }

        $grupos = self::grupos($usuario, $consulta);
        // Sin ningún grupo: el motivo de la policy (p. ej. "Solo el personal de salud puede cargar el triaje.").
        abort_if($grupos === [], 403, Gate::forUser($usuario)->inspect('escribirPreparacion', $consulta)->message()
            ?? 'No tiene permiso para modificar esta consulta.');
        abort_if(FormularioConsulta::traeCamposClinicos($request->all()) && ! in_array(FormularioConsulta::CLINICO, $grupos, true), 403,
            'No tiene permiso para cargar el motivo, los hallazgos, los diagnósticos ni las indicaciones.');
        abort_if((string) $request->input('version') !== $consulta->version(), 409, self::VERSION_VIEJA);

        [$datos, $incompletas] = FormularioConsulta::validar($request, $consulta, $grupos, final: false);
        $version = (string) $request->input('version');
        // Los valores muy alterados sin su confirmación exacta no se guardan (los demás campos sí).
        $pendientes = in_array(FormularioConsulta::PREPARACION, $grupos, true)
            ? SignosVitales::revisar($consulta, $datos, $request->input('confirmaciones'))
            : [];

        $ids = DB::transaction(function () use ($usuario, $consulta, $datos, $grupos, $version) {
            $actual = Apoyo::consultaBloqueada($consulta);
            if (! $actual->abierta()) {
                throw new ConsultaCerrada; // se cerró entre el pedido y el bloqueo
            }
            abort_if($actual->version() !== $version, 409, self::VERSION_VIEJA);

            $ids = Auditoria::conDetalle('Borrador (autoguardado)', fn () => GuardarSecciones::guardar($consulta, $datos, $usuario, $grupos));
            $consulta->touch(); // nueva versión

            return $ids;
        });

        return [
            'version' => $consulta->fresh()->version(),
            'guardado' => Fecha::mostrar(now(), conHora: true),
            'incompletas' => $incompletas,
            'ids' => $ids,
            // campo => [valor, texto, mensaje]: no se guardaron, esperan la confirmación (el controlador responde 422).
            'pendientes' => $pendientes,
        ];
    }

    /**
     * Los grupos de campos que el usuario puede escribir en esta consulta.
     *
     * @return list<string>
     */
    public static function grupos(User $usuario, Consulta $consulta): array
    {
        return array_values(array_filter([
            Gate::forUser($usuario)->allows('escribirPreparacion', $consulta) ? FormularioConsulta::PREPARACION : null,
            Gate::forUser($usuario)->allows('escribirClinico', $consulta) ? FormularioConsulta::CLINICO : null,
        ]));
    }
}
