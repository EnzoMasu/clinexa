<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccionAuditoria;
use App\Http\Controllers\Controller;
use App\Models\CatalogoCIE10;
use App\Models\Consulta;
use App\Models\LogAuditoria;
use App\Models\Receta;
use App\Support\Auditoria;
use App\Support\BuscadorPersonas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Lectura de una consulta (página y fragmento del popup) y el buscador de CIE-10. La atención (crear,
 * autoguardar, finalizar y editar) está en AtencionController y en los servicios de App\Support\Atencion.
 * Una consulta ANULADA no existe para nadie (404).
 */
class ConsultaController extends Controller
{
    /** Entradas del historial de cambios que se muestran (las más recientes); el resto, en Auditoría. */
    public const MAXIMO_HISTORIAL = 50;

    private const AUTOGUARDADO = 'Borrador (autoguardado)';

    /**
     * Una consulta deja varios autoguardados (uno por guardado efectivo, cada uno con todas las secciones
     * que cambiaron). Para leer el historial, cada racha de autoguardados seguidos del mismo usuario se ve
     * como UNA entrada: [evento (el último), desde (fecha del primero), cantidad (guardados), secciones (las
     * que cambiaron en toda la racha)]. Cualquier otro evento (Preparar, Atender, Finalizar, otro usuario...)
     * corta la racha. Los eventos con el formato anterior (uno por sección) se agrupan igual. El log no cambia.
     *
     * @param  \Illuminate\Support\Collection<int, LogAuditoria>  $eventos
     * @return \Illuminate\Support\Collection<int, array{evento: LogAuditoria, desde: mixed, cantidad: int, secciones: list<string>}>
     */
    public static function agruparHistorial(\Illuminate\Support\Collection $eventos): \Illuminate\Support\Collection
    {
        $entradas = [];
        $autorRacha = null;
        foreach ($eventos as $evento) {
            $secciones = array_keys($evento->valor_nuevo ?? []);
            $autor = [$evento->usuario_id, $evento->tabla_afectada, $evento->registro_afectado_id];
            if ($evento->detalle === self::AUTOGUARDADO && $autor === $autorRacha) {
                $entrada = array_pop($entradas);
                $entradas[] = ['evento' => $evento, 'desde' => $entrada['desde'], 'cantidad' => $entrada['cantidad'] + 1,
                    'secciones' => array_values(array_unique([...$entrada['secciones'], ...$secciones]))];

                continue;
            }
            $autorRacha = $evento->detalle === self::AUTOGUARDADO ? $autor : null;
            $entradas[] = ['evento' => $evento, 'desde' => $evento->fecha_hora, 'cantidad' => 1, 'secciones' => $secciones];
        }

        return collect($entradas);
    }

    /** Relaciones que muestra el contenido de una consulta (partial consultas._contenido). */
    private const RELACIONES_CONTENIDO = [
        'historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona', 'turno',
        // Con la autoría ("Cargado por" / "modificado por") de la anamnesis y del examen físico.
        'bloquesAnamnesis.tipoBloqueAnamnesis', 'bloquesAnamnesis.usuario.persona', 'bloquesAnamnesis.modificadoPor.persona',
        'examenFisico.signosUsuario.persona', 'examenFisico.hallazgosUsuario.persona', 'diagnosticos.cie10', 'indicaciones.tipoIndicacion',
    ];

    /**
     * Las relaciones del contenido para este usuario: las recetas solo con VER sobre RECETAS. Sin ese
     * permiso ni siquiera se consultan a la base (el partial tampoco las muestra).
     */
    private function relacionesContenido(Request $request): array
    {
        return [...self::RELACIONES_CONTENIDO, ...($request->user()->tienePermiso('RECETAS', 'VER') ? ['recetas.detalles'] : [])];
    }

    public function show(Request $request, Consulta $consulta): View
    {
        abort_if($consulta->anulada(), 404);
        $consulta->load($this->relacionesContenido($request));
        $verRecetas = $request->user()->tienePermiso('RECETAS', 'VER');
        if ($verRecetas) {
            // Para las acciones de cada receta (RecetaPolicy mira la consulta): sin una consulta SQL por receta.
            $consulta->recetas->each->setRelation('consulta', $consulta);
        }

        // Historial de cambios: solo con VER sobre AUDITORIA (además de HISTORIA_CLINICA, que pide la ruta).
        // Incluye los eventos de sus recetas solo si además tiene VER sobre RECETAS.
        $historial = null;
        if ($request->user()->tienePermiso('AUDITORIA', 'VER')) {
            $recetas = $verRecetas ? $consulta->recetas->modelKeys() : [];
            $historial = LogAuditoria::query()
                ->with('usuario.persona')
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('tabla_afectada', 'consultas')->where('registro_afectado_id', (string) $consulta->id))
                    ->when($recetas !== [], fn ($query) => $query->orWhere(fn ($query) => $query
                        ->where('tabla_afectada', 'recetas')->whereIn('registro_afectado_id', array_map('strval', $recetas)))))
                ->whereIn('accion', [AccionAuditoria::CREAR->value, AccionAuditoria::EDITAR->value, AccionAuditoria::ANULAR->value])
                ->orderBy('fecha_hora')
                ->orderBy('id')
                ->get();
            $historial = self::agruparHistorial($historial);
            // Ver el historial es leer el log de auditoría: queda registrado como un VER de AUDITORIA
            // (con qué consulta), con la misma regla anti-ruido que las demás lecturas.
            Auditoria::registrarLectura('AUDITORIA', null, detalle: "Historial de cambios de la consulta {$consulta->id}");
        }

        return view('admin.consultas.show', [
            'consulta' => $consulta,
            'historial' => $historial === null ? null : $historial->slice(-self::MAXIMO_HISTORIAL)->values(),
            'cambiosEnTotal' => $historial?->count() ?? 0,
            'verRecetas' => $verRecetas,
            'puedeCrearReceta' => $verRecetas && Gate::allows('create', [Receta::class, $consulta]),
        ]);
    }

    /**
     * Detalle de una consulta para el popup de la historia: solo el partial del contenido, de a una
     * consulta. La ruta lleva la marca "lectura-ajax": el middleware de permisos registra el VER sobre
     * consultas (con la misma regla de no repetir en 5 minutos) cuando se devuelve el contenido.
     *
     * Pegado en el navegador (sin AJAX) no se devuelve el fragmento suelto: redirige a la página
     * completa, que es la que registra esa lectura (una redirección no registra).
     */
    public function detalle(Request $request, Consulta $consulta): Response|RedirectResponse
    {
        abort_if($consulta->anulada(), 404);
        if (! $request->ajax()) {
            return redirect()->route('admin.consultas.show', $consulta);
        }

        $consulta->load($this->relacionesContenido($request));

        return response()->view('admin.consultas._contenido', [
            'consulta' => $consulta,
        ])->header('Vary', 'X-Requested-With');
    }

    /**
     * Buscador de CIE-10 del formulario: solo códigos ACTIVOS, por código o descripción, hasta 15.
     * Exige CREAR o EDITAR sobre HISTORIA_CLINICA (no permisos sobre el catálogo CIE-10).
     */
    public function cie10(Request $request): JsonResponse
    {
        abort_unless($request->user()->tienePermiso('HISTORIA_CLINICA', 'CREAR') || $request->user()->tienePermiso('HISTORIA_CLINICA', 'EDITAR'), 403,
            'No tiene permiso para acceder a esta sección.');

        $busqueda = trim((string) $request->query('q'));
        if (mb_strlen($busqueda) < BuscadorPersonas::MINIMO) {
            return response()->json([]);
        }

        return response()->json(CatalogoCIE10::activos()
            ->where(fn ($query) => $query->whereLike('codigo', "{$busqueda}%")->orWhereLike('descripcion', "%{$busqueda}%"))
            ->orderBy('codigo')
            ->limit(BuscadorPersonas::LIMITE)
            ->get(['codigo', 'descripcion'])
            ->map(fn (CatalogoCIE10 $cie10) => ['codigo' => $cie10->codigo, 'descripcion' => $cie10->descripcion]));
    }
}
