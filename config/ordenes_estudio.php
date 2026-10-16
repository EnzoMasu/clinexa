<?php

/*
| Orden de estudio (docs/ordenes-estudio.md). ÚNICO lugar de los textos fijos que salen impresos.
|
| TEXTO PROVISORIO, sin revisión: lo tiene que revisar el profesor o un asesor ANTES de usarlo con pacientes reales. Para
| reemplazarlo basta con editar este archivo; no hay texto fijo en las vistas ni en el código. Las órdenes YA emitidas
| conservan el texto con el que se emitieron (va en su snapshot y en su huella): cambiar esto solo afecta a las nuevas.
*/

return [

    /*
    | true: toda orden nueva sale marcada con 'marca_borrador' en el cartel superior y en el pie (queda en su snapshot).
    | Ponerlo en false SOLO cuando el texto ya fue revisado y aprobado.
    */
    'borrador' => true,
    'marca_borrador' => 'BORRADOR SUJETO A REVISIÓN',

    'titulo' => 'Orden de estudio',

    // Títulos de las secciones del cuerpo.
    'estudios' => 'Estudios solicitados',
    'indicacion_clinica' => 'Indicación clínica',
    'diagnostico' => 'Diagnóstico presuntivo (CIE-10)',

    // Debajo de las líneas impresas para la firma.
    'firma' => 'Firma',
    'aclaracion' => 'Aclaración y sello',
];
