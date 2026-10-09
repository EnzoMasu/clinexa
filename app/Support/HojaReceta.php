<?php

namespace App\Support;

use App\Models\Consulta;
use App\Models\Indicacion;
use App\Models\Persona;
use App\Models\Receta;
use App\Models\Sucursal;
use Illuminate\Support\Carbon;

/**
 * Todo lo que se imprime en la hoja de una receta, como un arreglo plano.
 *
 * - Al emitir, este arreglo se guarda en recetas.snapshot y desde ahí se dibuja SIEMPRE la hoja de
 *   una receta emitida o anulada: reimprimir sale idéntico aunque después cambien el nombre del
 *   paciente, la matrícula, la dirección de la sucursal o las indicaciones de la consulta.
 * - Un borrador (vista previa) se dibuja con este mismo arreglo, armado con los datos de hoy.
 *
 * Ninguna vista arma la hoja desde los modelos: así la vista previa y la hoja emitida no pueden
 * diferir en qué muestran.
 */
final class HojaReceta
{
    /** Relaciones que hacen falta para armar la hoja de una receta. */
    public const RELACIONES = [
        'detalles', 'reemplazaA',
        'consulta.historiaClinica.paciente.persona.tipoDocumento',
        'consulta.profesional.persona', 'consulta.profesional.especialidadesActivas',
        'consulta.turno.consultorio.sucursal',
        'consulta.indicaciones.tipoIndicacion',
    ];

    /** @return array<string, mixed> */
    public static function datos(Receta $receta, ?Carbon $fecha = null): array
    {
        $receta->loadMissing(self::RELACIONES);
        $consulta = $receta->consulta;
        $fecha ??= Fecha::hoy();
        $persona = $consulta->historiaClinica->paciente->persona;
        $profesional = $consulta->profesional;
        $sucursal = self::sucursal($consulta);

        return [
            'clinica' => [
                'nombre' => (string) config('clinexa.clinica.nombre'),
                'direccion' => $sucursal?->direccion,
                'telefono' => $sucursal?->telefono,
            ],
            'numero' => $receta->numero,
            'fecha' => $fecha->format('Y-m-d'),
            'reemplaza_a' => $receta->reemplazaA?->numero,
            'paciente' => [
                'nombre' => self::nombreNatural($persona),
                'documento' => trim($persona->tipoDocumento->codigo.' '.$persona->nro_documento),
                'ficha' => $consulta->historiaClinica->paciente->nro_ficha,
                // Edad cumplida el día de la receta.
                'edad' => $persona->fecha_nacimiento ? (int) $persona->fecha_nacimiento->diffInYears($fecha) : null,
            ],
            'profesional' => [
                'nombre' => self::nombreNatural($profesional->persona),
                'matricula' => $profesional->matricula,
                'especialidades' => $profesional->especialidadesActivas->pluck('nombre')->sort()->values()->all(),
            ],
            'medicamentos' => $receta->detalles->sortBy(['orden', 'id'])
                ->map(fn ($detalle) => $detalle->only(['medicamento', 'cantidad', 'dosis', 'via', 'frecuencia', 'duracion', 'observaciones']))
                ->values()->all(),
            'observaciones' => $receta->observaciones,
            'indicaciones' => $consulta->indicaciones->where('activo', true)->sortBy(['orden', 'id'])
                ->map(fn (Indicacion $indicacion) => ['tipo' => $indicacion->tipoIndicacion?->nombre, 'texto' => $indicacion->descripcion])
                ->values()->all(),
        ];
    }

    /** Si los datos de la hoja se pasan de media hoja en alguna de las dos mitades (MediaHoja). */
    public static function excede(array $hoja): bool
    {
        return MediaHoja::excede($hoja['medicamentos'], $hoja['observaciones'], $hoja['indicaciones'], filled($hoja['reemplaza_a']));
    }

    /**
     * Sucursal del encabezado: la del consultorio del turno de la consulta. PROVISORIO: una consulta
     * sin turno (urgencia) no tiene consultorio, y por ahora se usa la sucursal activa más antigua.
     * Cuando la consulta registre dónde se atendió, usar eso.
     */
    private static function sucursal(Consulta $consulta): ?Sucursal
    {
        return $consulta->turno?->consultorio?->sucursal ?? Sucursal::activos()->orderBy('id')->first();
    }

    /** "Carmen Sofía Duarte" (nombre en orden natural); una persona jurídica, su razón social. */
    private static function nombreNatural(Persona $persona): string
    {
        return $persona->tipo_persona === 'JURIDICA'
            ? (string) $persona->razon_social
            : trim($persona->nombres.' '.$persona->apellidos);
    }
}
