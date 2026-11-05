<?php

return [

    /*
    | La clínica, como sale en el encabezado de las recetas: el nombre es fijo; la dirección y el
    | teléfono son los de la sucursal (App\Support\HojaReceta).
    */
    'clinica' => [
        'nombre' => env('CLINEXA_NOMBRE_CLINICA', 'Plenitud Mujer'),
        'logo' => 'images/logo-plenitud-mujer.png',
    ],

    /*
    | Hoja de la receta: el tamaño de papel en un solo lugar. Se divide en dos mitades iguales (receta
    | arriba, indicaciones abajo). App\Support\MediaHoja estima cuánto entra en cada mitad.
    */
    'recetas' => [
        'papel' => ['nombre' => 'A4', 'ancho_mm' => 210, 'alto_mm' => 297],
        'relleno_mm' => 10,
    ],

    /*
    | Prestaciones (docs/tarifas-prestaciones.md): código del procedimiento que se usa cuando la consulta no tiene turno o su
    | turno no tiene procedimiento.
    */
    'prestaciones' => [
        'procedimiento_consulta' => env('CLINEXA_PROCEDIMIENTO_CONSULTA', 'CONS-001'),
    ],

    /*
    | Facturación (docs/facturacion.md).
    |
    | - simulada: la emisión es una SIMULACIÓN (Facturacion\EmisorSimulado): funciona como la factura electrónica, pero no se envía a
    |   ningún lado ni tiene validez fiscal; la factura, su PDF y la pantalla lo dicen. Apagada, hace falta un emisor real (todavía no
    |   hay: no se emite).
    | - mostrar_marca: SOLO muestra u oculta el cartel de pantalla y la marca del PDF; no cambia el modo de emisión. Sin marca, una
    |   factura simulada puede confundirse con una válida: no apagarla en un uso real.
    | - plazo_anulacion_minutos: desde la EMISIÓN, el cajero anula una factura suya, de su caja abierta, sin pedir aprobación.
    */
    'facturacion' => [
        'simulada' => (bool) env('CLINEXA_FACTURACION_SIMULADA', true),
        'mostrar_marca' => (bool) env('CLINEXA_FACTURACION_MOSTRAR_MARCA', true),
        'plazo_anulacion_minutos' => (int) env('CLINEXA_PLAZO_ANULACION_FACTURA', 10),
    ],

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

    /*
    | RUC de las personas (docs/ruc.md): si se controla el dígito verificador (módulo 11 de la DNIT). Apagarlo solo
    | acepta cualquier dígito; el formato (6 a 8 dígitos, guion y un dígito) se exige siempre.
    */
    'validar_digito_ruc' => (bool) env('CLINEXA_VALIDAR_DIGITO_RUC', true),

];
