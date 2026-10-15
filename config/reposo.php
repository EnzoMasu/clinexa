<?php

/*
| Certificado de reposo médico (docs/reposo-medico.md). ÚNICO lugar de los textos fijos que salen impresos.
|
| TEXTO PROVISORIO, sin revisión legal: lo tiene que revisar el profesor o un asesor ANTES de usarlo con pacientes
| reales. Para reemplazarlo basta con editar este archivo; no hay texto fijo en las vistas ni en el código. Los
| certificados YA emitidos conservan el texto con el que se emitieron (va en su snapshot y en su huella): cambiar
| esto solo afecta a los nuevos.
*/

return [

    /*
    | true: todo certificado nuevo sale marcado con 'marca_borrador' en la impresión (queda en su snapshot).
    | Ponerlo en false SOLO cuando el texto ya fue revisado y aprobado.
    */
    'borrador' => true,
    'marca_borrador' => 'BORRADOR SUJETO A REVISIÓN',

    'titulo' => 'Certificado de reposo médico',

    // Modalidades del reposo (una por certificado). OTRA lleva además el texto que escribe el profesional.
    'modalidades' => [
        'ABSOLUTO' => 'Reposo absoluto',
        'RELATIVO' => 'Reposo relativo / laboral',
        'DOMICILIARIO' => 'Reposo domiciliario',
        'OTRA' => 'Otra indicación',
    ],

    // Junto a "desde" y "hasta".
    'inclusive' => 'Ambas fechas inclusive.',

    // Leyenda al pie del cuerpo del certificado.
    'leyenda' => 'Se extiende a solicitud del/de la paciente para ser presentado ante quien corresponda.',

    // Debajo de las líneas impresas para la firma.
    'firma' => 'Firma',
    'aclaracion' => 'Aclaración y sello',
];
