<?php

namespace App\Support\PdfClinico;

use App\Enums\AccionAuditoria;
use App\Models\Consulta;
use App\Models\ExamenFisico;
use App\Models\User;
use App\Support\Atencion\DatosFormulario;
use App\Support\Auditoria;
use App\Support\Fecha;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF clínico (docs/pdf-clinico.md): el de triaje y el de la consulta, generados al vuelo con dompdf. Solo lo ya
 * registrado, tal cual: nada se guarda en el servidor (el PDF va directo a la respuesta) ni en el navegador (no-store).
 * Cada generación queda en Auditoría (EXPORTAR sobre la consulta, con el documento). Sin recetas, indicaciones ni
 * reposo (fuera de esta fase).
 */
final class GenerarPdf
{
    public const TRIAJE = 'triaje';

    public const CONSULTA = 'consulta';

    public const TITULOS = [self::TRIAJE => 'Triaje', self::CONSULTA => 'Consulta'];

    private const RELACIONES = [
        'historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona', 'turno', 'estado',
        'bloquesAnamnesis.tipoBloqueAnamnesis', 'bloquesAnamnesis.usuario.persona.personalSalud.cargoSalud', 'bloquesAnamnesis.modificadoPor.persona.personalSalud.cargoSalud',
        'examenFisico.signosUsuario.persona.personalSalud.cargoSalud', 'examenFisico.hallazgosUsuario.persona.personalSalud.cargoSalud',
        'urgentePor.persona.personalSalud.cargoSalud',
    ];

    /** El HTML del documento (lo que dompdf convierte): solo lo registrado, tal cual. Separado para poder verificar el contenido. */
    public static function html(User $usuario, Consulta $consulta, string $documento): string
    {
        $consulta->load([...self::RELACIONES, ...($documento === self::CONSULTA ? ['diagnosticos.cie10'] : [])]);
        $paciente = $consulta->historiaClinica->paciente;
        $examen = $consulta->examenFisico;

        return view('pdf-clinico.'.$documento, [
            'titulo' => self::TITULOS[$documento],
            'clinica' => (string) config('clinexa.clinica.nombre'),
            'consulta' => $consulta,
            'paciente' => $paciente,
            'persona' => $paciente->persona,
            'profesional' => $consulta->profesional->persona->nombre_completo,
            'estadoTexto' => match (true) {
                $consulta->enPreparacion() => 'En triaje',
                $consulta->enCurso() => 'En curso',
                default => 'Finalizada',
            },
            'generadoPor' => DatosFormulario::nombre($usuario->loadMissing('persona.personalSalud.cargoSalud')),
            'generadoEn' => Fecha::mostrar(Fecha::ahora(), conHora: true),
            'urgentePor' => $consulta->urgentePor ? DatosFormulario::nombre($consulta->urgentePor) : null,
            'signos' => collect(ExamenFisico::SIGLAS)
                ->map(fn (string $sigla, string $campo) => [$sigla, $examen?->valorTexto($campo), ExamenFisico::CAMPOS[$campo][0], $examen?->alerta($campo)])
                ->filter(fn (array $signo) => $signo[1] !== null),
            'bloques' => $consulta->bloquesAnamnesis->sortBy(['orden', 'id']),
            'diagnosticos' => $documento === self::CONSULTA
                ? $consulta->diagnosticos->sortBy([['activo', 'desc'], ['principal', 'desc'], ['id', 'asc']])
                : collect(),
            'examen' => $examen,
        ])->render();
    }

    public static function ejecutar(User $usuario, Consulta $consulta, string $documento): Response
    {
        $vista = self::html($usuario, $consulta, $documento);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        Auditoria::registrar(
            AccionAuditoria::EXPORTAR,
            'consultas',
            $consulta->id,
            detalle: 'PDF de '.mb_strtolower(self::TITULOS[$documento])." de la consulta {$consulta->id}",
        );

        $contenido = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true])
            ->loadHTML($vista)->setPaper('a4')->output();

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$documento.'-consulta-'.$consulta->id.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
