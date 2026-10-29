<?php

namespace Database\Seeders\Demo;

use RuntimeException;

/**
 * Recorrido manual (docs/recorrido-manual.md): SOLO las disponibilidades de lunes a viernes de los dos médicos demo, las mismas
 * de AgendaDemo, y ningún turno ni urgencia: los turnos los da Recepción a mano durante el recorrido. Idempotente.
 */
class DisponibilidadesRecorrido extends AgendaDemo
{
    public function run(): void
    {
        $medicos = collect(self::CONSULTORIO_DE)->map(fn ($consultorio, $usuario) => $this->profesional($usuario));
        $this->como($this->correo('gerencia'), fn () => $this->disponibilidades($medicos));
    }
}
