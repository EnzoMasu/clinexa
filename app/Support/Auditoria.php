<?php

namespace App\Support;

use App\Enums\AccionAuditoria;
use App\Models\CatalogoCIE10;
use App\Models\CategoriaGasto;
use App\Models\CategoriaProveedor;
use App\Models\Ciudad;
use App\Models\Consultorio;
use App\Models\Departamento;
use App\Models\Disponibilidad;
use App\Models\Especialidad;
use App\Models\Estado;
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
use App\Models\ResponsablePago;
use App\Models\Sucursal;
use App\Models\TipoDocumento;
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
        OrigenTurno::class, Disponibilidad::class, Turno::class,
    ];

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
            ->mapWithKeys(fn (string $modelo) => [$modelo::moduloEstado() => (new $modelo)->getTable()])
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
            ->mapWithKeys(fn (string $modelo) => [(new $modelo)->getTable() => $modelo::moduloEstado()])
            ->put('logs_auditoria', 'AUDITORIA')
            ->all();
    }

    /**
     * Lectura de una pantalla de un módulo sensible: el listado (sin registro) o el detalle/edición
     * de un registro. No repite un VER idéntico dentro de MINUTOS_SIN_REPETIR_VER.
     */
    public static function registrarLectura(string $modulo, int|string|null $registroId): void
    {
        $tabla = self::tablasPorModulo()[$modulo] ?? strtolower($modulo);
        $registro = $registroId === null ? null : (string) $registroId;

        $reciente = DB::table('logs_auditoria')
            ->where('usuario_id', Auth::id())
            ->where('tabla_afectada', $tabla)
            ->where('accion', AccionAuditoria::VER->value)
            ->when($registro === null, fn ($query) => $query->whereNull('registro_afectado_id'), fn ($query) => $query->where('registro_afectado_id', $registro))
            ->where('fecha_hora', '>=', now()->subMinutes(self::MINUTOS_SIN_REPETIR_VER))
            ->exists();

        if (! $reciente) {
            self::registrar(AccionAuditoria::VER, $tabla, $registro);
        }
    }

    /** El módulo está marcado como sensible (se cargan una vez por pedido). */
    public static function esSensible(string $modulo): bool
    {
        $contexto = app(ContextoAuditoria::class);
        $contexto->sensibles ??= ModuloSistema::where('es_sensible', true)->pluck('codigo')->flip()->map(fn () => true)->all();

        return isset($contexto->sensibles[$modulo]);
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
     * BLOQUEO; cualquier otro cambio (también reactivar) es EDITAR.
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
            default => AccionAuditoria::EDITAR,
        };
    }
}
