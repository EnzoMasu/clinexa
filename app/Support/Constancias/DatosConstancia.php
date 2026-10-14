<?php

namespace App\Support\Constancias;

use App\Models\ConstanciaEstado;
use App\Models\ConstanciaRetiro;
use App\Models\Estado;
use App\Models\SalidaSinAtencion;
use App\Models\Sucursal;
use App\Models\Turno;
use App\Models\User;
use App\Support\Fecha;
use App\Support\HojaReceta;
use Illuminate\Support\Carbon;

/**
 * Todo lo que se imprime en una constancia, como un arreglo plano (queda en el snapshot y de ahí sale su huella). Los
 * textos vienen de config/constancias.php, el único lugar donde se reemplazan.
 *
 * Lista CERRADA de datos: se arma campo por campo, sin volcar modelos, para que nada clínico pueda colarse.
 * - Retiro (la emite Recepción): paciente (nombre y documento), clínica, fecha y hora del hecho, fecha y hora del turno, y si
 *   fue retiro o rechazo. Nada del triaje, la urgencia, la observación, la nota, los signos, el cargo ni el tipo de turno.
 * - Estado: lo mismo, más el profesional del turno (nombre y matrícula) y el hecho según el estado del turno.
 */
final class DatosConstancia
{
    /** Clase del modelo de cada tipo. */
    public const MODELOS = ['retiro' => ConstanciaRetiro::class, 'estado' => ConstanciaEstado::class];

    /** @return array<string, mixed> */
    public static function retiro(Turno $turno, SalidaSinAtencion $salida, User $emisor): array
    {
        $cuando = Carbon::parse($salida->created_at)->setTimezone(config('app.zona_horaria_local'));

        return self::armar('retiro', $turno, $emisor, $salida->tipo, $cuando);
    }

    /** @return array<string, mixed> */
    public static function estado(Turno $turno, User $emisor): array
    {
        $hecho = $turno->estado->codigo === Estado::RETIRADO ? $turno->salida->tipo : $turno->estado->codigo;

        return self::armar('estado', $turno, $emisor, $hecho, null);
    }

    private static function armar(string $tipo, Turno $turno, User $emisor, string $hecho, ?Carbon $cuando): array
    {
        $turno->loadMissing(['paciente.persona.tipoDocumento', 'profesional.persona', 'consultorio.sucursal']);
        $emisor->loadMissing('persona');
        $persona = $turno->paciente->persona;
        $sucursal = $turno->consultorio?->sucursal ?? Sucursal::activos()->orderBy('id')->first();
        $textos = config("constancias.{$tipo}");

        $paciente = ['nombre' => HojaReceta::nombreNatural($persona), 'documento' => trim($persona->tipoDocumento->codigo.' '.$persona->nro_documento)];
        $profesional = ['nombre' => HojaReceta::nombreNatural($turno->profesional->persona), 'matricula' => $turno->profesional->matricula];
        $horaTurno = $turno->tieneHorario() ? substr((string) $turno->hora_inicio, 0, 5) : null;

        $valores = [
            ':paciente' => $paciente['nombre'],
            ':documento' => $paciente['documento'],
            ':clinica' => (string) config('clinexa.clinica.nombre'),
            ':fecha_turno' => Fecha::mostrar($turno->fecha),
            ':hora_turno' => (string) $horaTurno,
            ':profesional' => $profesional['nombre'],
            ':fecha' => $cuando?->format(Fecha::FORMATO) ?? '',
            ':hora' => $cuando?->format('H:i') ?? '',
        ];
        $reemplazar = fn (string $texto) => strtr($texto, $valores);

        $datos = [
            'tipo' => $tipo,
            'titulo' => $textos['titulo'],
            'clinica' => ['nombre' => $valores[':clinica'], 'direccion' => $sucursal?->direccion, 'telefono' => $sucursal?->telefono],
            'fecha' => Fecha::hoy()->format('Y-m-d'),
            'paciente' => $paciente,
            'hecho' => $reemplazar($textos['hechos'][$hecho]),
            'turno' => $reemplazar($textos[$horaTurno ? 'turno_con_hora' : 'turno_sin_hora']),
            'pie' => config('constancias.pie'),
            'emitida_por' => HojaReceta::nombreNatural($emisor->persona),
            'borrador' => (bool) config('constancias.borrador'),
            'marca_borrador' => config('constancias.marca_borrador'),
        ];
        if ($tipo === 'estado') {
            $datos['profesional'] = $profesional;
        }

        return $datos;
    }
}
