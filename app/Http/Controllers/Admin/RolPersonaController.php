<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Support\BuscadorPersonas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * CRUD común de los roles de negocio sobre Persona (Paciente, Profesional, Proveedor, ...).
 *
 * Al crear se ELIGE una persona existente (activa, de un tipo permitido y que todavía no tiene
 * el rol) y se cargan solo los campos propios del rol; la persona no se cambia después. Sin
 * borrado: "Desactivar" pasa el rol a INACTIVO. Cada rol concreto define su modelo, sus reglas
 * y cómo se llama.
 *
 * Los parámetros de ruta ({paciente}, {profesional}, ...) llegan como id y se buscan con
 * registro(): así una sola clase sirve para los cinco.
 */
abstract class RolPersonaController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelo(): string;

    /** Prefijo de vistas y rutas, p. ej. 'pacientes' (admin.pacientes.*). */
    abstract protected function seccion(): string;

    /** Para los mensajes: 'Paciente', 'Propietario de equipo', ... */
    abstract protected function nombre(): string;

    /** Reglas de los campos propios del rol (sin persona_id ni estado_id). */
    abstract protected function reglas(?Model $registro): array;

    /** Columnas propias del rol que se suman al buscador (además de nombre y documento). */
    protected function columnasBusqueda(): array
    {
        return [];
    }

    /** Relaciones que se precargan en el listado (evita una consulta por fila). */
    protected function relacionesListado(): array
    {
        return ['persona.tipoDocumento'];
    }

    /** Datos extra para el formulario. */
    protected function datosFormulario(?Model $registro): array
    {
        return [];
    }

    /** Valores iniciales de un rol nuevo. */
    protected function nuevo(): Model
    {
        $modelo = $this->modelo();

        return new $modelo;
    }

    /** Guarda lo que no son columnas del rol (p. ej. especialidades del profesional). */
    protected function guardarRelaciones(Model $registro, array $datos): void {}

    /** Mensajes de validación propios del rol (p. ej. los de las fechas). */
    protected function mensajes(): array
    {
        return [];
    }

    /** Nombres de los campos en los mensajes de validación. */
    protected function atributos(): array
    {
        return [];
    }

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $modelo = $this->modelo();
        $tabla = (new $modelo)->getTable();

        $registros = $modelo::query()
            ->select("{$tabla}.*")
            ->join('personas', 'personas.id', '=', "{$tabla}.persona_id")
            ->with($this->relacionesListado())
            ->when($busqueda !== '', fn ($query) => $query->where(function ($query) use ($busqueda, $tabla) {
                $query->whereLike('personas.nro_documento', "{$busqueda}%")
                    ->orWhereLike('personas.apellidos', "%{$busqueda}%")
                    ->orWhereLike('personas.nombres', "%{$busqueda}%")
                    ->orWhereLike('personas.razon_social', "%{$busqueda}%");
                foreach ($this->columnasBusqueda() as $columna) {
                    $query->orWhereLike("{$tabla}.{$columna}", "%{$busqueda}%");
                }
            }))
            ->orderByRaw('COALESCE(personas.apellidos, personas.razon_social)')
            ->orderBy('personas.nombres')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.'.$this->seccion(), compact('registros', 'busqueda'));
    }

    public function create(): View
    {
        return view('admin.'.$this->seccion().'.form', [
            'registro' => $this->nuevo(),
            ...$this->datosFormulario(null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $modelo = $this->modelo();

        $datos = $request->validate([
            'persona_id' => ['required', 'integer', function (string $atributo, mixed $valor, \Closure $fail) use ($modelo) {
                if (! $modelo::personasDisponibles()->whereKey($valor)->exists()) {
                    $fail($this->motivoPersonaNoDisponible((int) $valor));
                }
            }],
            ...$this->reglas(null),
        ], $this->mensajes(), ['persona_id' => 'persona', ...$this->atributos()]);

        DB::transaction(function () use ($modelo, $datos) {
            $registro = $modelo::create($datos);
            $this->guardarRelaciones($registro, $datos);
        });

        return redirect()->route('admin.'.$this->seccion().'.index')->with('status', $this->nombre().' creado.');
    }

    public function edit(string $id): View
    {
        $registro = $this->registro($id);

        return view('admin.'.$this->seccion().'.form', [
            'registro' => $registro,
            ...$this->datosFormulario($registro),
        ]);
    }

    /**
     * La persona no se cambia una vez creado el rol: persona_id no se valida ni se toma del request.
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $registro = $this->registro($id);
        $modelo = $this->modelo();

        $datos = $request->validate([
            ...$this->reglas($registro),
            'estado_id' => $modelo::reglaEstado(),
        ], $this->mensajes(), $this->atributos());

        DB::transaction(function () use ($registro, $datos) {
            $registro->update($datos);
            $this->guardarRelaciones($registro, $datos);
        });

        return redirect()->route('admin.'.$this->seccion().'.index')->with('status', $this->nombre().' actualizado.');
    }

    public function desactivar(string $id): RedirectResponse
    {
        $this->registro($id)->desactivar();

        return redirect()->route('admin.'.$this->seccion().'.index')->with('status', $this->nombre().' desactivado.');
    }

    /**
     * Buscador de personas para el formulario de alta (JSON): solo las que pueden recibir el rol.
     * Mínimo de caracteres, tope y filtro: BuscadorPersonas (el mismo que usa Usuarios).
     */
    public function personasDisponibles(Request $request): JsonResponse
    {
        $modelo = $this->modelo();

        return BuscadorPersonas::responder($modelo::personasDisponibles(), (string) $request->query('q'));
    }

    protected function registro(string $id): Model
    {
        $modelo = $this->modelo();

        return $modelo::with('persona.tipoDocumento')->findOrFail($id);
    }

    private function motivoPersonaNoDisponible(int $personaId): string
    {
        $modelo = $this->modelo();
        $persona = Persona::find($personaId);
        $rol = mb_strtolower($this->nombre());

        return match (true) {
            $persona === null => 'La persona elegida no existe.',
            $modelo::where('persona_id', $personaId)->exists() => "Esa persona ya está registrada como {$rol}.",
            ! $persona->estaActivo() => 'La persona elegida está inactiva.',
            default => "Ese tipo de persona no puede registrarse como {$rol}.",
        };
    }
}
