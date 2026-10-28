<?php

namespace Database\Seeders\Demo;

use App\Models\Liquidacion;
use App\Models\MedioPago;
use App\Models\ResponsablePago;
use App\Models\TipoDocumento;
use App\Support\Fecha;
use App\Support\Liquidaciones\ArmarLiquidacion;
use App\Support\Liquidaciones\FlujoLiquidacion;
use App\Support\Liquidaciones\PagosAseguradora;

/**
 * Liquidaciones demo (docs/liquidaciones-aseguradoras.md), con corte hoy, sobre las prestaciones de seguro ya firmadas de los días
 * simulados: la de la primera aseguradora armada y enviada por Facturación, aprobada por Gerencia y con un pago parcial (Facturación,
 * por transferencia); la de la segunda, armada y en preparación. Todo con los servicios de la aplicación.
 *
 * Idempotente y sobre una base ya cargada: una aseguradora que ya tiene alguna liquidación no recibe otra. Sin prestaciones firmadas
 * (días simulados sin cargar), no hace nada.
 */
class LiquidacionesDemo extends EtapaDemo
{
    public function run(): void
    {
        $ruc = TipoDocumento::where('codigo', 'RUC')->value('id');
        [$primera, $segunda] = array_map(fn (string $base) => ResponsablePago::whereHas('persona', fn ($query) => $query
            ->where('tipo_documento_id', $ruc)->where('nro_documento', Ruc::conDigito($base)))->first(), array_keys(SegurosDemo::ASEGURADORAS));

        if ($primera && ($liquidacion = $this->armar($primera))) {
            $this->como($this->correo('facturacion'), fn () => FlujoLiquidacion::enviar($this->usuario('facturacion'), $liquidacion));
            $this->como($this->correo('gerencia'), fn () => FlujoLiquidacion::aprobar($this->usuario('gerencia'), $liquidacion));
            $this->contar('liquidaciones aprobadas');
            // Un pago parcial: la mitad, redondeada al millar.
            $this->como($this->correo('facturacion'), fn () => PagosAseguradora::registrar($this->usuario('facturacion'), $liquidacion->fresh(),
                intdiv($liquidacion->totalEntero(), 2000) * 1000, Fecha::hoy(), MedioPago::where('codigo', 'TRANSFERENCIA')->value('id'), 'TR-demo-0001'));
            $this->contar('pagos de aseguradoras');
        }
        if ($segunda && $this->armar($segunda)) {
            $this->contar('liquidaciones en preparación');
        }
    }

    /** Facturación arma la de esa aseguradora con corte hoy, si todavía no tiene ninguna y hay prestaciones firmadas. */
    private function armar(ResponsablePago $responsable): ?Liquidacion
    {
        if (Liquidacion::where('responsable_pago_id', $responsable->id)->exists()) {
            return null;
        }
        if (! ArmarLiquidacion::candidatas($responsable->id, Fecha::hoy())->exists()) {
            $this->aviso("Liquidaciones: {$responsable->persona->nombre_completo} no tiene prestaciones firmadas para liquidar.");

            return null;
        }

        return $this->como($this->correo('facturacion'), fn () => ArmarLiquidacion::ejecutar($this->usuario('facturacion'), $responsable, Fecha::hoy()));
    }
}
