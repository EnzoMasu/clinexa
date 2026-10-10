<?php

namespace App\Support;

use App\Enums\AccionAuditoria;
use App\Models\BloqueAnamnesis;
use App\Models\CatalogoCIE10;
use App\Models\CategoriaGasto;
use App\Models\CategoriaProveedor;
use App\Models\Ciudad;
use App\Models\Consulta;
use App\Models\Consultorio;
use App\Models\Departamento;
use App\Models\DetalleReceta;
use App\Models\Diagnostico;
use App\Models\Disponibilidad;
use App\Models\Especialidad;
use App\Models\Estado;
use App\Models\ExamenFisico;
use App\Models\HistoriaClinica;
use App\Models\Indicacion;
use App\Models\MedioPago;
use App\Models\ModuloSistema;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Pais;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\Receta;
use App\Models\ResponsablePago;
use App\Models\Sucursal;
use App\Models\TipoBloqueAnamnesis;
use App\Models\TipoDocumento;
use App\Models\TipoIndicacion;
use App\Models\TipoRedSocial;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Escritura del log de auditoría (logs_auditoria). La usan el trait Auditable (cambios de datos),
 * el middleware de permisos (lecturas en módulos sensibles) y los eventos de autenticación.
 *
 * Cada registro es un único INSERT, en la conexión y la transacción en curso: si el cambio se
 * deshace, el registro también.
 */
final class Auditoria
{
    /**
     * Campos que nunca se guardan en el log, ni enmascarados. Si en un cambio solo se modifican
     * estos, no se registra nada (si no, cada inicio de sesión dejaría un EDITAR del usuario).
     */
    public const CAMPOS_EXCLUIDOS = ['password', 'remember_token', 'ultimo_acceso', 'created_at', 'updated_at'];

    /**
     * Modelos auditados (llevan el trait Auditable). De acá sale qué tabla corresponde a cada módulo,
     * para las lecturas y para mostrar el módulo en la pantalla de auditoría.
     */
    public const MODELOS = [
        User::class, PerfilAcceso::class, Persona::class, Paciente::class,
        Profesional::class, Proveedor::class, ResponsablePago::class,
        Especialidad::class, Sucursal::class, TipoDocumento::class,
        CatalogoCIE10::class, MedioPago::class, CategoriaGasto::class,
        CategoriaProveedor::class, Procedimiento::class, Pais::class,
        Departamento::class, Ciudad::class, Consultorio::class,
        OrigenTurno::class, Disponibilidad::class, Turno::class, TipoRedSocial::class, TipoBloqueAnamnesis::class, TipoIndicacion::class,
        // Historia clínica (módulo HISTORIA_CLINICA; la historia primero: es la tabla de sus lecturas).
        HistoriaClinica::class, Consulta::class, BloqueAnamnesis::class, ExamenFisico::class, Diagnostico::class, Indicacion::class,
        // Recetas (módulo RECETAS; la receta primero: es la tabla de sus lecturas).
        Receta::class, DetalleReceta::class,
    ];

    /**
     * Tablas con contenido clínico: en la pantalla de auditoría, sus valores solo los ve quien tiene
     * VER sobre HISTORIA_CLINICA (y el buscador no busca en ellos para los demás).
     */
    public const TABLAS_CLINICAS = ['historias_clinicas', 'consultas', 'bloques_anamnesis', 'examenes_fisicos', 'diagnosticos', 'indicaciones'];

    /** Tablas de recetas: sus valores solo los ve quien tiene VER sobre RECETAS (misma regla, otro permiso). */
    public const TABLAS_RECETAS = ['recetas', 'detalles_receta'];

    /**
     * Módulo cuyo VER hace falta para ver los valores de los eventos de esa tabla en la pantalla de
     * auditoría, o null si no son contenido protegido.
     */
    public static function permisoDeContenido(string $tabla): ?string
    {
        return match (true) {
            in_array($tabla, self::TABLAS_CLINICAS, true) => 'HISTORIA_CLINICA',
            in_array($tabla, self::TABLAS_RECETAS, true) => 'RECETAS',
            default => null,
        };
    }

    /** Minutos en los que no se repite un VER idéntico (mismo usuario, tabla y registro). */
    public const MINUTOS_SIN_REPETIR_VER = 5;

    /**
     * Tabla de cada módulo (código de modulos_sistema => tabla). Geografía tiene tres tablas: se usa la
     * de países para sus lecturas, que de todos modos no se auditan (no es un módulo sensible).
     *
     * @return array<string, string>
     */
    public static function tablasPorModulo(): array
    {
        static $tablas = null;

        return $tablas ??= collect(self::MODELOS)
            ->reverse() // el primero de la lista gana (Pais antes que Departamento y Ciudad)
            ->mapWithKeys(fn (string $modelo) => [$modelo::moduloAuditoria() => (new $modelo)->getTable()])
            ->put('AUDITORIA', 'logs_auditoria')
            ->all();
    }

    /**
     * Módulo de cada tabla auditada (tabla => código de módulo), para la pantalla de auditoría.
     *
     * @return array<string, string>
     */
    public static function modulosPorTabla(): array
    {
        static $modulos = null;

        return $modulos ??= collect(self::MODELOS)
            ->mapWithKeys(fn (string $modelo) => [(new $modelo)->getTable() => $modelo::moduloAuditoria()])
            ->put('logs_auditoria', 'AUDITORIA')
            ->all();
    }

    /**
     * Lectura de una pantalla de un módulo sensible: el listado (sin registro) o el detalle/edición
     * de un registro. No repite un VER idéntico (mismo usuario, tabla, registro y detalle) dentro de
     * MINUTOS_SIN_REPETIR_VER. "tabla": la del registro, si el módulo tiene varias (por defecto, la
     * principal del módulo); "detalle": qué se leyó, si no alcanza con la tabla y el registro.
     */
    public static function registrarLectura(string $modulo, int|string|null $registroId, ?string $tabla = null, ?string $detalle = null): void
    {
        $tabla ??= self::tablasPorModulo()[$modulo] ?? strtolower($modulo);
        $registro = $registroId === null ? null : (string) $registroId;

        $reciente = DB::table('logs_auditoria')
            ->where('usuario_id', Auth::id())
            ->where('tabla_afectada', $tabla)
            ->where('accion', AccionAuditoria::VER->value)
            ->when($registro === null, fn ($query) => $query->whereNull('registro_afectado_id'), fn ($query) => $query->where('registro_afectado_id', $registro))
            ->when($detalle === null, fn ($query) => $query->whereNull('detalle'), fn ($query) => $query->where('detalle', $detalle))
            ->where('fecha_hora', '>=', now()->subMinutes(self::MINUTOS_SIN_REPETIR_VER))
            ->exists();

        if (! $reciente) {
            self::registrar(AccionAuditoria::VER, $tabla, $registro, detalle: $detalle);
        }
    }

    /** El módulo está marcado como sensible (se cargan una vez por pedido). */
    public static function esSensible(string $modulo): bool
    {
        $contexto = app(ContextoAuditoria::class);
        $contexto->sensibles ??= ModuloSistema::where('es_sensible', true)->pluck('codigo')->flip()->map(fn () => true)->all();

        return isset($contexto->sensibles[$modulo]);
    }

    /**
     * Corre una acción con un detalle para los eventos de cambios que registre (salvo los que traen uno
     * propio, como el motivo al anular una receta), p. ej. "Borrador (autoguardado)" o "Cierre de jornada".
     *
     * @template T
     *
     * @param  \Closure(): T  $accion
     * @return T
     */
    public static function conDetalle(string $detalle, \Closure $accion): mixed
    {
        $contexto = app(ContextoAuditoria::class);
        $anterior = $contexto->detalle;
        $contexto->detalle = $detalle;
        try {
            return $accion();
        } finally {
            $contexto->detalle = $anterior;
        }
    }

    /**
     * Corre $accion juntando sus EDITAR: los de un mismo registro (y usuario) quedan en UN solo evento con
     * todas las secciones que cambiaron (el antes, el primero de cada una; el después, el último). Lo que
     * al final quedó igual que al principio no se registra; si no queda nada, no hay evento. Se registra al
     * terminar, dentro de la transacción del llamador; si $accion falla, no se registra nada. Anidado, el
     * de afuera es el que agrupa.
     */
    public static function agrupar(\Closure $accion): mixed
    {
        $contexto = app(ContextoAuditoria::class);
        if ($contexto->agrupados !== null) {
            return $accion();
        }

        $contexto->agrupados = [];
        try {
            $resultado = $accion();
            $agrupados = $contexto->agrupados;
        } finally {
            $contexto->agrupados = null;
        }

        foreach ($agrupados as $grupo) {
            [$anterior, $nuevo] = self::sinCambiosNetos($grupo['anterior'], $grupo['nuevo']);
            if ($nuevo !== [] || $anterior !== []) {
                self::registrar(AccionAuditoria::EDITAR, $grupo['tabla'], $grupo['registro'], $anterior, $nuevo, $grupo['detalle'], $grupo['usuario']);
            }
        }

        return $resultado;
    }

    /** Suma un EDITAR al grupo de su registro (ver agrupar). */
    private static function sumarAlGrupo(ContextoAuditoria $contexto, string $tabla, int|string|null $registroId, array $anterior, array $nuevo, ?string $detalle, int|false|null $usuarioId): void
    {
        $usuario = $usuarioId === false ? Auth::id() : $usuarioId;
        $clave = $tabla.'|'.$registroId.'|'.$usuario;
        $grupo = $contexto->agrupados[$clave] ?? ['tabla' => $tabla, 'registro' => $registroId, 'usuario' => $usuario, 'detalle' => $detalle, 'anterior' => [], 'nuevo' => []];

        foreach ($anterior as $campo => $valor) {
            // El primer "antes" de cada campo (o fila, en las secciones por filas) es el que vale.
            if (is_array($valor) && is_array($grupo['anterior'][$campo] ?? null) && ! array_is_list($valor)) {
                $grupo['anterior'][$campo] += $valor;
            } elseif (! array_key_exists($campo, $grupo['anterior'])) {
                $grupo['anterior'][$campo] = $valor;
            }
        }
        foreach ($nuevo as $campo => $valor) {
            if (is_array($valor) && is_array($grupo['nuevo'][$campo] ?? null) && ! array_is_list($valor)) {
                $grupo['nuevo'][$campo] = array_replace($grupo['nuevo'][$campo], $valor);
            } else {
                $grupo['nuevo'][$campo] = $valor;
            }
        }
        $contexto->agrupados[$clave] = $grupo;
    }

    /**
     * Quita lo que terminó igual que empezó (un campo, o una fila de una sección).
     *
     * @return array{0: array, 1: array}
     */
    private static function sinCambiosNetos(array $anterior, array $nuevo): array
    {
        foreach ($nuevo as $campo => $valor) {
            $antes = $anterior[$campo] ?? null;
            if (is_array($valor) && is_array($antes) && ! array_is_list($valor) && ! array_is_list($antes)) {
                foreach ($valor as $fila => $texto) {
                    if (array_key_exists($fila, $antes) && $antes[$fila] === $texto) {
                        unset($nuevo[$campo][$fila], $anterior[$campo][$fila]);
                    }
                }
                if ($nuevo[$campo] === [] && ($anterior[$campo] ?? []) === []) {
                    unset($nuevo[$campo], $anterior[$campo]);
                }
            } elseif (array_key_exists($campo, $anterior) && $antes === $valor) {
                unset($nuevo[$campo], $anterior[$campo]);
            }
        }

        return [$anterior, $nuevo];
    }

    /** Se registran cambios de datos: dentro de un pedido web y con un usuario autenticado. */
    public static function activa(): bool
    {
        return app(ContextoAuditoria::class)->pedidoWeb && Auth::check();
    }

    /** Se está atendiendo un pedido web (para los eventos de autenticación, que pueden no tener usuario). */
    public static function enPedidoWeb(): bool
    {
        return app(ContextoAuditoria::class)->pedidoWeb;
    }

    /**
     * Agrega un registro. Por defecto el usuario es el autenticado y la IP la del pedido.
     *
     * @param  array<string, mixed>|null  $anterior
     * @param  array<string, mixed>|null  $nuevo
     */
    public static function registrar(
        AccionAuditoria $accion,
        string $tabla,
        int|string|null $registroId = null,
        ?array $anterior = null,
        ?array $nuevo = null,
        ?string $detalle = null,
        int|false|null $usuarioId = false,
    ): void {
        $contexto = app(ContextoAuditoria::class);
        $detalle ??= in_array($accion, [AccionAuditoria::CREAR, AccionAuditoria::EDITAR, AccionAuditoria::ANULAR], true)
            ? $contexto->detalle : null;
        if ($accion === AccionAuditoria::EDITAR && $contexto->agrupados !== null) {
            self::sumarAlGrupo($contexto, $tabla, $registroId, $anterior ?? [], $nuevo ?? [], $detalle, $usuarioId);

            return;
        }

        DB::table('logs_auditoria')->insert([
            'usuario_id' => $usuarioId === false ? Auth::id() : $usuarioId,
            'tabla_afectada' => $tabla,
            'registro_afectado_id' => $registroId === null ? null : (string) $registroId,
            'accion' => $accion->value,
            'valor_anterior' => $anterior === null ? null : json_encode($anterior, JSON_UNESCAPED_UNICODE),
            'valor_nuevo' => $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE),
            'detalle' => $detalle,
            'ip_origen' => request()?->ip(),
            'fecha_hora' => now(),
        ]);
    }

    /**
     * Sin los campos excluidos ni ningún token.
     *
     * @param  array<string, mixed>  $atributos
     * @param  list<string>  $otrosExcluidos  campos propios del modelo que tampoco se auditan
     * @return array<string, mixed>
     */
    public static function filtrar(array $atributos, array $otrosExcluidos = []): array
    {
        return array_filter(
            $atributos,
            fn (string $campo) => ! in_array($campo, [...self::CAMPOS_EXCLUIDOS, ...$otrosExcluidos], true) && ! str_contains($campo, 'token'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Acción de una edición según el estado nuevo: pasar a INACTIVO es DESACTIVAR, a BLOQUEADO es
     * BLOQUEO, a ANULADO es ANULAR; cualquier otro cambio (también reactivar) es EDITAR.
     *
     * @param  array<string, mixed>  $cambios
     */
    public static function accionDeEdicion(array $cambios): AccionAuditoria
    {
        if (! array_key_exists('estado_id', $cambios) || $cambios['estado_id'] === null) {
            return AccionAuditoria::EDITAR;
        }

        return match ((int) $cambios['estado_id']) {
            Estado::idDe(Estado::INACTIVO) => AccionAuditoria::DESACTIVAR,
            Estado::idDe(Estado::BLOQUEADO) => AccionAuditoria::BLOQUEO,
            // Documentos (recetas): pasar a ANULADO es ANULAR.
            Estado::idDe(Estado::ANULADO) => AccionAuditoria::ANULAR,
            default => AccionAuditoria::EDITAR,
        };
    }
}
