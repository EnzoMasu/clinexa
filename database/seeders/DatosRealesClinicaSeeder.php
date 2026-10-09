<?php

namespace Database\Seeders;

use App\Models\CategoriaProveedor;
use App\Models\Ciudad;
use App\Models\Especialidad;
use App\Models\ModuloSistema;
use App\Models\OrigenTurno;
use App\Models\Persona;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\ResponsablePago;
use App\Models\Sucursal;
use App\Models\TipoBloqueAnamnesis;
use App\Models\TipoDocumento;
use App\Models\TipoIndicacion;
use App\Models\TipoRedSocial;
use Illuminate\Database\Seeder;

/**
 * Datos reales de la clínica. Idempotente: busca por el campo que identifica cada registro
 * y lo actualiza si ya existe, así se puede correr varias veces sin duplicar.
 */
class DatosRealesClinicaSeeder extends Seeder
{
    /** Orígenes de turno (código => nombre). */
    public const ORIGENES_TURNO = ['PRESENCIAL' => 'Presencial', 'TELEFONICO' => 'Telefónico', 'WEB' => 'Web', 'APP' => 'App'];

    public const TIPOS_RED_SOCIAL = ['Facebook', 'Instagram', 'WhatsApp', 'LinkedIn', 'X', 'TikTok', 'YouTube', 'Telegram'];

    public const TIPOS_BLOQUE_ANAMNESIS = [
        'Enfermedad actual', 'Antecedentes personales', 'Antecedentes familiares', 'Hábitos',
        'Antecedentes ginecoobstétricos', 'Alergias', 'Otros',
    ];

    public const TIPOS_INDICACION = ['Reposo', 'Dieta', 'Control', 'General'];

    public const CATEGORIAS_PROVEEDOR = ['Insumos médicos', 'Equipos médicos', 'Insumos de oficina', 'Artículos de limpieza', 'Servicios tercerizados'];

    public function run(): void
    {
        // sucursales no tiene columna unique en el diseño: se identifica por nombre.
        $sucursal = Sucursal::updateOrCreate(['nombre' => 'Plenitud Mujer'], [
            'direccion' => 'Iturbe e/ Pte. Franco y Mcal Estigarribia, Concepción - Paraguay',
            'telefono' => '0975282556',
            // La clínica está en la ciudad de Concepción (requiere GeografiaSeeder antes).
            'ciudad_id' => Ciudad::whereHas('departamento', fn ($query) => $query->where('nombre', 'Concepción'))
                ->where('nombre', 'Concepción')->value('id'),
        ]);

        // El pasaporte se había cargado a mano con código "PAS": se renombra (mismo registro).
        if (! TipoDocumento::where('codigo', 'PASAPORTE')->exists()) {
            TipoDocumento::where('codigo', 'PAS')->update(['codigo' => 'PASAPORTE']);
        }

        $tiposDocumento = [
            'CI' => 'Cédula de identidad',
            'PASAPORTE' => 'Pasaporte',
            'RUC' => 'Registro Único del Contribuyente',
            'DNI' => 'Documento Nacional de Identidad',
        ];
        foreach ($tiposDocumento as $codigo => $nombre) {
            TipoDocumento::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        }

        // Tipos de documento que acepta el módulo Personas. SOLO ADITIVO: agrega los que falten sin
        // quitar lo habilitado a mano desde Tipos de documento; CI queda como predeterminado solo si
        // Personas todavía no tiene uno. Requiere los módulos (ModuloSistemaSeeder corre antes).
        ModuloSistema::where('codigo', 'PERSONAS')->first()
            ?->habilitarTiposDocumento(['CI', 'PASAPORTE', 'RUC', 'DNI']);

        // Catálogos editables desde el panel: los valores base se siembran solo si la tabla está vacía.
        // Así, si se renombra uno (o se le cambia el código) desde la pantalla, volver a correr el
        // seeder no crea un duplicado con el nombre original.
        if (! CategoriaProveedor::exists()) {
            foreach (self::CATEGORIAS_PROVEEDOR as $nombre) {
                CategoriaProveedor::create(['nombre' => $nombre]);
            }
        }

        if (! OrigenTurno::exists()) {
            foreach (self::ORIGENES_TURNO as $codigo => $nombre) {
                OrigenTurno::create(['codigo' => $codigo, 'nombre' => $nombre]);
            }
        }

        if (! TipoRedSocial::exists()) {
            foreach (self::TIPOS_RED_SOCIAL as $nombre) {
                TipoRedSocial::create(['nombre' => $nombre]);
            }
        }

        if (! TipoBloqueAnamnesis::exists()) {
            foreach (self::TIPOS_BLOQUE_ANAMNESIS as $nombre) {
                TipoBloqueAnamnesis::create(['nombre' => $nombre]);
            }
        }

        if (! TipoIndicacion::exists()) {
            foreach (self::TIPOS_INDICACION as $nombre) {
                TipoIndicacion::create(['nombre' => $nombre]);
            }
        }

        Especialidad::updateOrCreate(['nombre' => 'Ginecología y Obstetricia'], [
            'descripcion' => null,
        ]);

        $procedimientos = [
            ['CONS-001', 'Consulta', 'CONSULTA', 20],
            ['ECO-ABD', 'Ecografía Abdominal', 'ESTUDIO', 30],
            ['ECO-TV', 'Ecografía Transvaginal (Eco TV)', 'ESTUDIO', 30],
            ['ECO-OBST', 'Ecografía Obstétrica', 'ESTUDIO', 30],
            ['ECO-OBST-DOP', 'Ecografía Obstétrica + Doppler', 'ESTUDIO', 40],
            ['PAP-COLPO', 'Pap + Colposcopía', 'ESTUDIO', 30],
            ['ECO-CROMO', 'Eco Cromosómica', 'ESTUDIO', 90],
        ];

        foreach ($procedimientos as [$codigo, $nombre, $tipo, $duracion]) {
            Procedimiento::updateOrCreate(['codigo' => $codigo], [
                'nombre' => $nombre,
                'tipo' => $tipo,
                'duracion_estimada_minutos' => $duracion,
            ]);
        }

        $this->personasReales($sucursal);
    }

    /**
     * PERSONAS REALES de la clínica y sus roles (no son datos de demo).
     *
     * Solo se cargan los datos que se conocen. La base exige email, teléfono y dirección: el
     * teléfono y la dirección son los de la sucursal y el email queda vacío (''); la fecha de
     * nacimiento, el sexo y la nacionalidad quedan en NULL. Al editarlas en /admin/personas el
     * formulario pide completar lo que falta.
     *
     * Idempotente sin pisar ediciones: las personas y los roles se crean solo si no existen
     * (firstOrCreate), así volver a correr el seeder no borra un email, una fecha o unos datos
     * bancarios que se hayan completado después desde la pantalla.
     */
    private function personasReales(Sucursal $sucursal): void
    {
        $ruc = TipoDocumento::where('codigo', 'RUC')->sole();
        $contacto = ['email' => '', 'telefono' => $sucursal->telefono, 'direccion' => $sucursal->direccion];

        $luigi = Persona::firstOrCreate(['tipo_documento_id' => $ruc->id, 'nro_documento' => '4441089-1'], [
            'tipo_persona' => 'FISICA',
            'apellidos' => 'Masuzzo Zorrilla',
            'nombres' => 'Luigi Armando',
            ...$contacto,
        ]);

        $profesional = Profesional::firstOrCreate(['persona_id' => $luigi->id], ['matricula' => '15523']);
        // fecha_desde aproximada (no se conoce la real); sin número de matrícula de especialidad.
        $ginecologia = Especialidad::where('nombre', 'Ginecología y Obstetricia')->sole();
        if (! $profesional->especialidades()->whereKey($ginecologia->id)->exists()) {
            $profesional->especialidades()->attach($ginecologia->id, ['fecha_desde' => '2015-01-01', 'nro_matricula_especialidad' => null]);
        }

        // Dueño del ecógrafo Mindray MX7 - el registro del Equipo en sí se carga cuando
        // construyamos el módulo de Equipos, esto solo deja preparado el rol. Era PropietarioEquipo,
        // que se fusionó en Proveedor: proveedor con categoría "Equipos médicos", sin datos bancarios.
        $proveedor = Proveedor::firstOrCreate(['persona_id' => $luigi->id], ['datos_bancarios' => null]);
        // Si la categoría se renombró desde el panel, no se asigna (el seeder no la vuelve a crear).
        if ($equipos = CategoriaProveedor::where('nombre', 'Equipos médicos')->first()) {
            $proveedor->categorias()->syncWithoutDetaching([$equipos->id]);
        }

        $clara = Persona::firstOrCreate(['tipo_documento_id' => $ruc->id, 'nro_documento' => '421964-3'], [
            'tipo_persona' => 'FISICA',
            'apellidos' => 'Zorrilla de Masuzzo',
            'nombres' => 'Clara Daniela',
            ...$contacto,
        ]);

        // Sin límite de crédito definido.
        ResponsablePago::firstOrCreate(['persona_id' => $clara->id], ['limite_credito' => null]);
    }
}
