<?php

namespace App\Support\PdfClinico;

use App\Enums\AccionAuditoria;
use App\Models\Consulta;
use App\Models\InformeEstudio;
use App\Models\Sucursal;
use App\Support\Auditoria;
use App\Support\Fecha;
use App\Support\HojaReceta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF de un informe de estudio (docs/informes-estudio.md). Patrón del PDF clínico: al vuelo con dompdf, toma lo ya guardado,
 * no se guarda en el servidor ni en el navegador (no-store) y cada generación queda en Auditoría (EXPORTAR sobre la consulta).
 * Sin numeración ni huella. Lista cerrada: clínica y logotipo, paciente (nombre, documento, nacimiento y edad, ficha),
 * profesional (nombre, especialidades, matrícula), fecha de la consulta, estudio, solicitante si hay, el texto tal cual y las
 * líneas de firma y sello. Nunca diagnósticos, medicamentos ni nada más de la consulta. Todo escapado.
 */
final class PdfInformeEstudio
{
    /** Para ConsultaPolicy::generarPdf: la regla del PDF de la consulta. */
    public const DOCUMENTO = 'informe-estudio';

    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(Consulta $consulta, InformeEstudio $informe): string
    {
        $consulta->loadMissing(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona', 'profesional.especialidadesActivas', 'turno.consultorio.sucursal']);
        $paciente = $consulta->historiaClinica->paciente;
        $persona = $paciente->persona;
        $profesional = $consulta->profesional;
        $sucursal = $consulta->turno?->consultorio?->sucursal ?? Sucursal::activos()->orderBy('id')->first();
        // Día en que se inició la consulta, en hora local; la edad, cumplida ese día.
        $dia = Carbon::parse($consulta->fechaAtencion())->setTimezone(config('app.zona_horaria_local'))->startOfDay();
        $edad = $persona->fecha_nacimiento ? (int) $persona->fecha_nacimiento->diffInYears($dia) : null;

        return view('pdf-informe-estudio.hoja', [
            'clinica' => ['nombre' => (string) config('clinexa.clinica.nombre'), 'direccion' => $sucursal?->direccion, 'telefono' => $sucursal?->telefono],
            'logo' => public_path(config('clinexa.clinica.logo')),
            'paciente' => [
                'nombre' => HojaReceta::nombreNatural($persona),
                'documento' => trim(($persona->tipoDocumento?->codigo ?? '').' '.$persona->nro_documento),
                'fecha_nacimiento' => $persona->fecha_nacimiento ? Fecha::mostrar($persona->fecha_nacimiento) : null,
                'edad' => $edad === null ? null : $edad.' '.($edad === 1 ? 'año' : 'años'),
                'ficha' => $paciente->nro_ficha,
            ],
            'profesional' => [
                'nombre' => HojaReceta::nombreNatural($profesional->persona),
                'especialidades' => $profesional->especialidadesActivas->pluck('nombre')->sort()->values()->all(),
                'matricula' => $profesional->matricula,
            ],
            'fecha' => Fecha::mostrar($dia->format('Y-m-d')),
            'estudio' => $informe->estudio_nombre,
            'solicitante' => $informe->solicitante,
            'texto' => $informe->texto,
        ])->render();
    }

    /** Quien lo llama ya verificó que el informe es de esta consulta y está vigente. */
    public static function ejecutar(Consulta $consulta, InformeEstudio $informe): Response
    {
        $vista = self::html($consulta, $informe);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'consultas', $consulta->id, detalle: "PDF del informe de estudio {$informe->id} de la consulta {$consulta->id}");

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper('a4')->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="informe-estudio-'.$informe->id.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
