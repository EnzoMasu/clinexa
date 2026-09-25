<?php

use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\User;
use App\Notifications\InvitacionUsuario;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

function opcionesPersona(array $cambios = []): array
{
    return [
        '--documento' => '1234567',
        '--apellidos' => 'Masuzzo',
        '--nombres' => 'Enzo',
        '--fecha-nacimiento' => '1990-05-10',
        '--telefono' => '0981 000 000',
        '--direccion' => 'Concepción',
        ...$cambios,
    ];
}

test('crea la persona y el usuario administrador con todos los permisos, y le manda el link', function () {
    $this->artisan('clinexa:crear-admin', ['email' => 'Admin@Clinexa.test', ...opcionesPersona()])->assertSuccessful();

    $admin = User::where('email', 'admin@clinexa.test')->sole();
    $perfil = PerfilAcceso::where('nombre', PerfilAcceso::ADMINISTRADOR)->sole();

    expect($admin->perfil_acceso_id)->toBe($perfil->id)
        ->and($perfil->permisos()->count())->toBe(10 * 5)
        ->and($admin->persona)
            ->nro_documento->toBe('1234567')
            ->nombre_completo->toBe('Masuzzo, Enzo')
            ->email->toBe('admin@clinexa.test')
            ->tipo_persona->toBe('FISICA')
            ->estado->toBe('ACTIVO')
        ->and($admin->persona->tipoDocumento->codigo)->toBe('CI');
    Notification::assertSentTo($admin, InvitacionUsuario::class);

    // Un segundo admin con el mismo email se rechaza.
    $this->artisan('clinexa:crear-admin', ['email' => 'admin@clinexa.test', ...opcionesPersona(['--documento' => '7654321'])])->assertFailed();
});

test('si faltan datos de la persona los pregunta', function () {
    $this->artisan('clinexa:crear-admin', ['email' => 'admin@clinexa.test'])
        ->expectsQuestion('Número de CI', '1234567')
        ->expectsQuestion('Apellidos', 'Masuzzo')
        ->expectsQuestion('Nombres', 'Enzo')
        ->expectsQuestion('Fecha de nacimiento (AAAA-MM-DD)', '1990-05-10')
        ->expectsQuestion('Teléfono', '0981 000 000')
        ->expectsQuestion('Dirección', 'Concepción')
        ->assertSuccessful();

    expect(User::where('email', 'admin@clinexa.test')->sole()->persona->nombre_completo)->toBe('Masuzzo, Enzo');
});

test('si ya existe una persona con ese CI y el mismo email, la usa sin duplicarla', function () {
    $persona = Persona::factory()->create(['nro_documento' => '1234567', 'email' => 'admin@clinexa.test']);

    $this->artisan('clinexa:crear-admin', ['email' => 'admin@clinexa.test', '--documento' => '1234567'])->assertSuccessful();

    expect(User::where('email', 'admin@clinexa.test')->sole()->persona_id)->toBe($persona->id)
        ->and(Persona::where('nro_documento', '1234567')->count())->toBe(1);
});

test('rechaza una persona existente que no se puede usar', function (array $atributos, string $mensaje) {
    $persona = Persona::factory()->create(['nro_documento' => '1234567', 'email' => 'admin@clinexa.test', ...$atributos]);
    if ($mensaje === 'ya tiene un usuario') {
        User::factory()->create(['persona_id' => $persona->id, 'email' => 'otro@clinexa.test']);
    }

    $this->artisan('clinexa:crear-admin', ['email' => 'admin@clinexa.test', '--documento' => '1234567'])
        ->expectsOutputToContain($mensaje)
        ->assertFailed();

    expect(User::where('email', 'admin@clinexa.test')->exists())->toBeFalse();
})->with([
    'con otro email' => [['email' => 'distinto@clinexa.test'], 'tiene otro email'],
    'inactiva' => [['estado' => 'INACTIVO'], 'está inactiva'],
    'que ya tiene usuario' => [[], 'ya tiene un usuario'],
]);

test('rechaza datos de persona inválidos sin crear nada', function () {
    $this->artisan('clinexa:crear-admin', ['email' => 'admin@clinexa.test', ...opcionesPersona(['--fecha-nacimiento' => 'ayer', '--apellidos' => ''])])
        ->assertFailed();

    expect(User::count())->toBe(0)->and(Persona::count())->toBe(0);
});
