<?php

namespace App\Models\Concerns;

use App\Enums\AccionAuditoria;
use App\Support\Auditoria;
use App\Support\ContextoAuditoria;
use Illuminate\Support\Collection;

/**
 * Auditoría automática de los cambios del modelo (logs_auditoria):
 *
 * - CREAR: valor_nuevo con los atributos.
 * - EDITAR: solo lo que cambió, con su valor anterior y el nuevo. Si el estado pasa a INACTIVO se
 *   registra DESACTIVAR, si pasa a BLOQUEADO, BLOQUEO (reactivar es un EDITAR).
 * - Nunca guarda contraseñas, tokens, ultimo_acceso ni las fechas de creación y modificación (ver
 *   Auditoria::CAMPOS_EXCLUIDOS); si solo cambian esos, no registra nada.
 * - Solo dentro de un pedido web con usuario autenticado (Auditoria::activa).
 * - Cada guardado auditado va en su propia transacción (o en la del llamador): el cambio y su
 *   registro se confirman o se deshacen juntos.
 *
 * Los modelos con tablas pivote importantes las sincronizan con sincronizarAuditado(), que las
 * registra como EDITAR del modelo dueño, con la lista de antes y la de después. Los modelos hijos
 * que se guardan dentro de auditarRelacion() no registran por su cuenta (quedan en el dueño).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $modelo) {
            if (Auditoria::activa() && ! self::dentroDeRelacion()) {
                Auditoria::registrar(AccionAuditoria::CREAR, $modelo->getTable(), $modelo->getKey(),
                    nuevo: Auditoria::filtrar($modelo->getAttributes(), $modelo->camposNoAuditados()));
            }
        });

        static::updated(function (self $modelo) {
            if (! Auditoria::activa() || self::dentroDeRelacion()) {
                return;
            }

            $cambios = Auditoria::filtrar($modelo->getChanges(), $modelo->camposNoAuditados());
            if ($cambios === []) {
                return;
            }

            $anterior = array_intersect_key($modelo->getRawOriginal(), $cambios);
            $accion = Auditoria::accionDeEdicion($cambios);
            Auditoria::registrar($accion, $modelo->getTable(), $modelo->getKey(), $anterior, $cambios, $modelo->detalleAuditoria($accion));
        });
    }

    /**
     * Módulo al que pertenece la tabla en la auditoría (el de sus estados). Los modelos sin estados
     * propios (los de la historia clínica) lo indican aparte.
     */
    public static function moduloAuditoria(): string
    {
        return static::moduloEstado();
    }

    private static function dentroDeRelacion(): bool
    {
        return app(ContextoAuditoria::class)->dentroDeRelacion > 0;
    }

    /** Guardado y registro de auditoría en la misma transacción. */
    public function save(array $options = [])
    {
        if (! Auditoria::activa()) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    /** Texto del campo "detalle" del evento (p. ej. el motivo al anular una receta), o null. */
    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return null;
    }

    /** Campos propios del modelo que no se auditan (además de los excluidos para todos). */
    public function camposNoAuditados(): array
    {
        return [];
    }

    /**
     * De dos mapas identificación => texto, solo las filas distintas: [antes, después] (null donde la fila
     * no existía).
     *
     * @return array{0: array<string, ?string>, 1: array<string, ?string>}
     */
    private static function filasCambiadas(array $antes, array $despues): array
    {
        $anterior = [];
        $nuevo = [];
        foreach (array_unique([...array_keys($antes), ...array_keys($despues)]) as $fila) {
            if (($antes[$fila] ?? null) !== ($despues[$fila] ?? null)) {
                $anterior[$fila] = $antes[$fila] ?? null;
                $nuevo[$fila] = $despues[$fila] ?? null;
            }
        }

        return [$anterior, $nuevo];
    }

    /**
     * Cómo se muestra cada relación pivote en el log (lista legible, ordenada), por nombre de relación.
     *
     * @return array<string, \Closure(Collection): array>
     */
    public function relacionesAuditadas(): array
    {
        return [];
    }

    /**
     * true: las relaciones auditadas se describen como mapa "identificación legible" => texto (p. ej.
     * "Bloque #12" => "1. Alergias: Penicilina.") y en el log quedan solo las filas que cambiaron: en el
     * antes su texto anterior (null si es nueva) y en el después el nuevo. Aplicando los "después" en orden
     * se reconstruye el estado en cada momento. false (por defecto): la lista entera de antes y de después.
     */
    public function auditaFilasCambiadas(): bool
    {
        return false;
    }

    /**
     * sync() de una relación pivote, registrando el cambio como EDITAR de este modelo si la lista
     * quedó distinta. Sin auditoría activa es un sync() común.
     */
    public function sincronizarAuditado(string $relacion, array $ids): array
    {
        return $this->auditarRelacion($relacion, fn () => $this->{$relacion}()->sync($ids));
    }

    /**
     * Aplica un cambio a una relación pivote (sync, o un update directo de la tabla pivote) y, si la
     * lista quedó distinta, lo registra como EDITAR de este modelo con la lista de antes y la de
     * después. Sin auditoría activa solo aplica el cambio.
     */
    public function auditarRelacion(string $relacion, \Closure $cambio): mixed
    {
        if (! Auditoria::activa()) {
            return $cambio();
        }

        $describir = $this->relacionesAuditadas()[$relacion] ?? fn (Collection $items) => $items->modelKeys();

        return $this->getConnection()->transaction(function () use ($relacion, $cambio, $describir) {
            $antes = $describir($this->{$relacion}()->get());
            $contexto = app(ContextoAuditoria::class);
            $contexto->dentroDeRelacion++;
            try {
                $resultado = $cambio();
            } finally {
                $contexto->dentroDeRelacion--;
            }
            $despues = $describir($this->{$relacion}()->get());

            if ($antes !== $despues) {
                [$antes, $despues] = $this->auditaFilasCambiadas() ? self::filasCambiadas($antes, $despues) : [$antes, $despues];
                Auditoria::registrar(AccionAuditoria::EDITAR, $this->getTable(), $this->getKey(), [$relacion => $antes], [$relacion => $despues]);
            }

            return $resultado;
        });
    }
}
