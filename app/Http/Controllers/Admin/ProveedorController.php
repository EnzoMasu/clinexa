<?php

namespace App\Http\Controllers\Admin;

use App\Models\CategoriaProveedor;
use App\Models\Estado;
use App\Models\Proveedor;
use App\Models\RedSocialProveedor;
use App\Models\TipoRedSocial;
use App\Support\Enlace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProveedorController extends RolPersonaController
{
    /** Como mucho, por proveedor. Los contactos deshabilitados no cuentan (nunca se borran). */
    public const MAXIMO_CONTACTOS_ACTIVOS = 20;

    public const MAXIMO_REDES = 10;

    protected function modelo(): string
    {
        return Proveedor::class;
    }

    protected function seccion(): string
    {
        return 'proveedores';
    }

    protected function nombre(): string
    {
        return 'Proveedor';
    }

    /**
     * El sitio web sin esquema se completa con https://; en contactos y redes se recortan los
     * espacios (un teléfono o un correo vacíos quedan en null).
     */
    protected function prepararPedido(Request $request): void
    {
        $recortar = fn ($valor) => is_string($valor) && trim($valor) !== '' ? trim($valor) : null;

        $request->merge(['sitio_web' => Enlace::normalizarSitio($request->input('sitio_web'))]);

        if (is_array($request->input('contactos'))) {
            $request->merge(['contactos' => array_map(fn ($fila) => is_array($fila) ? [
                ...$fila,
                'nombre' => $recortar($fila['nombre'] ?? null),
                'apellido' => $recortar($fila['apellido'] ?? null),
                'telefono' => $recortar($fila['telefono'] ?? null),
                'correo' => $recortar($fila['correo'] ?? null),
            ] : $fila, $request->input('contactos'))]);
        }

        if (is_array($request->input('redes'))) {
            $request->merge(['redes' => array_map(fn ($fila) => is_array($fila) ? [
                ...$fila,
                'enlace' => $recortar($fila['enlace'] ?? null),
            ] : $fila, $request->input('redes'))]);
        }
    }

    /**
     * Condiciones comerciales, datos bancarios, sitio web, categorías (activas, o las que ya tenía
     * aunque después se hayan desactivado), contactos y redes sociales.
     */
    protected function reglas(?Model $registro): array
    {
        $yaAsignadas = $registro ? $registro->categorias->modelKeys() : [];
        $proveedorId = $registro?->getKey() ?? 0;
        // Tipo de red que ya tiene cada red guardada (un tipo inactivo solo vale en la fila que ya lo tenía).
        $tiposGuardados = $registro ? $registro->redesSociales()->pluck('tipo_red_social_id', 'id')->all() : [];

        return [
            'condiciones_comerciales' => ['nullable', 'string', 'max:5000'],
            'datos_bancarios' => ['nullable', 'string', 'max:255'],
            'sitio_web' => ['nullable', 'string', 'max:255', function (string $atributo, mixed $valor, \Closure $fail) {
                if (Enlace::tieneEsquemaNoPermitido($valor) || ! Enlace::esUrlWeb($valor)) {
                    $fail('El sitio web debe ser una dirección que empiece con http:// o https://.');
                } elseif (! Enlace::tieneDominio($valor)) {
                    $fail('El sitio web no tiene un dominio válido (por ejemplo, clinica.com.py).');
                }
            }],

            'con_categorias' => ['nullable'],
            'categorias' => ['array'],
            'categorias.*' => ['integer', 'distinct', Rule::exists('categorias_proveedor', 'id')->where(
                fn ($query) => $query->where('estado_id', Estado::idDe(Estado::ACTIVO))
                    ->when($yaAsignadas, fn ($query) => $query->orWhereIn('id', $yaAsignadas))
            )],

            'con_contactos' => ['nullable'],
            'contactos' => ['array', function (string $atributo, mixed $filas, \Closure $fail) {
                $activos = collect($filas)->filter(fn ($fila) => is_array($fila) && filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN));
                if ($activos->count() > self::MAXIMO_CONTACTOS_ACTIVOS) {
                    $fail('Un proveedor puede tener como máximo '.self::MAXIMO_CONTACTOS_ACTIVOS.' contactos activos. Deshabilite alguno.');
                }
            }],
            'contactos.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('contactos_proveedor', 'id')->where('proveedor_id', $proveedorId)],
            'contactos.*.nombre' => ['required', 'string', 'max:100'],
            'contactos.*.apellido' => ['required', 'string', 'max:100'],
            'contactos.*.telefono' => ['nullable', 'string', 'max:20', 'regex:/^(?=.*\d)[0-9 +\-()]+$/', 'required_without:contactos.*.correo'],
            'contactos.*.correo' => ['nullable', 'string', 'email', 'max:100'],
            'contactos.*.activo' => ['nullable', 'boolean'],

            'con_redes' => ['nullable'],
            'redes' => ['array', 'max:'.self::MAXIMO_REDES, function (string $atributo, mixed $filas, \Closure $fail) {
                $claves = collect($filas)->filter(fn ($fila) => is_array($fila) && filled($fila['enlace'] ?? null))
                    ->map(fn ($fila) => ($fila['tipo_red_social_id'] ?? '').'|'.mb_strtolower($fila['enlace']));
                if ($claves->duplicates()->isNotEmpty()) {
                    $fail('Hay redes repetidas: el mismo tipo con el mismo enlace.');
                }
            }],
            'redes.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('redes_sociales_proveedor', 'id')->where('proveedor_id', $proveedorId)],
            'redes.*.tipo_red_social_id' => ['required', 'integer', function (string $atributo, mixed $valor, \Closure $fail) use ($tiposGuardados) {
                $indice = explode('.', $atributo)[1];
                $idFila = request()->input("redes.{$indice}.id");
                $tipo = TipoRedSocial::find($valor);
                $yaLoTenia = $idFila !== null && (int) ($tiposGuardados[(int) $idFila] ?? 0) === (int) $valor;

                if (! $tipo || (! $tipo->estaActivo() && ! $yaLoTenia)) {
                    $fail('El tipo de red elegido no existe o está inactivo.');
                }
            }],
            'redes.*.enlace' => ['required', 'string', 'max:255', function (string $atributo, mixed $valor, \Closure $fail) {
                if (Enlace::tieneEsquemaNoPermitido($valor)) {
                    $fail('El enlace solo puede ser una dirección http:// o https://, un @usuario o un número.');
                } elseif (preg_match('#^https?://#i', $valor) && ! Enlace::esUrlWeb($valor)) {
                    $fail('El enlace no es una dirección web válida.');
                }
            }],
        ];
    }

    protected function mensajes(): array
    {
        return [
            'contactos.*.telefono.regex' => 'El teléfono del contacto solo puede tener números, espacios y los signos + - ( ).',
            'contactos.*.telefono.required_without' => 'Indique al menos un teléfono o un correo electrónico para el contacto.',
            'redes.max' => 'Un proveedor puede tener como máximo '.self::MAXIMO_REDES.' redes sociales.',
        ];
    }

    protected function atributos(): array
    {
        return [
            'condiciones_comerciales' => 'condiciones comerciales',
            'datos_bancarios' => 'datos bancarios',
            'sitio_web' => 'sitio web',
            'categorias.*' => 'categoría',
            'contactos.*.nombre' => 'nombre del contacto',
            'contactos.*.apellido' => 'apellido del contacto',
            'contactos.*.telefono' => 'teléfono del contacto',
            'contactos.*.correo' => 'correo electrónico del contacto',
            'redes.*.tipo_red_social_id' => 'tipo de red',
            'redes.*.enlace' => 'enlace o usuario',
        ];
    }

    protected function relacionesListado(): array
    {
        return ['persona.tipoDocumento', 'categorias'];
    }

    /** Cantidad de contactos activos de cada proveedor, en la misma consulta del listado. */
    protected function conteosListado(): array
    {
        return ['contactos as contactos_activos_count' => fn ($query) => $query->where('activo', true)];
    }

    /** Además: por nombre, apellido, correo o teléfono de un contacto activo, y por el sitio web. */
    protected function ampliarBusqueda(Builder $query, string $busqueda, string $tabla): void
    {
        $query->orWhereLike("{$tabla}.sitio_web", "%{$busqueda}%")
            ->orWhereExists(fn ($consulta) => $consulta->select(DB::raw(1))
                ->from('contactos_proveedor')
                ->whereColumn('contactos_proveedor.proveedor_id', "{$tabla}.id")
                ->where('contactos_proveedor.activo', true)
                ->where(fn ($consulta) => $consulta
                    ->whereLike('contactos_proveedor.nombre', "%{$busqueda}%")
                    ->orWhereLike('contactos_proveedor.apellido', "%{$busqueda}%")
                    ->orWhereLike('contactos_proveedor.correo', "%{$busqueda}%")
                    ->orWhereLike('contactos_proveedor.telefono', "%{$busqueda}%")));
    }

    protected function datosFormulario(?Model $registro): array
    {
        $yaAsignadas = $registro ? $registro->categorias->modelKeys() : [];

        return [
            'categorias' => CategoriaProveedor::query()
                ->where(fn ($query) => $query->activos()->orWhereIn('id', $yaAsignadas))
                ->orderBy('nombre')
                ->get(),
            'contactos' => $registro ? $registro->contactos()->orderBy('id')->get() : collect(),
            'redes' => $registro ? $registro->redesSociales()->with('tipoRedSocial')->orderBy('id')->get() : collect(),
            // Activos para cualquier fila; los inactivos solo aparecen en la fila que ya los tenía.
            'tiposRedActivos' => TipoRedSocial::activos()->orderBy('nombre')->pluck('nombre', 'id'),
        ];
    }

    /**
     * Categorías, contactos y redes. Cada lista se toca solo si el formulario trae su marca (sin
     * JavaScript no se dibujan las filas y no hay que interpretar eso como "borrar todo").
     */
    protected function guardarRelaciones(Model $registro, array $datos): void
    {
        if (! empty($datos['con_categorias'])) {
            $registro->sincronizarAuditado('categorias', array_map('intval', $datos['categorias'] ?? []));
        }

        if (! empty($datos['con_contactos'])) {
            $this->guardarContactos($registro, $datos['contactos'] ?? []);
        }

        if (! empty($datos['con_redes'])) {
            $this->guardarRedes($registro, $datos['redes'] ?? []);
        }
    }

    /**
     * Actualiza los contactos del formulario y crea los nuevos. Nunca borra: un contacto guardado que
     * no viene en el envío queda como estaba. La auditoría registra la lista de antes y la de después.
     */
    private function guardarContactos(Proveedor $proveedor, array $filas): void
    {
        $proveedor->auditarRelacion('contactos', function () use ($proveedor, $filas) {
            $existentes = $proveedor->contactos()->get()->keyBy('id');

            foreach ($filas as $fila) {
                $valores = [
                    'nombre' => $fila['nombre'],
                    'apellido' => $fila['apellido'],
                    'telefono' => $fila['telefono'] ?? null,
                    'correo' => $fila['correo'] ?? null,
                    'activo' => filter_var($fila['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ];

                $existente = isset($fila['id']) ? $existentes->get((int) $fila['id']) : null;
                $existente ? $existente->fill($valores)->save() : $proveedor->contactos()->create($valores);
            }
        });
    }

    /** Deja exactamente las redes del formulario: actualiza, crea y quita las que ya no están. */
    private function guardarRedes(Proveedor $proveedor, array $filas): void
    {
        $proveedor->auditarRelacion('redesSociales', function () use ($proveedor, $filas) {
            $existentes = $proveedor->redesSociales()->get()->keyBy('id');
            $conservadas = [];

            foreach ($filas as $fila) {
                $valores = ['tipo_red_social_id' => (int) $fila['tipo_red_social_id'], 'enlace' => $fila['enlace']];
                $existente = isset($fila['id']) ? $existentes->get((int) $fila['id']) : null;

                if ($existente) {
                    $existente->fill($valores)->save();
                    $conservadas[] = $existente->id;
                } else {
                    $conservadas[] = $proveedor->redesSociales()->create($valores)->id;
                }
            }

            RedSocialProveedor::where('proveedor_id', $proveedor->id)->whereNotIn('id', $conservadas)->delete();
        });
    }
}
