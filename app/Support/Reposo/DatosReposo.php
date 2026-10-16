<?php

namespace App\Support\Reposo;

use App\Models\Consulta;
use App\Models\Diagnostico;
use App\Models\ReposoMedico;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Fecha;
use App\Support\HojaReceta;
use Illuminate\Support\Carbon;

/**
 * Todo lo que se imprime en un certificado de reposo, como un arreglo plano (queda en el snapshot y de ahí sale su huella).
 * Los textos fijos vienen de config/reposo.php, el único lugar donde se reemplazan.
 *
 * Lista CERRADA de datos, armada campo por campo (sin volcar modelos): clínica, paciente, empleador, profesional, fecha de
 * atención, días, desde y hasta, modalidad, observaciones, prórroga y, solo si el profesional los eligió, diagnósticos con su
 * CIE-10. Nunca el motivo de consulta, la anamnesis, los signos, los hallazgos, las indicaciones ni las recetas.
 */
final class DatosReposo
{
    /**
     * @param  array{dias: int, desde: Carbon, modalidad: string, modalidad_otra: ?string, empleador: ?string, observaciones: ?string}  $datos
     * @param  iterable<Diagnostico>  $diagnosticos  los elegidos para imprimir (ya verificados: activos y de esta consulta)
     * @return array<string, mixed>
     */
    public static function armar(Consulta $consulta, User $emisor, array $datos, iterable $diagnosticos, ?ReposoMedico $prorrogaDe): array
    {
        $consulta->loadMissing(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona', 'profesional.especialidadesActivas', 'turno.consultorio.sucursal']);
        $emisor->loadMissing('persona');
        $paciente = $consulta->historiaClinica->paciente;
        $persona = $paciente->persona;
        $profesional = $consulta->profesional;
        $sucursal = $consulta->turno?->consultorio?->sucursal ?? Sucursal::activos()->orderBy('id')->first();
        $hoy = Fecha::hoy();
        $desde = $datos['desde']->copy()->startOfDay();
        $modalidad = $datos['modalidad'];

        return [
            'titulo' => config('reposo.titulo'),
            'clinica' => ['nombre' => (string) config('clinexa.clinica.nombre'), 'direccion' => $sucursal?->direccion, 'telefono' => $sucursal?->telefono],
            'fecha' => $hoy->format('Y-m-d'),
            'paciente' => [
                'nombre' => HojaReceta::nombreNatural($persona),
                'documento' => trim($persona->tipoDocumento->codigo.' '.$persona->nro_documento),
                'fecha_nacimiento' => $persona->fecha_nacimiento?->format('Y-m-d'),
                // Edad cumplida el día de la emisión.
                'edad' => $persona->fecha_nacimiento ? (int) $persona->fecha_nacimiento->diffInYears($hoy) : null,
                'telefono' => $persona->telefono,
                'ficha' => $paciente->nro_ficha,
            ],
            'empleador' => self::texto($datos['empleador'] ?? null),
            'profesional' => [
                'nombre' => HojaReceta::nombreNatural($profesional->persona),
                'especialidades' => $profesional->especialidadesActivas->pluck('nombre')->sort()->values()->all(),
                'matricula' => $profesional->matricula,
                // Contacto: el teléfono de la clínica (no los datos personales del profesional).
                'contacto' => $sucursal?->telefono,
            ],
            // Día de la atención, en la hora local.
            'fecha_atencion' => Carbon::parse($consulta->fechaAtencion())->setTimezone(config('app.zona_horaria_local'))->format('Y-m-d'),
            'dias' => $datos['dias'],
            'desde' => $desde->format('Y-m-d'),
            'hasta' => self::hasta($desde, $datos['dias'])->format('Y-m-d'),
            'modalidad' => [
                'codigo' => $modalidad,
                'texto' => config("reposo.modalidades.{$modalidad}"),
                'detalle' => $modalidad === 'OTRA' ? self::texto($datos['modalidad_otra'] ?? null) : null,
            ],
            'observaciones' => self::texto($datos['observaciones'] ?? null),
            'prorroga_de' => $prorrogaDe?->numero,
            'diagnosticos' => collect($diagnosticos)
                ->sortBy([['principal', 'desc'], ['id', 'asc']])
                ->map(fn (Diagnostico $diagnostico) => ['codigo' => $diagnostico->codigo_cie10, 'descripcion' => (string) $diagnostico->cie10?->descripcion])
                ->values()->all(),
            'leyenda' => config('reposo.leyenda'),
            'inclusive' => config('reposo.inclusive'),
            'firma' => config('reposo.firma'),
            'aclaracion' => config('reposo.aclaracion'),
            'emitido_por' => HojaReceta::nombreNatural($emisor->persona),
            'borrador' => (bool) config('reposo.borrador'),
            'marca_borrador' => config('reposo.marca_borrador'),
        ];
    }

    /** Último día del reposo: "desde" más los días, contando los dos extremos (5 días desde el 15 terminan el 19). */
    public static function hasta(Carbon $desde, int $dias): Carbon
    {
        return $desde->copy()->addDays($dias - 1);
    }

    private static function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
