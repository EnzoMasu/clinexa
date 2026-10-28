<?php

namespace Database\Seeders\Demo;

use App\Models\Clinica;
use App\Models\Persona;
use App\Models\Sucursal;
use App\Models\Timbrado;
use App\Support\Facturacion\GestionTimbrados;
use App\Support\Ruc;
use RuntimeException;

/**
 * Facturación demo (docs/facturacion.md): los datos fiscales de una clínica de PRUEBA (solo si no hay ninguna cargada) y un timbrado
 * SIMULADO vigente desde hace dos meses para el punto 001-001 de la sucursal principal (solo si ese punto no tiene uno vigente). Los
 * carga Gerencia. Los cobros de los días simulados salen con su factura; en una base ya cargada, los cobros existentes no se facturan
 * (son anteriores al hito FACTURACION). Idempotente.
 */
class FacturacionDemo extends EtapaDemo
{
    /** Base del RUC de la clínica de prueba (el dígito lo calcula Ruc): no es el de ninguna persona demo. */
    public const RUC_CLINICA = '80900010';

    public const NUMERO_TIMBRADO = '19990001';

    public function run(): void
    {
        $gerencia = $this->usuario('gerencia');
        $sucursal = Sucursal::principal() ?? throw new RuntimeException('Falta la sucursal: correr antes BaseClinica.');

        $clinica = Clinica::actual();
        if ($clinica === null) {
            $ruc = Ruc::conDigito(self::RUC_CLINICA);
            if (Persona::where('ruc', $ruc)->exists()) {
                throw new RuntimeException("El RUC de prueba {$ruc} ya es de una persona: cambie FacturacionDemo::RUC_CLINICA.");
            }
            $clinica = $this->como($gerencia->email, fn () => Clinica::create([
                'tipo_contribuyente' => Clinica::SOCIEDAD, 'razon_social' => 'Clínica de Prueba (demo) S.A.',
                'nombre_fantasia' => (string) config('clinexa.clinica.nombre'), 'ruc' => $ruc,
                'actividad_economica' => 'Servicios de atención médica (demo)', 'direccion_fiscal' => $sucursal->direccion,
                'ciudad_id' => $sucursal->ciudad_id, 'telefono' => $sucursal->telefono, 'correo' => 'clinica@'.self::DOMINIO,
            ]));
            $this->contar('clínica de prueba');
        }
        foreach (Sucursal::whereNull('clinica_id')->get() as $sinClinica) {
            $this->como($gerencia->email, fn () => $sinClinica->update(['clinica_id' => $clinica->id]));
            $this->contar('sucursales asignadas a la clínica');
        }

        $vigente = Timbrado::query()->vigentes()->where('sucursal_id', $sucursal->id)->where('establecimiento', '001')->where('punto_expedicion', '001')->exists();
        if (! $vigente) {
            $desde = self::inicioVigencias();
            $this->como($gerencia->email, fn () => GestionTimbrados::cargar($gerencia, [
                'sucursal_id' => $sucursal->id, 'numero' => self::NUMERO_TIMBRADO, 'vigente_desde' => $desde->format('Y-m-d'),
                'vigente_hasta' => $desde->copy()->addYear()->subDay()->format('Y-m-d'), 'establecimiento' => '001', 'punto_expedicion' => '001',
                'numero_inicial' => 1, 'numero_final' => 9999999,
            ]));
            $this->contar('timbrados simulados');
        }
    }
}
