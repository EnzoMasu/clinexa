<?php

namespace App\Support\Convenios;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Prestacion;
use App\Support\Auditoria;
use App\Support\Fecha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * El ticket de la aseguradora (docs/convenios-seguros.md): la planilla que firma la paciente, con TODAS las prestaciones de aseguradora
 * de una consulta finalizada. Clínica y logotipo, profesional, fecha y hora, nombre y cédula de la paciente, aseguradora, plan, número
 * de asegurado, número de visación, procedimientos y la línea de firma. SIN montos ni nada clínico.
 *
 * Al vuelo, como los demás PDF: nada se guarda (no-store) y cada generación queda en Auditoría (EXPORTAR). El permiso (EXPORTAR sobre
 * VISACIONES: Recepción, Facturación y convenios, Gerencia y el Administrador) lo controla la ruta.
 */
final class TicketVisacion
{
    public const SIN_PRESTACIONES = 'Esta consulta no tiene prestaciones de aseguradora: no hay ticket para imprimir.';

    /** Las prestaciones de aseguradora (no anuladas) de la consulta, en orden. */
    public static function prestaciones(Consulta $consulta): Collection
    {
        return Prestacion::query()->where('consulta_id', $consulta->id)->where('pagador', Prestacion::ASEGURADORA)
            ->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))
            ->with(['procedimiento', 'visacion.cobertura', 'plan', 'responsablePago.persona'])
            ->orderBy('id')->get();
    }

    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(Consulta $consulta, Collection $prestaciones): string
    {
        $consulta->loadMissing(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona']);
        $persona = $consulta->historiaClinica->paciente->persona;
        $primera = $prestaciones->first();

        return view('pdf-ticket-visacion.hoja', [
            'clinica' => config('clinexa.clinica.nombre'),
            'logo' => public_path(config('clinexa.clinica.logo')),
            'profesional' => $consulta->profesional->persona->nombre_completo,
            'fechaHora' => $consulta->fechaHoraTexto(),
            'paciente' => $persona->nombre_completo,
            'documento' => trim(($persona->tipoDocumento?->codigo ?? '').' '.$persona->nro_documento),
            'aseguradora' => $primera->responsablePago->persona->nombre_completo,
            'plan' => $primera->plan->nombre,
            'asegurado' => $primera->visacion->cobertura->numero_asegurado,
            'tipoAfiliado' => $primera->visacion->cobertura->tipoTexto(),
            'filas' => $prestaciones->map(fn (Prestacion $prestacion) => [
                'procedimiento' => $prestacion->procedimiento?->nombre ?? 'Sin procedimiento',
                'visacion' => $prestacion->numero_visacion,
            ])->all(),
            'generadoEn' => Fecha::mostrar(Fecha::ahora(), conHora: true),
        ])->render();
    }

    public static function ejecutar(Consulta $consulta): Response
    {
        $prestaciones = self::prestaciones($consulta);
        if ($prestaciones->isEmpty()) {
            throw new AccionRechazada(self::SIN_PRESTACIONES);
        }
        $vista = self::html($consulta, $prestaciones);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        $numeros = $prestaciones->pluck('numero_visacion')->unique()->join(', ');
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'visaciones', $prestaciones->first()->visacion_id,
            detalle: "Ticket de la consulta {$consulta->id} (visación N.º {$numeros}; prestaciones ".$prestaciones->pluck('id')->join(', ').')');

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper('a5', 'portrait')->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="ticket-consulta-'.$consulta->id.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
