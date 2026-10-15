<?php

namespace App\Support;

use App\Models\Consulta;
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
 *
 * Formatos: las recetas nuevas llevan 'formato' => FORMATO_PDF (medicamento, cantidad e indicación de uso;
 * se imprimen en PDF, PdfReceta). Un snapshot sin 'formato' es de una receta anterior (dosis, vía,
 * frecuencia... e indicaciones generales de la consulta): se sigue imprimiendo desde el navegador
 * (recetas/_hoja), tal como está.
 */
final class HojaReceta
{
    /** Marca del snapshot de las recetas con indicación de uso, impresas en PDF. */
    public const FORMATO_PDF = 'pdf-indicacion-uso';

    /** Relaciones que hacen falta para armar la hoja de una receta. */
    public const RELACIONES = [
        'detalles', 'reemplazaA',
        'consulta.historiaClinica.paciente.persona.tipoDocumento',
        'consulta.profesional.persona', 'consulta.profesional.especialidadesActivas',
        'consulta.turno.consultorio.sucursal',
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
            'formato' => self::FORMATO_PDF,
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
                ->map(fn ($detalle) => $detalle->only(['medicamento', 'cantidad', 'indicacion_uso']))
                ->values()->all(),
            'observaciones' => $receta->observaciones,
        ];
    }

    /** Si la hoja es del formato nuevo (PDF con indicación de uso) y no de una receta anterior. */
    public static function esPdf(?array $hoja): bool
    {
        return ($hoja['formato'] ?? null) === self::FORMATO_PDF;
    }

    /** Si los datos de la hoja se pasan de media hoja en alguna de las dos mitades (MediaHoja), en su formato. */
    public static function excede(array $hoja): bool
    {
        return self::esPdf($hoja)
            ? MediaHoja::excedeConIndicacionUso($hoja['medicamentos'], $hoja['observaciones'], filled($hoja['reemplaza_a']))
            : MediaHoja::excede($hoja['medicamentos'], $hoja['observaciones'], $hoja['indicaciones'], filled($hoja['reemplaza_a']));
    }

    /** Si algún medicamento no tiene su indicación de uso (no se emite así). */
    public static function faltaIndicacion(array $hoja): bool
    {
        return collect($hoja['medicamentos'])->contains(fn (array $medicamento) => blank($medicamento['indicacion_uso'] ?? null));
    }

    /**
     * Sucursal del encabezado: la del consultorio del turno de la consulta. PROVISORIO: una consulta
     * sin turno no tiene consultorio, y por ahora se usa la sucursal activa más antigua.
     * Cuando la consulta registre dónde se atendió, usar eso.
     */
    private static function sucursal(Consulta $consulta): ?Sucursal
    {
        return $consulta->turno?->consultorio?->sucursal ?? Sucursal::activos()->orderBy('id')->first();
    }

    /** "Carmen Sofía Duarte" (nombre en orden natural); una persona jurídica, su razón social. */
    public static function nombreNatural(Persona $persona): string
    {
        return $persona->tipo_persona === 'JURIDICA'
            ? (string) $persona->razon_social
            : trim($persona->nombres.' '.$persona->apellidos);
    }
}
