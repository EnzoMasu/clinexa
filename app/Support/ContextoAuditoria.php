<?php

namespace App\Support;

/**
 * Estado de la auditoría durante un pedido (registrado como "scoped": uno por pedido).
 *
 * - pedidoWeb: lo enciende el middleware ContextoAuditoriaWeb mientras atiende un pedido web. Fuera
 *   de él (seeders, migraciones, comandos de consola, código de los tests) no se registra nada.
 * - motivoCierre: si la sesión la cierra el sistema (cuenta bloqueada o desactivada mientras
 *   estaba logueada), el motivo para el registro de CIERRE_SESION.
 */
class ContextoAuditoria
{
    public bool $pedidoWeb = false;

    public ?string $motivoCierre = null;

    /**
     * Mayor que cero mientras Auditable::auditarRelacion aplica un cambio: los modelos hijos que se
     * guardan ahí (bloques de anamnesis de una consulta, ...) no registran por su cuenta; el cambio
     * queda como EDITAR del dueño, con la lista de antes y la de después.
     */
    public int $dentroDeRelacion = 0;

    /** @var array<string, bool>|null códigos de los módulos sensibles, cargados una vez por pedido */
    public ?array $sensibles = null;
}
