<?php

namespace Database\Seeders\Demo;

use App\Models\AcuerdoReparto;
use App\Models\CategoriaGasto;
use App\Models\CategoriaProveedor;
use App\Models\Equipo;
use App\Models\LiquidacionBeneficiario;
use App\Models\MedioPago;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Proveedor;
use App\Models\Tarifa;
use App\Models\TipoEquipo;
use App\Support\Fecha;
use App\Support\LiquidacionesBeneficiarios\ArmarLiquidacionBeneficiario;
use App\Support\LiquidacionesBeneficiarios\FlujoLiquidacionBeneficiario;
use App\Support\LiquidacionesBeneficiarios\PagosBeneficiario;
use App\Support\Prestaciones\TarifarPrestacion;
use App\Support\Repartos\AltaAcuerdo;
use App\Support\Repartos\Porcentaje;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Ruc;
use App\Support\Tarifas\AltaTarifa;

/**
 * Liquidación a beneficiarios demo (docs/liquidacion-beneficiarios.md), después de los días simulados y de las liquidaciones a
 * aseguradoras, con los servicios de la aplicación:
 *
 * - El Dr. Duarte pasa a FUNCIONARIO: acuerdo general de 0 % desde el inicio de las vigencias (Gerencia) y se reparten sus prestaciones
 *   pendientes, salvo la más reciente (queda "pendiente de reparto" para probar "Repartir" en el navegador).
 * - Un dueño de equipo EXTERNO (persona jurídica "Equipos Ejemplo S.A.", RUC 80900003, proveedor de "Equipos médicos", sin usuario: el
 *   dueño externo no entra al sistema) con el "Colposcopio Ejemplo (demo)" para PAP-COLPO al 25 %. PAP-COLPO recibe tarifa y se tarifa
 *   UNA de sus prestaciones pendientes (las demás siguen pendientes de tarifar): queda repartida entre la profesional, el dueño externo y la
 *   clínica, sin cobrar (en "Pendientes de liquidar").
 * - La Dra. Benítez como profesional: liquidación armada (Facturación), enviada, aprobada (Gerencia) y con un pago parcial de Tesorería
 *   por transferencia. Como dueña del ecógrafo: otra liquidación, en preparación.
 *
 * Idempotente: no repite acuerdos, equipos, tarifas ni liquidaciones (un beneficiario que ya tiene alguna no recibe otra).
 */
class BeneficiariosDemo extends EtapaDemo
{
    public const DUENIO_RUC = '80900003';

    public const EQUIPO = 'Colposcopio Ejemplo (demo)';

    public const TARIFA_PAP = 180000;

    public function run(): void
    {
        $desde = self::inicioVigencias();
        $gerencia = $this->usuario('gerencia');

        // El Dr. Duarte, funcionario: 0 % general (su parte queda en la clínica).
        $duarte = $this->profesional('medico2');
        $this->como($this->correo('gerencia'), function () use ($duarte, $desde, $gerencia) {
            if (! AcuerdoReparto::where('tipo', AcuerdoReparto::PROFESIONAL)->where('profesional_id', $duarte->id)->exists()) {
                AltaAcuerdo::ejecutar($gerencia, AcuerdoReparto::PROFESIONAL, $duarte->id, null, null, Porcentaje::desdeTexto('0'), $desde);
                $this->contar('acuerdos de funcionarios (0 %)');
                $pendientes = RepartirPrestacion::pendientes()->where('prestaciones.profesional_id', $duarte->id)->orderBy('prestaciones.id')->get();
                foreach ($pendientes->slice(0, max(0, $pendientes->count() - 1)) as $prestacion) {
                    RepartirPrestacion::repartir($gerencia, $prestacion);
                    $this->contar('prestaciones de funcionario repartidas');
                }
            }
        });

        // El dueño de equipo externo y su colposcopio para PAP-COLPO.
        $pap = Procedimiento::where('codigo', 'PAP-COLPO')->first();
        if ($pap) {
            $proveedor = $this->como($this->correo('compras'), function () {
                $ruc = Ruc::conDigito(self::DUENIO_RUC);
                $persona = Persona::where('ruc', $ruc)->first() ?? Persona::create([
                    'ruc' => $ruc, 'tipo_persona' => 'JURIDICA', 'razon_social' => 'Equipos Ejemplo S.A. (demo)', 'nombre_fantasia' => 'Equipos Ejemplo',
                    'representante_legal' => 'Representante de prueba (demo)', 'email' => $this->correo('equipos'), 'telefono' => '021 900 003',
                    'direccion' => 'Avenida Ejemplo 2000, depósito demo',
                ]);
                $proveedor = Proveedor::firstOrCreate(['persona_id' => $persona->id]);
                if ($proveedor->wasRecentlyCreated) {
                    $this->contar('dueños de equipo externos');
                    if ($categoria = CategoriaProveedor::where('nombre', 'Equipos médicos')->first()) {
                        $proveedor->sincronizarAuditado('categorias', [$categoria->id]);
                    }
                }

                return $proveedor;
            });
            $this->como($this->correo('gerencia'), function () use ($pap, $proveedor, $desde, $gerencia) {
                $tipo = TipoEquipo::firstOrCreate(['nombre' => 'Colposcopio']);
                $equipo = Equipo::firstOrCreate(['nombre' => self::EQUIPO], ['tipo_equipo_id' => $tipo->id, 'proveedor_id' => $proveedor->id]);
                if ($equipo->wasRecentlyCreated) {
                    $equipo->procedimientos()->sync([$pap->id]);
                    AltaAcuerdo::ejecutar($gerencia, AcuerdoReparto::EQUIPO, null, null, $equipo->id, Porcentaje::desdeTexto('25'), $desde);
                    $this->contar('equipos de dueños externos');
                }
                if (! Tarifa::where('procedimiento_id', $pap->id)->exists()) {
                    AltaTarifa::ejecutar($gerencia, $pap->id, null, self::TARIFA_PAP, $desde);
                    $this->contar('tarifas');
                    $pendiente = Prestacion::where('procedimiento_id', $pap->id)->get()->first(fn (Prestacion $p) => $p->pendiente() && ! $p->anulada());
                    if ($pendiente) {
                        TarifarPrestacion::ejecutar($gerencia, $pendiente);
                        $this->contar('prestaciones con el equipo externo');
                    }
                }
            });
        }

        // La Dra. Benítez: como profesional (aprobada y con un pago parcial) y como dueña del ecógrafo (en preparación).
        $benitez = $this->profesional('medica1');
        if ($liquidacion = $this->armar(LiquidacionBeneficiario::PROFESIONAL, $benitez->id)) {
            $this->como($this->correo('facturacion'), fn () => FlujoLiquidacionBeneficiario::enviar($this->usuario('facturacion'), $liquidacion));
            $this->como($this->correo('gerencia'), fn () => FlujoLiquidacionBeneficiario::aprobar($gerencia, $liquidacion->fresh()));
            $this->contar('liquidaciones a beneficiarios aprobadas');
            $liquidacion = $liquidacion->fresh();
            if ($liquidacion->saldo() >= 2000) {
                $this->como($this->correo('compras'), fn () => PagosBeneficiario::registrar($this->usuario('compras'), $liquidacion, [
                    'monto' => intdiv($liquidacion->saldo(), 2000) * 1000, 'fecha' => Fecha::hoy(),
                    'medio_pago_id' => MedioPago::where('codigo', 'TRANSFERENCIA')->value('id'), 'referencia' => 'TR-demo-0101',
                ]));
                $this->contar('pagos a beneficiarios');
            }
        }
        $duenia = Proveedor::where('persona_id', $benitez->persona_id)->first();
        if ($duenia && $this->armar(LiquidacionBeneficiario::EQUIPO, $duenia->id)) {
            $this->contar('liquidaciones a beneficiarios en preparación');
        }

        // Una categoría de gasto para los pagos en efectivo desde la caja de Tesorería (si la clínica todavía no cargó ninguna).
        if (! CategoriaGasto::exists()) {
            $this->como($this->correo('compras'), fn () => CategoriaGasto::create(['nombre' => 'Honorarios profesionales (demo)']));
            $this->contar('categorías de gasto');
        }
    }

    /** Facturación arma la de ese beneficiario con corte hoy, si todavía no tiene ninguna y hay algo para liquidar. */
    private function armar(string $tipo, int $id): ?LiquidacionBeneficiario
    {
        if (LiquidacionBeneficiario::query()->delBeneficiario($tipo, $id)->exists()) {
            return null;
        }
        if (ArmarLiquidacionBeneficiario::vistaPrevia($tipo, $id, Fecha::hoy())['cantidad'] === 0) {
            $this->aviso("Liquidación a beneficiarios: nada percibido para liquidar ({$tipo} {$id}).");

            return null;
        }

        return $this->como($this->correo('facturacion'), fn () => ArmarLiquidacionBeneficiario::ejecutar($this->usuario('facturacion'), $tipo, $id, Fecha::hoy()));
    }
}
