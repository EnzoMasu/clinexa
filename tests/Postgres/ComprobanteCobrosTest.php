<?php

use App\Models\MedioPago;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\MediosPagoSeeder;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * El CHECK del comprobante de los cobros en PostgreSQL (docs/cobros-caja.md): el efectivo nunca pide comprobante
 * (medios_pago_efectivo_sin_comprobante). Lo demás está en tests/Feature/Economico/ComprobanteCobrosTest.php. Datos inventados.
 */
test('el efectivo no puede pedir comprobante, ni por SQL; los demás medios de referencia sí', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();

    expect(fn () => DB::transaction(fn () => DB::table('medios_pago')->where('codigo', MedioPago::EFECTIVO)->update(['requiere_comprobante' => true])))->toThrow(QueryException::class);
    DB::table('medios_pago')->where('codigo', MedioPago::CHEQUE)->update(['requiere_comprobante' => true]);

    expect(MedioPago::efectivo()->requiere_comprobante)->toBeFalse()
        ->and(MedioPago::where('codigo', MedioPago::CHEQUE)->sole()->exigeComprobante())->toBeTrue();
});
