<?php

namespace Database\Seeders\Demo;

use App\Models\AcuerdoReparto;
use App\Models\CategoriaProveedor;
use App\Models\Dieta;
use App\Models\Equipo;
use App\Models\Estudio;
use App\Models\Procedimiento;
use App\Models\Proveedor;
use App\Models\Tarifa;
use App\Support\Ruc;
use App\Models\TipoEquipo;
use App\Support\Repartos\AltaAcuerdo;
use App\Support\Tarifas\AltaTarifa;
use RuntimeException;

/**
 * Catálogos y economía demo (etapa 2, parte A): los estudios del catálogo de órdenes, dietas de prueba, el ecógrafo (dueña:
 * la Dra. Benítez como proveedora) usado por las ecografías, las tarifas generales y los acuerdos de reparto. Precios y
 * porcentajes inventados y redondos.
 *
 * A propósito: PAP-COLPO sin tarifa ("pendiente de tarifar") y el Dr. Duarte sin acuerdo ("pendiente de reparto").
 *
 * Con servicio: tarifas (AltaTarifa) y acuerdos (AltaAcuerdo). Sin servicio (con los modelos y su auditoría, como el panel):
 * estudios y dietas (Supervisión médica), tipo de equipo y equipo (Gerencia) y el rol de proveedora (Compras).
 * Idempotente: cada cosa por su nombre o su clave; lo que existe no se toca.
 */
class EconomiaDemo extends EtapaDemo
{
    public const ESTUDIOS = [
        'Hemograma completo', 'Perfil hormonal (FSH, LH, estradiol)', 'Papanicolaou', 'Mamografía bilateral', 'Ecografía mamaria', 'Urocultivo',
    ];

    /** nombre => texto. Textos de PRUEBA, sin valor clínico. */
    public const DIETAS = [
        'Dieta de prueba A (demo)' => "Texto de prueba (demo), sin valor clínico.\nComidas de ejemplo: desayuno, almuerzo, merienda y cena.",
        'Dieta de prueba B (demo)' => "Texto de prueba (demo), sin valor clínico.\nLista de ejemplo de alimentos permitidos y no permitidos.",
        'Dieta de prueba C (demo)' => 'Texto de prueba (demo), sin valor clínico. Recomendaciones de ejemplo en una sola línea.',
    ];

    public const TIPO_EQUIPO = 'Ecógrafo';

    public const EQUIPO = 'Ecógrafo Mindray MX7';

    /** Tarifas generales (guaraníes). PAP-COLPO, a propósito, sin tarifa. */
    public const TARIFAS = [
        'CONS-001' => 250000, 'ECO-ABD' => 350000, 'ECO-TV' => 350000, 'ECO-OBST' => 350000,
        'ECO-OBST-DOP' => 450000, 'ECO-CROMO' => 600000, 'ECO-MORFO' => 700000,
    ];

    /** Los procedimientos que usan el ecógrafo. */
    public const ECOGRAFIAS = ['ECO-ABD', 'ECO-TV', 'ECO-OBST', 'ECO-OBST-DOP', 'ECO-CROMO', 'ECO-MORFO'];

    public function run(): void
    {
        $desde = self::inicioVigencias();
        $procedimientos = Procedimiento::whereIn('codigo', array_keys(BaseClinica::PROCEDIMIENTOS))->get()->keyBy('codigo');
        $benitez = $this->profesional('medica1');

        $this->como($this->correo('supervision'), function () {
            foreach (self::ESTUDIOS as $nombre) {
                Estudio::firstOrCreate(['nombre' => $nombre])->wasRecentlyCreated && $this->contar('estudios');
            }
            foreach (self::DIETAS as $nombre => $texto) {
                Dieta::firstOrCreate(['nombre' => $nombre], ['texto' => $texto])->wasRecentlyCreated && $this->contar('dietas');
            }
        });

        $proveedor = $this->como($this->correo('compras'), function () use ($benitez) {
            // Proveedora de equipos: física (CI 9000007), con su RUC en el campo propio (base = su CI) y el dígito calculado.
            $persona = $benitez->persona;
            if ($persona->ruc === null) {
                $persona->update(['ruc' => Ruc::conDigito($persona->nro_documento)]);
                $this->contar('RUC completados');
            }
            $proveedor = Proveedor::firstOrCreate(['persona_id' => $benitez->persona_id]);
            if ($proveedor->wasRecentlyCreated) {
                $this->contar('proveedores');
                $categoria = CategoriaProveedor::where('nombre', 'Equipos médicos')->first() ?? throw new RuntimeException('Falta la categoría de proveedor "Equipos médicos".');
                $proveedor->sincronizarAuditado('categorias', [$categoria->id]);
            }

            return $proveedor;
        });

        $this->como($this->correo('gerencia'), function () use ($desde, $procedimientos, $benitez, $proveedor) {
            $gerencia = $this->usuario('gerencia');

            $tipo = TipoEquipo::firstOrCreate(['nombre' => self::TIPO_EQUIPO]);
            $tipo->wasRecentlyCreated && $this->contar('tipos de equipo');
            $equipo = Equipo::firstOrCreate(['nombre' => self::EQUIPO], ['tipo_equipo_id' => $tipo->id, 'proveedor_id' => $proveedor->id]);
            if ($equipo->wasRecentlyCreated) {
                $this->contar('equipos');
                $equipo->sincronizarAuditado('procedimientos', $procedimientos->whereIn('codigo', self::ECOGRAFIAS)->modelKeys());
            }

            foreach (self::TARIFAS as $codigo => $monto) {
                if (! Tarifa::query()->deLaClave($procedimientos[$codigo]->id, null)->exists()) {
                    AltaTarifa::ejecutar($gerencia, $procedimientos[$codigo]->id, null, $monto, $desde);
                    $this->contar('tarifas');
                }
            }

            // Dra. Benítez: 60 % general y 40 % en ECO-CROMO; el ecógrafo, 30 % para su dueña. El Dr. Duarte, sin acuerdo.
            $acuerdos = [
                [AcuerdoReparto::PROFESIONAL, $benitez->id, null, null, '60'],
                [AcuerdoReparto::PROFESIONAL, $benitez->id, $procedimientos['ECO-CROMO']->id, null, '40'],
                [AcuerdoReparto::EQUIPO, null, null, $equipo->id, '30'],
            ];
            foreach ($acuerdos as [$tipoAcuerdo, $profesionalId, $procedimientoId, $equipoId, $porcentaje]) {
                if (! AcuerdoReparto::query()->deLaClave($tipoAcuerdo, $profesionalId, $procedimientoId, $equipoId)->exists()) {
                    AltaAcuerdo::ejecutar($gerencia, $tipoAcuerdo, $profesionalId, $procedimientoId, $equipoId, $porcentaje, $desde);
                    $this->contar('acuerdos de reparto');
                }
            }
        });
    }
}
