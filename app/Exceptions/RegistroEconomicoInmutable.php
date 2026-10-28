<?php

namespace App\Exceptions;

use LogicException;

/**
 * Intento de editar o borrar una tarifa, una prestación, un acuerdo, un reparto, una caja o un cobro fuera de lo permitido
 * (docs/tarifas-prestaciones.md, docs/reparto-economico.md, docs/cobros-caja.md). Es un error de
 * programación: las pantallas nunca lo ofrecen.
 */
class RegistroEconomicoInmutable extends LogicException
{
    public static function tarifa(): self
    {
        return new self('Una tarifa no se edita: para cambiar el monto, se cierra la vigente y se abre otra.');
    }

    public static function prestacion(): self
    {
        return new self('Una prestación solo se tarifa (una vez) o se anula; el resto no cambia.');
    }

    public static function acuerdo(): self
    {
        return new self('Un acuerdo de reparto no se edita: para cambiar el porcentaje, se cierra el vigente y se abre otro.');
    }

    public static function reparto(): self
    {
        return new self('Un reparto solo se completa (una vez) o se anula; sus líneas no cambian.');
    }

    public static function caja(): self
    {
        return new self('Una caja solo se cierra, se aprueba o se devuelve; sus movimientos y cierres no cambian.');
    }

    public static function cobro(): self
    {
        return new self('Un cobro no se edita: sus prestaciones, líneas y movimientos quedan como se registraron.');
    }

    public static function convenio(): self
    {
        return new self('Un convenio, su tarifa, una cobertura o una visación no se editan: se cierran (o se anulan) y se carga otra.');
    }

    public static function liquidacion(): self
    {
        return new self('Una liquidación solo avanza por su flujo (enviar, aprobar o devolver, pagar, anular); sus líneas y pagos solo se quitan o se anulan.');
    }

    public static function factura(): self
    {
        return new self('Una factura no se edita: solo se cobra (al emitirse) o se anula, y su número se conserva; un timbrado solo pasa a histórico o avanza su numeración.');
    }

    public static function borrado(): self
    {
        return new self('Las tarifas, las prestaciones, los acuerdos, los repartos, las cajas, los cobros, los convenios, las visaciones, las liquidaciones, las facturas y los timbrados no se borran.');
    }
}
