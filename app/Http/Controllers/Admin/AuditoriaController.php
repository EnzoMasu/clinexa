<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccionAuditoria;
use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\ModuloSistema;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Consulta del log de auditoría (solo lectura: no hay alta, edición ni baja). Las lecturas de esta
 * pantalla también quedan en el log (AUDITORIA es un módulo sensible).
 */
class AuditoriaController extends Controller
{
    /** Valor del filtro de usuario para los eventos sin usuario (intento con un correo inexistente). */
    public const SIN_USUARIO = 'ninguno';

    public function index(Request $request): Response
    {
        $filtros = $this->filtros($request);

        $logs = LogAuditoria::query()
            ->with('usuario.persona')
            ->when($filtros['usuario'] === self::SIN_USUARIO, fn ($query) => $query->whereNull('usuario_id'))
            ->when(ctype_digit((string) $filtros['usuario']), fn ($query) => $query->where('usuario_id', (int) $filtros['usuario']))
            ->when($filtros['accion'], fn ($query, $accion) => $query->where('accion', $accion))
            ->when($filtros['modulo'], fn ($query, $modulo) => $query->whereIn('tabla_afectada', array_keys(Auditoria::modulosPorTabla(), $modulo, true)))
            ->when($filtros['desdeUtc'], fn ($query, $desde) => $query->where('fecha_hora', '>=', $desde))
            ->when($filtros['hastaUtc'], fn ($query, $hasta) => $query->where('fecha_hora', '<', $hasta))
            ->when($filtros['q'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('registro_afectado_id', $filtros['q'])
                ->orWhereLike('detalle', "%{$filtros['q']}%")))
            ->orderByDesc('fecha_hora')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.auditoria', [
            'logs' => $logs,
            'busqueda' => $filtros['q'],
            'filtros' => $filtros,
            'modulos' => $this->nombresDeModulos(),
            // Las opciones de los filtros solo hacen falta al dibujar la página entera (no en la búsqueda en vivo).
            ...($request->ajax() ? [] : $this->opcionesDeFiltro()),
        ]);
    }

    public function show(LogAuditoria $log): View
    {
        $log->load('usuario.persona');

        return view('admin.auditoria.show', [
            'log' => $log,
            'modulos' => $this->nombresDeModulos(),
            'cambios' => $this->cambios($log),
        ]);
    }

    /**
     * Campo por campo: valor anterior y nuevo (en una edición solo están los que cambiaron). Los
     * estados se muestran con su código además del id.
     *
     * @return list<array{campo: string, anterior: mixed, nuevo: mixed}>
     */
    private function cambios(LogAuditoria $log): array
    {
        $anterior = $log->valor_anterior ?? [];
        $nuevo = $log->valor_nuevo ?? [];
        $estados = Estado::pluck('codigo', 'id');
        $legible = fn (string $campo, mixed $valor) => $campo === 'estado_id' && isset($estados[$valor]) ? "{$estados[$valor]} ({$valor})" : $valor;

        return collect(array_unique([...array_keys($anterior), ...array_keys($nuevo)]))
            ->map(fn (string $campo) => [
                'campo' => $campo,
                'anterior' => array_key_exists($campo, $anterior) ? $legible($campo, $anterior[$campo]) : null,
                'nuevo' => array_key_exists($campo, $nuevo) ? $legible($campo, $nuevo[$campo]) : null,
            ])
            ->values()->all();
    }

    /**
     * Filtros de la URL. Las fechas (dd/mm/aaaa) son días de Paraguay: se pasan al rango UTC en que se
     * guarda fecha_hora. Una fecha mal escrita se ignora.
     */
    private function filtros(Request $request): array
    {
        $dia = function (?string $texto) {
            $texto = trim((string) $texto);
            if (! preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) || ! Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO)) {
                return null;
            }

            return Carbon::createFromFormat('!'.Fecha::FORMATO, $texto, config('app.zona_horaria_local'));
        };

        $desde = $dia($request->query('desde'));
        $hasta = $dia($request->query('hasta'));
        $accion = AccionAuditoria::tryFrom((string) $request->query('accion'))?->value;
        $modulo = array_key_exists((string) $request->query('modulo'), $this->nombresDeModulos()) ? (string) $request->query('modulo') : null;

        return [
            'q' => trim((string) $request->query('q')),
            'usuario' => (string) $request->query('usuario', ''),
            'accion' => $accion,
            'modulo' => $modulo,
            'desde' => $desde ? $desde->format(Fecha::FORMATO) : '',
            'hasta' => $hasta ? $hasta->format(Fecha::FORMATO) : '',
            'desdeUtc' => $desde?->copy()->startOfDay()->utc(),
            'hastaUtc' => $hasta?->copy()->addDay()->startOfDay()->utc(), // hasta inclusive
        ];
    }

    /** Opciones de los filtros de usuario y acción. */
    private function opcionesDeFiltro(): array
    {
        return [
            'usuarios' => [
                self::SIN_USUARIO => '(Sin usuario: correo inexistente)',
                ...User::with('persona')->get()->sortBy(fn (User $usuario) => $usuario->name)
                    ->mapWithKeys(fn (User $usuario) => [$usuario->id => $usuario->name])->all(),
            ],
            'acciones' => collect(AccionAuditoria::cases())->mapWithKeys(fn (AccionAuditoria $accion) => [$accion->value => $accion->etiqueta()])->all(),
        ];
    }

    /**
     * Nombre de cada módulo que aparece en el log (código => nombre), una consulta por pedido.
     *
     * @return array<string, string>
     */
    private function nombresDeModulos(): array
    {
        return $this->nombresDeModulos ??= ModuloSistema::whereIn('codigo', array_unique(Auditoria::modulosPorTabla()))
            ->orderBy('nombre')->pluck('nombre', 'codigo')->all();
    }

    /** @var array<string, string>|null */
    private ?array $nombresDeModulos = null;
}
