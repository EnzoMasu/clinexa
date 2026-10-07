<?php

namespace App\Models\Concerns;

use App\Enums\AccionAuditoria;
use App\Support\Auditoria;
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
 * registra como EDITAR del modelo dueño, con la lista de antes y la de después.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $modelo) {
            if (Auditoria::activa()) {
                Auditoria::registrar(AccionAuditoria::CREAR, $modelo->getTable(), $modelo->getKey(),
                    nuevo: Auditoria::filtrar($modelo->getAttributes(), $modelo->camposNoAuditados()));
            }
        });

        static::updated(function (self $modelo) {
            if (! Auditoria::activa()) {
                return;
            }

            $cambios = Auditoria::filtrar($modelo->getChanges(), $modelo->camposNoAuditados());
            if ($cambios === []) {
                return;
            }

            $anterior = array_intersect_key($modelo->getRawOriginal(), $cambios);
            Auditoria::registrar(Auditoria::accionDeEdicion($cambios), $modelo->getTable(), $modelo->getKey(), $anterior, $cambios);
        });
    }

    /** Guardado y registro de auditoría en la misma transacción. */
    public function save(array $options = [])
    {
        if (! Auditoria::activa()) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    /** Campos propios del modelo que no se auditan (además de los excluidos para todos). */
    public function camposNoAuditados(): array
    {
        return [];
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
            $resultado = $cambio();
            $despues = $describir($this->{$relacion}()->get());

            if ($antes !== $despues) {
                Auditoria::registrar(AccionAuditoria::EDITAR, $this->getTable(), $this->getKey(), [$relacion => $antes], [$relacion => $despues]);
            }

            return $resultado;
        });
    }
}
