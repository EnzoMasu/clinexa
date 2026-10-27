<?php

namespace Database\Seeders\Demo;

use App\Models\Ciudad;
use App\Models\CoberturaPaciente;
use App\Models\Convenio;
use App\Models\Persona;
use App\Models\PlanConvenio;
use App\Models\Procedimiento;
use App\Models\ResponsablePago;
use App\Models\TarifaConvenio;
use App\Models\TipoDocumento;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Convenios\GestionConvenios;
use RuntimeException;

/**
 * Seguros demo (etapa 2, parte B): dos aseguradoras inventadas (personas jurídicas con RUC válido) como responsables de pago,
 * cada una con su convenio, dos planes y tarifas del convenio para Consulta y ECO-TV, y la cobertura de las 4 primeras
 * pacientes demo (la cuarta, vencida). Las visaciones se cargan en los días simulados (DiasDemo), desde el turno.
 *
 * Con servicio: convenios, planes y tarifas (GestionConvenios, Facturación) y coberturas (GestionCoberturas, Recepción). Sin
 * servicio (con los modelos y su auditoría, como el panel): la persona jurídica y su rol de responsable de pago (Recepción).
 * Idempotente: la persona por su RUC, el convenio por su nombre, la tarifa por convenio y procedimiento, y la cobertura por
 * paciente (una paciente que ya tiene alguna no recibe otra).
 */
class SegurosDemo extends EtapaDemo
{
    /** base del RUC => [razón social, nombre de fantasía, correo, teléfono, convenio, planes, [código => monto]] */
    public const ASEGURADORAS = [
        '80900001' => ['Seguros Ejemplo Uno S.A.', 'Ejemplo Uno Seguros', 'aseguradora1', '021 900 001', 'Convenio Ejemplo Uno', ['Plan Oro', 'Plan Plata'],
            ['CONS-001' => 200000, 'ECO-TV' => 300000]],
        '80900002' => ['Previsión Médica Dos S.A.', 'Previsión Dos', 'aseguradora2', '021 900 002', 'Convenio Previsión Dos', ['Plan Integral', 'Plan Básico'],
            ['CONS-001' => 220000, 'ECO-TV' => 320000]],
    ];

    /** Las coberturas: paciente (orden), [convenio, plan], número de asegurado, tipo, vencida. */
    public const COBERTURAS = [
        [0, ['Convenio Ejemplo Uno', 'Plan Oro'], 'UNO-000101', 'TITULAR', false],
        [1, ['Convenio Ejemplo Uno', 'Plan Plata'], 'UNO-000102', 'BENEFICIARIO', false],
        [2, ['Convenio Previsión Dos', 'Plan Integral'], 'DOS-000201', 'TITULAR', false],
        [3, ['Convenio Previsión Dos', 'Plan Básico'], 'DOS-000202', 'TITULAR', true],
    ];

    public function run(): void
    {
        $desde = self::inicioVigencias();
        $ruc = TipoDocumento::where('codigo', 'RUC')->first() ?? throw new RuntimeException('Falta el tipo de documento RUC.');
        $ciudad = Ciudad::whereHas('departamento', fn ($query) => $query->where('nombre', 'Asunción (Distrito Capital)'))->where('nombre', 'Asunción')->value('id');
        $procedimientos = Procedimiento::whereIn('codigo', ['CONS-001', 'ECO-TV'])->pluck('id', 'codigo');

        foreach (self::ASEGURADORAS as $base => [$razonSocial, $fantasia, $correo, $telefono, $nombreConvenio, $planes, $tarifas]) {
            $responsable = $this->como($this->correo('recepcion'), function () use ($ruc, $base, $razonSocial, $fantasia, $correo, $telefono, $ciudad) {
                $persona = Persona::firstOrCreate(['tipo_documento_id' => $ruc->id, 'nro_documento' => Ruc::conDigito($base)], [
                    'tipo_persona' => 'JURIDICA', 'razon_social' => $razonSocial, 'nombre_fantasia' => $fantasia,
                    'representante_legal' => 'Representante de prueba (demo)', 'email' => $this->correo($correo), 'telefono' => $telefono,
                    'direccion' => 'Avenida Ejemplo 1000, oficina demo', 'ciudad_id' => $ciudad,
                ]);
                $persona->wasRecentlyCreated && $this->contar('personas jurídicas');
                $responsable = ResponsablePago::firstOrCreate(['persona_id' => $persona->id]);
                $responsable->wasRecentlyCreated && $this->contar('responsables de pago');

                return $responsable;
            });

            $this->como($this->correo('facturacion'), function () use ($responsable, $nombreConvenio, $planes, $tarifas, $procedimientos, $desde) {
                $facturacion = $this->usuario('facturacion');
                $convenio = Convenio::where(['responsable_pago_id' => $responsable->id, 'nombre' => $nombreConvenio])->first();
                if (! $convenio) {
                    $convenio = GestionConvenios::alta($facturacion, $responsable->id, $nombreConvenio, $desde, null, $planes);
                    $this->contar('convenios');
                    $this->contar('planes', count($planes));
                }
                foreach ($tarifas as $codigo => $monto) {
                    if (! TarifaConvenio::where(['convenio_id' => $convenio->id, 'procedimiento_id' => $procedimientos[$codigo]])->exists()) {
                        GestionConvenios::guardarTarifa($facturacion, $convenio, $procedimientos[$codigo], $monto, $desde);
                        $this->contar('tarifas del convenio');
                    }
                }
            });
        }

        $pacientes = $this->pacientesDemo();
        $this->como($this->correo('recepcion'), function () use ($pacientes, $desde) {
            $recepcion = $this->usuario('recepcion');
            foreach (self::COBERTURAS as [$orden, [$convenio, $plan], $numero, $tipo, $vencida]) {
                $paciente = $pacientes[$orden];
                if (CoberturaPaciente::where('paciente_id', $paciente->id)->exists()) {
                    continue;
                }
                $planId = PlanConvenio::whereHas('convenio', fn ($query) => $query->where('nombre', $convenio))->where('nombre', $plan)->value('id')
                    ?? throw new RuntimeException("Falta el plan {$plan} de {$convenio}.");
                // La vencida: valió un mes, mucho antes de los días simulados.
                GestionCoberturas::cargarCobertura($recepcion, $paciente, $planId, $numero, $tipo, $desde, $vencida ? $desde->copy()->addMonth()->subDay() : null);
                $this->contar('coberturas');
            }
        });
    }
}
