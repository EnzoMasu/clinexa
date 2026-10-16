<?php

namespace App\Support\OrdenEstudio;

use App\Models\Consulta;
use App\Models\Diagnostico;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Fecha;
use App\Support\HojaReceta;

/**
 * Todo lo que se imprime en una orden de estudio, como un arreglo plano (queda en el snapshot y de ahí sale su huella). Los
 * textos fijos vienen de config/ordenes_estudio.php, el único lugar donde se reemplazan.
 *
 * Lista CERRADA de datos, armada campo por campo (sin volcar modelos): clínica, paciente, profesional, estudios (nombre
 * copiado; los del catálogo con su id, los escritos a mano sin él), indicación clínica y, solo si el profesional los eligió,
 * diagnósticos con su CIE-10. Nunca el motivo de consulta, la anamnesis, los síntomas, los signos, los hallazgos, las
 * indicaciones ni las recetas.
 */
final class DatosOrden
{
    /**
     * @param  list<array{nombre: string, estudio_id: ?int}>  $estudios  ya verificados y en el orden en que se imprimen
     * @param  iterable<Diagnostico>  $diagnosticos  los elegidos para imprimir (ya verificados: activos y de esta consulta)
     * @return array<string, mixed>
     */
    public static function armar(Consulta $consulta, User $emisor, array $estudios, ?string $indicacionClinica, iterable $diagnosticos): array
    {
        $consulta->loadMissing(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona', 'profesional.especialidadesActivas', 'turno.consultorio.sucursal']);
        $emisor->loadMissing('persona');
        $paciente = $consulta->historiaClinica->paciente;
        $persona = $paciente->persona;
        $profesional = $consulta->profesional;
        $sucursal = $consulta->turno?->consultorio?->sucursal ?? Sucursal::activos()->orderBy('id')->first();
        $hoy = Fecha::hoy();
        $indicacionClinica = trim((string) $indicacionClinica);

        return [
            'titulo' => config('ordenes_estudio.titulo'),
            'clinica' => ['nombre' => (string) config('clinexa.clinica.nombre'), 'direccion' => $sucursal?->direccion, 'telefono' => $sucursal?->telefono],
            'fecha' => $hoy->format('Y-m-d'),
            'paciente' => [
                'nombre' => HojaReceta::nombreNatural($persona),
                'documento' => trim($persona->tipoDocumento->codigo.' '.$persona->nro_documento),
                'fecha_nacimiento' => $persona->fecha_nacimiento?->format('Y-m-d'),
                // Edad cumplida el día de la emisión.
                'edad' => $persona->fecha_nacimiento ? (int) $persona->fecha_nacimiento->diffInYears($hoy) : null,
                'ficha' => $paciente->nro_ficha,
            ],
            'profesional' => [
                'nombre' => HojaReceta::nombreNatural($profesional->persona),
                'especialidades' => $profesional->especialidadesActivas->pluck('nombre')->sort()->values()->all(),
                'matricula' => $profesional->matricula,
                // Contacto: el teléfono de la clínica (no los datos personales del profesional).
                'contacto' => $sucursal?->telefono,
            ],
            'estudios' => array_values(array_map(fn (array $estudio) => ['nombre' => $estudio['nombre'], 'estudio_id' => $estudio['estudio_id']], $estudios)),
            'indicacion_clinica' => $indicacionClinica === '' ? null : $indicacionClinica,
            'diagnosticos' => collect($diagnosticos)
                ->sortBy([['principal', 'desc'], ['id', 'asc']])
                ->map(fn (Diagnostico $diagnostico) => ['codigo' => $diagnostico->codigo_cie10, 'descripcion' => (string) $diagnostico->cie10?->descripcion])
                ->values()->all(),
            'textos' => [
                'estudios' => config('ordenes_estudio.estudios'),
                'indicacion_clinica' => config('ordenes_estudio.indicacion_clinica'),
                'diagnostico' => config('ordenes_estudio.diagnostico'),
            ],
            'firma' => config('ordenes_estudio.firma'),
            'aclaracion' => config('ordenes_estudio.aclaracion'),
            'emitido_por' => HojaReceta::nombreNatural($emisor->persona),
            'borrador' => (bool) config('ordenes_estudio.borrador'),
            'marca_borrador' => config('ordenes_estudio.marca_borrador'),
        ];
    }
}
