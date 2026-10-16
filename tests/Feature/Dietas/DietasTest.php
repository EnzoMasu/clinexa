<?php

/*
 * Catálogo de dietas e "Insertar dieta" (docs/indicaciones-generales.md): nace vacío; lo administran el Administrador y
 * Supervisión médica (módulo DIETAS); el Médico solo las usa y Recepción no ve nada; nada se borra. El texto se COPIA a la
 * indicación: editar o desactivar la dieta no cambia lo guardado, y una desactivada ya no se ofrece. Datos inventados.
 * Funciones con el prefijo "dietas".
 */

use App\Models\Dieta;
use App\Models\Estado;
use App\Models\Indicacion;
use App\Models\User;
use App\Support\PdfClinico\PdfIndicaciones;
use App\Support\PerfilesPredefinidos;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

const DIETAS_TODO = ['DIETAS' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR']];

beforeEach(function () {
    hcEscenario();
});

function dietasCrear(string $nombre, string $texto, string $estado = Estado::ACTIVO): Dieta
{
    return Dieta::create(['nombre' => $nombre, 'texto' => $texto, 'estado_id' => Estado::idDe($estado)]);
}

describe('el catálogo', function () {
    test('nace vacío: ningún seeder ni migración carga dietas', function () {
        expect(Dieta::count())->toBe(0);
        $archivos = collect(File::allFiles(database_path('seeders')))->merge(File::allFiles(database_path('migrations')));
        $conDietas = $archivos->filter(fn ($archivo) => preg_match('/Dieta::(create|firstOrCreate|updateOrCreate|insert)|table\(\'dietas\'\)->insert/', File::get($archivo->getPathname())));

        expect($conDietas->all())->toBe([]);
    });

    test('lo administran Supervisión médica y el Administrador: crear, editar y desactivar', function () {
        foreach ([User::factory()->conPermisos(DIETAS_TODO)->create(), User::factory()->administrador()->create()] as $n => $usuario) {
            $this->actingAs($usuario)->get(route('admin.dietas.index'))->assertOk();
            $this->actingAs($usuario)->post(route('admin.dietas.store'), ['nombre' => "Dieta de prueba {$n}", 'texto' => 'Texto de prueba.'])
                ->assertRedirect(route('admin.dietas.index'));
            $dieta = Dieta::where('nombre', "Dieta de prueba {$n}")->sole();
            $this->actingAs($usuario)->put(route('admin.dietas.update', $dieta), ['nombre' => $dieta->nombre, 'texto' => 'Otro texto.', 'estado_id' => $dieta->estado_id])
                ->assertRedirect();
            $this->actingAs($usuario)->patch(route('admin.dietas.desactivar', $dieta))->assertRedirect();

            expect($dieta->fresh()->texto)->toBe('Otro texto.')->and($dieta->fresh()->estaActivo())->toBeFalse();
        }
    });

    test('el Médico y Recepción no ven ni tocan el catálogo', function () {
        $dieta = dietasCrear('Dieta de prueba', 'Texto de prueba.');
        $recepcion = User::factory()->conPermisos(['TURNOS' => ['VER', 'CREAR', 'EDITAR'], 'PACIENTES' => ['VER', 'CREAR', 'EDITAR']])->create();

        foreach ([$this->medico, $recepcion] as $usuario) {
            $this->actingAs($usuario)->get(route('admin.dietas.index'))->assertForbidden();
            $this->actingAs($usuario)->get(route('admin.dietas.create'))->assertForbidden();
            $this->actingAs($usuario)->post(route('admin.dietas.store'), ['nombre' => 'Otra', 'texto' => 'Otro.'])->assertForbidden();
            $this->actingAs($usuario)->put(route('admin.dietas.update', $dieta), ['nombre' => 'X', 'texto' => 'X.'])->assertForbidden();
            $this->actingAs($usuario)->patch(route('admin.dietas.desactivar', $dieta))->assertForbidden();
        }
        expect(Dieta::count())->toBe(1)->and($dieta->fresh()->texto)->toBe('Texto de prueba.');
    });

    test('la matriz predefinida: Supervisión médica lo administra; Médico, Enfermería y Recepción no lo tienen', function () {
        expect(PerfilesPredefinidos::MATRIZ[PerfilesPredefinidos::SUPERVISION_MEDICA]['DIETAS'])->toBe(['VER', 'CREAR', 'EDITAR', 'DESACTIVAR']);
        foreach ([PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA, PerfilesPredefinidos::RECEPCION] as $perfil) {
            expect(PerfilesPredefinidos::MATRIZ[$perfil])->not->toHaveKey('DIETAS');
        }
    });

    test('nada se borra: no hay ruta de borrado', function () {
        expect(Route::has('admin.dietas.destroy'))->toBeFalse();
    });

    test('el texto es obligatorio y entra en una indicación (hasta 500 caracteres)', function () {
        $usuario = User::factory()->conPermisos(DIETAS_TODO)->create();

        $this->actingAs($usuario)->post(route('admin.dietas.store'), ['nombre' => 'Larga', 'texto' => str_repeat('a', Dieta::MAXIMO_TEXTO + 1)])
            ->assertSessionHasErrors('texto');
        $this->actingAs($usuario)->post(route('admin.dietas.store'), ['nombre' => 'Vacía', 'texto' => ''])->assertSessionHasErrors('texto');
        expect(Dieta::count())->toBe(0);
    });
});

describe('Insertar dieta en la indicación', function () {
    test('la pantalla de atención ofrece solo las dietas activas', function () {
        dietasCrear('Dieta ofrecida de prueba', 'Texto ofrecido.');
        dietasCrear('Dieta apagada de prueba', 'Texto apagado.', Estado::INACTIVO);
        $consulta = hcEnCurso();

        $this->actingAs($this->medico)->get(route('admin.consultas.atencion', $consulta))->assertOk()
            ->assertSee('Insertar dieta')->assertSee('Dieta ofrecida de prueba')->assertDontSee('Dieta apagada de prueba');
    });

    test('sin dietas activas no se muestra el botón', function () {
        dietasCrear('Dieta apagada de prueba', 'Texto apagado.', Estado::INACTIVO);

        $this->actingAs($this->medico)->get(route('admin.consultas.atencion', hcEnCurso()))->assertOk()
            ->assertDontSee('data-insertar-dieta', false);
    });

    test('la dieta copiada no cambia al editar ni al desactivar la dieta del catálogo', function () {
        $dieta = dietasCrear('Dieta liviana de prueba', 'Texto original de la dieta.');
        $consulta = hcEnCurso();
        // Lo que manda el formulario después de "Insertar dieta": el texto copiado, sin referencia a la dieta.
        hcAutoguardar($consulta, hcDatos(['con_indicaciones' => '1', 'indicaciones' => [
            ['uid' => 'n-1', 'id' => '', 'activo' => '1', 'tipo_indicacion_id' => '', 'descripcion' => "Comer liviano.\n".$dieta->texto],
        ]]))->assertOk();
        $supervision = User::factory()->conPermisos(DIETAS_TODO)->create();

        $this->actingAs($supervision)->put(route('admin.dietas.update', $dieta), ['nombre' => $dieta->nombre, 'texto' => 'Texto nuevo de la dieta.', 'estado_id' => $dieta->estado_id])->assertRedirect();
        $this->actingAs($supervision)->patch(route('admin.dietas.desactivar', $dieta))->assertRedirect();

        expect(Indicacion::sole()->descripcion)->toBe("Comer liviano.\nTexto original de la dieta.")
            ->and(PdfIndicaciones::html($consulta->fresh(), PdfIndicaciones::vigentes($consulta->fresh())))
            ->toContain('Texto original de la dieta.')->not->toContain('Texto nuevo de la dieta.');
        $this->actingAs($this->medico)->get(route('admin.consultas.atencion', $consulta))->assertOk()
            ->assertSee('Texto original de la dieta.')->assertDontSee('Dieta liviana de prueba');
    });
});
