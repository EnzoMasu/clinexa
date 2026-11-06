<?php

namespace App\Support\Convenios;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\CoberturaPaciente;
use App\Models\Estado;
use App\Models\LineaVisacion;
use App\Models\Prestacion;
use App\Models\Turno;
use App\Models\Visacion;
use App\Support\Auditoria;
use App\Support\Fecha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * La planilla de aceptación de consulta (docs/convenios-seguros.md), antes "ticket de la aseguradora": la que firma la paciente, una por
 * consulta o turno. Con las prestaciones de aseguradora de la consulta; si todavía no hay (antes de finalizar), con las líneas aprobadas de
 * la visación del turno o de la consulta, o con el procedimiento del turno ("Sin visación"). Nunca bloquea por una cobertura vencida o
 * sin visar: lo avisa con una leyenda. Es el mismo documento desde el turno, la atención y la ficha de cobertura. Clínica y logotipo, profesional, fecha y hora, nombre y cédula de la paciente, aseguradora, plan, número
 * de asegurado, número de visación, procedimientos y la línea de firma. SIN montos ni nada clínico.
 *
 * Al vuelo, como los demás PDF: nada se guarda (no-store) y cada generación queda en Auditoría (EXPORTAR). El permiso (EXPORTAR sobre
 * VISACIONES: Recepción, Facturación y convenios, Gerencia y el Administrador) lo controla la ruta.
 */
final class TicketVisacion
{
    public const A5 = 'a5';

    public const TICKET = 'ticket';

    /** 80 mm en puntos; el alto mínimo y el máximo de la hoja de prueba. */
    private const ANCHO_TICKET_PT = 226.77;

    private const MARGEN_TICKET_PT = 8.5;

    private const ALTO_MINIMO_PT = 280;

    private const ALTO_MAXIMO_PT = 3000;

    public const SIN_COBERTURA = 'La paciente no tiene una cobertura cargada: no hay planilla de aceptación para imprimir.';

    /** Las prestaciones de aseguradora (no anuladas) de la consulta, en orden. */
    public static function prestaciones(Consulta $consulta): Collection
    {
        return Prestacion::query()->where('consulta_id', $consulta->id)->where('pagador', Prestacion::ASEGURADORA)
            ->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))
            ->with(['procedimiento', 'visacion.cobertura', 'plan', 'responsablePago.persona'])
            ->orderBy('id')->get();
    }

    /** Las visaciones vigentes del turno o de la consulta, con sus líneas aprobadas. */
    private static function visaciones(?Consulta $consulta, ?Turno $turno): Collection
    {
        if ($consulta === null && $turno === null) {
            return collect();
        }

        return Visacion::query()->vigentes()
            ->where(fn ($q) => $q->when($consulta, fn ($q) => $q->orWhere('consulta_id', $consulta->id))->when($turno, fn ($q) => $q->orWhere('turno_id', $turno->id)))
            ->with(['cobertura.plan.convenio.responsablePago.persona', 'lineas.procedimiento'])->orderBy('id')->get();
    }

    /** La cobertura abierta de la paciente (vigente o vencida), la que se imprime si no hay visación. */
    public static function coberturaAbierta(int $pacienteId): ?CoberturaPaciente
    {
        return CoberturaPaciente::query()->where('paciente_id', $pacienteId)->whereNull('cerrada_en')
            ->with('plan.convenio.responsablePago.persona')->orderByDesc('vigente_desde')->orderByDesc('id')->first();
    }

    /**
     * Todo lo que lleva la planilla, más las leyendas de aviso. Rechaza solo si la paciente no tiene ninguna cobertura.
     *
     * @return array<string, mixed>
     */
    public static function datos(?Consulta $consulta, ?Turno $turno): array
    {
        $consulta ??= $turno?->consulta;
        $turno ??= $consulta?->turno;
        $consulta?->loadMissing(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona']);
        $turno?->loadMissing(['paciente.persona.tipoDocumento', 'profesional.persona', 'procedimiento']);
        $paciente = $consulta?->historiaClinica->paciente ?? $turno->paciente;
        $profesional = $consulta?->profesional ?? $turno->profesional;
        $persona = $paciente->persona;

        $prestaciones = $consulta ? self::prestaciones($consulta) : collect();
        $visaciones = self::visaciones($consulta, $turno);
        $cobertura = $prestaciones->first()?->visacion?->cobertura ?? $visaciones->first()?->cobertura ?? self::coberturaAbierta($paciente->id);
        if ($cobertura === null) {
            throw new AccionRechazada(self::SIN_COBERTURA);
        }
        $cobertura->loadMissing('plan.convenio.responsablePago.persona');

        // Fecha de la atención: la de la consulta, o la del turno (con su hora, si la tiene).
        $fechaHora = $consulta ? $consulta->fechaHoraTexto()
            : Fecha::mostrar($turno->fecha).($turno->tieneHorario() ? ' '.substr($turno->hora_inicio, 0, 5) : '');
        $dia = ($consulta?->fechaAtencion() ? Fecha::diaLocal($consulta->fechaAtencion()) : null) ?? $turno?->fecha->format('Y-m-d') ?? Fecha::hoy()->format('Y-m-d');

        if ($prestaciones->isNotEmpty()) {
            $filas = $prestaciones->map(fn (Prestacion $p) => ['procedimiento' => $p->procedimiento?->nombre ?? 'Sin procedimiento', 'visacion' => $p->numero_visacion])->all();
        } else {
            $filas = $visaciones->flatMap(fn (Visacion $v) => $v->lineas->where('resultado', LineaVisacion::APROBADO)
                ->map(fn (LineaVisacion $l) => ['procedimiento' => $l->procedimiento?->nombre ?? 'Sin procedimiento', 'visacion' => $v->numero]))->values()->all();
            if ($filas === []) {
                $filas = [['procedimiento' => $turno?->procedimiento?->nombre ?? 'Sin procedimiento', 'visacion' => null]];
            }
        }

        $avisos = [];
        if (! $cobertura->aplicaA($dia)) {
            $avisos[] = 'Cobertura vencida el '.Fecha::mostrar($cobertura->vigente_hasta);
        }
        if (collect($filas)->contains(fn (array $fila) => $fila['visacion'] === null)) {
            $avisos[] = 'Sin visación';
        }

        return [
            'consulta' => $consulta,
            'prestaciones' => $prestaciones,
            'visacionId' => $prestaciones->first()?->visacion_id ?? $visaciones->first()?->id,
            'numeros' => collect($filas)->pluck('visacion')->filter()->unique()->join(', '),
            'avisos' => $avisos,
            'vista' => [
                'clinica' => config('clinexa.clinica.nombre'),
                'logo' => public_path(config('clinexa.clinica.logo')),
                'profesional' => $profesional->persona->nombre_completo,
                'fechaHora' => $fechaHora,
                'paciente' => $persona->nombre_completo,
                'documento' => $persona->documentoPrincipal(),
                'aseguradora' => $cobertura->plan->convenio->responsablePago->persona->nombre_completo,
                'plan' => $cobertura->plan->nombre,
                'asegurado' => $cobertura->numero_asegurado,
                'tipoAfiliado' => $cobertura->tipoTexto(),
                'vencimiento' => $cobertura->vigente_hasta ? Fecha::mostrar($cobertura->vigente_hasta) : 'Sin vencimiento',
                'filas' => $filas,
                'avisos' => $avisos,
                'generadoEn' => Fecha::mostrar(Fecha::ahora(), conHora: true),
            ],
        ];
    }

    /** El HTML que dompdf convierte. Separado para poder verificar el contenido. */
    public static function html(array $datos, string $formato = self::A5): string
    {
        return view($formato === self::TICKET ? 'pdf-ticket-visacion.hoja-80mm' : 'pdf-ticket-visacion.hoja', $datos['vista'])->render();
    }

    /** El PDF del formato pedido (A5 o ticket de 80 mm), armado en memoria. */
    private static function pdf(string $vista, string $formato): string
    {
        $opciones = ['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'isFontSubsettingEnabled' => true];
        if ($formato !== self::TICKET) {
            return Pdf::setOption($opciones)->loadHTML($vista)->setPaper('a5', 'portrait')->output();
        }
        // 80 mm de ancho; el largo se ajusta al contenido: se arma una vez en una hoja muy alta, se mide dónde termina el cuerpo y se arma de nuevo.
        $ancho = self::ANCHO_TICKET_PT;
        $fondo = 0.0;
        $prueba = Pdf::setOption($opciones)->loadHTML($vista)->setPaper([0, 0, $ancho, self::ALTO_MAXIMO_PT]);
        $prueba->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => function ($marco) use (&$fondo) {
            if (! $marco instanceof \Dompdf\FrameDecorator\Page) {
                $fondo = max($fondo, (float) $marco->get_position('y') + (float) $marco->get_margin_height());
            }
        }]]);
        $prueba->output();

        return Pdf::setOption($opciones)->loadHTML($vista)
            ->setPaper([0, 0, $ancho, min(self::ALTO_MAXIMO_PT, max(self::ALTO_MINIMO_PT, $fondo + 2 * self::MARGEN_TICKET_PT))])->output();
    }

    public static function ejecutar(?Consulta $consulta, ?Turno $turno = null, string $formato = self::A5): Response
    {
        $formato = $formato === self::TICKET ? self::TICKET : self::A5;
        $datos = self::datos($consulta, $turno);
        $consulta = $datos['consulta'];
        $vista = self::html($datos, $formato);

        // Primero la auditoría: si falla, no sale el PDF. El PDF se arma en memoria y no se escribe en disco.
        $origen = $consulta ? "consulta {$consulta->id}" : "turno {$turno->id}";
        $detalle = "Planilla de aceptación de la {$origen}".($datos['numeros'] !== '' ? " (visación N.º {$datos['numeros']}" : ' (sin visación')
            .($datos['prestaciones']->isNotEmpty() ? '; prestaciones '.$datos['prestaciones']->pluck('id')->join(', ') : '').')'
            .($datos['avisos'] ? '. '.implode('; ', $datos['avisos']) : '');
        Auditoria::registrar(AccionAuditoria::EXPORTAR, 'visaciones', $datos['visacionId'], detalle: $detalle.($formato === self::TICKET ? ' [ticket 80 mm]' : ''));

        $contenido = self::pdf($vista, $formato);

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="aceptacion-consulta-'.($formato === self::TICKET ? '80mm-' : '').($consulta ? 'consulta-'.$consulta->id : 'turno-'.$turno->id).'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
