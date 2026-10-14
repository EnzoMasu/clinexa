<?php

/*
| Constancias de retiro y de estado (docs/constancias.md). ÚNICO lugar de los textos que salen impresos.
|
| TEXTO PROVISORIO: neutro y fáctico, sin valor legal revisado. Lo tiene que revisar el profesor o un asesor
| ANTES de usarlo con pacientes reales. Para reemplazarlo basta con editar este archivo; no hay texto en las
| vistas ni en el código. Las constancias YA emitidas conservan el texto con el que se emitieron (van en su
| snapshot): cambiar esto solo afecta a las nuevas.
|
| Marcadores: :paciente, :documento, :clinica, :fecha (del hecho), :hora (del hecho), :fecha_turno, :hora_turno,
| :profesional. Una línea sin hora no lleva ":hora" ni ":hora_turno".
*/

return [

    /*
    | true: toda constancia nueva sale marcada con 'marca_borrador' en la impresión (queda en su snapshot).
    | Ponerlo en false SOLO cuando el texto ya fue revisado y aprobado.
    */
    'borrador' => true,
    'marca_borrador' => 'BORRADOR SUJETO A REVISIÓN',

    'retiro' => [
        'titulo' => 'Constancia de retiro',
        // Una línea según el tipo de salida registrada por Recepción. Nada clínico.
        'hechos' => [
            'RETIRO' => 'Se deja constancia de que :paciente (:documento) se retiró de :clinica el :fecha a las :hora, sin haber recibido atención.',
            'RECHAZO' => 'Se deja constancia de que :paciente (:documento) rechazó la atención ofrecida en :clinica el :fecha a las :hora.',
        ],
        'turno_con_hora' => 'Turno del :fecha_turno a las :hora_turno.',
        'turno_sin_hora' => 'Turno del :fecha_turno.',
    ],

    'estado' => [
        'titulo' => 'Constancia de estado del turno',
        // Una línea según el estado del turno. Solo hechos del turno: sin diagnóstico ni datos clínicos.
        'hechos' => [
            'ATENDIDO' => 'Se deja constancia de que :paciente (:documento) se presentó en :clinica el :fecha_turno y su turno con :profesional figura como atendido.',
            'AUSENTE' => 'Se deja constancia de que :paciente (:documento) no se presentó a su turno del :fecha_turno en :clinica.',
            'RETIRO' => 'Se deja constancia de que :paciente (:documento) se presentó en :clinica el :fecha_turno y se retiró sin recibir atención.',
            'RECHAZO' => 'Se deja constancia de que :paciente (:documento) se presentó en :clinica el :fecha_turno y rechazó la atención ofrecida.',
        ],
        'turno_con_hora' => 'Turno del :fecha_turno a las :hora_turno, con :profesional.',
        'turno_sin_hora' => 'Turno del :fecha_turno, con :profesional.',
    ],

    // Pie de las dos constancias.
    'pie' => 'Este documento solo deja constancia de los hechos indicados.',
];
