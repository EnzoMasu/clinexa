<?php

return [

    /*
    | Respaldos de la base (php artisan clinexa:respaldo). Ver docs/respaldo.md.
    |
    | - carpeta: dónde se guardan. FUERA del repositorio: los respaldos tienen datos de salud.
    | - pg_bin: carpeta de pg_dump, pg_restore, createdb, dropdb y psql. Vacío: se busca la versión
    |   más nueva en C:\Program Files\PostgreSQL\*\bin y, si no está, se usan los del PATH.
    */
    'respaldos' => [
        'carpeta' => env('CLINEXA_RESPALDOS', 'C:\\clinexa\\respaldos'),
        'pg_bin' => env('CLINEXA_PG_BIN', ''),
    ],

];
