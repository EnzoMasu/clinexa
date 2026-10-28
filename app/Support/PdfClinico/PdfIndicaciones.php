<?php

namespace App\Support\PdfClinico;

use App\Enums\AccionAuditoria;
use App\Models\Consulta;
use App\Models\Indicacion;
use App\Support\Auditoria;
use App\Support\Fecha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF de las indicaciones generales de la consulta (docs/indicaciones-generales.md), en hoja aparte. Patrón del PDF clínico:
 * al vuelo con dompdf, toma lo ya guardado, no se guarda en el servidor ni en el navegador (no-store) y cada generación queda
 * en Auditoría (EXPORTAR). Sin numeración ni huella. Solo encabezado (clínica y logotipo, paciente, profesional, fecha de la
 * consulta) y las indicaciones vigentes tal cual: sin medicamentos, diagnósticos ni receta, sin textos legales ni clínicos.
 */
final class PdfIndicaciones
{
    public const DOCUMENTO = 'indicaciones';

    public const SIN_INDICACIONES = 'Esta consulta no tiene indicaciones generales vigentes: no hay nada para imprimir.';

    /** Las vigentes (las retiradas no salen), en el orden de la pantalla. */
    public static function vigentes(Consulta $consulta): Collection
    {
        return $consulta->indicaciones()->with('tipoIndicacion')->where('activo', true)->orderBy('orden')->orderBy('id')->get();
    }

    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(Consulta $consulta, Collection $indicaciones): string
    {
        $consulta->loadMissing(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona']);
        $persona = $consulta->historiaClinica->paciente->persona;

        return view('pdf-indicaciones.hoja', [
            'clinica' => (string) config('clinexa.clinica.nombre'),
            'logo' => public_path(config('clinexa.clinica.logo')),
            'paciente' => $persona->nombre_completo,
            'documento' => $persona->documentoPrincipal(),
            'profesional' => $consulta->profesional->persona->nombre_completo,
            // Día en que se inició la consulta, en hora local (como la fecha de atención del reposo).
            'fecha' => Fecha::mostrar(Carbon::parse($consulta->fechaAtencion())->setTimezone(config('app.zona_horaria_local'))->format('Y-m-d')),
            'indicaciones' => $indicaciones->map(fn (Indicacion $indicacion) => [
                'tipo' => $indicacion->tipoIndicacion?->nombre,
                'texto' => $indicacion->descripcion,
            ]),
        ])->render();
    }

    /** Quien lo llama ya verificó que hay indicaciones vigentes: una consulta sin ellas no genera un PDF vacío. */
    public static function ejecutar(Consulta $consulta, Collection $indicaciones): Response
    {
        $vista = self::html($consulta, $indicaciones);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'consultas', $consulta->id, detalle: "PDF de indicaciones generales de la consulta {$consulta->id}");

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper('a4')->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="indicaciones-consulta-'.$consulta->id.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
