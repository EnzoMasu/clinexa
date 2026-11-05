<?php

namespace App\Support\Grilla;

use App\Models\Estado;
use App\Support\Fecha;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orden y filtros por columna de un listado (docs/grillas.md). Todo se resuelve en la consulta: el
 * navegador solo manda "orden", "dir" y "f[clave]" en la URL. Solo cuentan las columnas declaradas
 * acá (lista blanca): cualquier otra clave, dirección o valor que no corresponda se ignora.
 *
 * Uso en el controlador:
 *   $grilla = Grilla::de($request)->texto('nombre', 'Nombre', 'paises.nombre')->estado('estado', 'Estado', 'paises.estado_id');
 *   $grilla->aplicar($query, fn ($query) => $query->orderBy('nombre'))->paginate(20)->withQueryString();
 * y en la vista: <x-admin.table :grilla="$grilla" :paginator="...">.
 */
final class Grilla
{
    /** Id del formulario del listado (x-admin.listado) al que pertenecen los campos de la fila de filtros. */
    public const FORMULARIO = 'filtros-grilla';

    /** Atributo de la request que marca que el listado usa una grilla (para RecordarGrilla). */
    public const ATRIBUTO = 'grilla';

    /** @var array<string, Columna> */
    private array $columnas = [];

    private function __construct(private readonly Request $request)
    {
        $request->attributes->set(self::ATRIBUTO, true);
    }

    public static function de(Request $request): self
    {
        return new self($request);
    }

    /** Texto: el filtro busca lo escrito en cualquier parte (sin distinguir mayúsculas). */
    public function texto(string $clave, string $etiqueta, string|Expression $columna, string|Expression|Closure|false|null $orden = null, string|Expression|Closure|false|null $filtro = null): self
    {
        return $this->agregar(new Columna($clave, $etiqueta, Columna::TEXTO, $this->o($orden, $columna), $this->o($filtro, $columna)));
    }

    /** Número o importe: el filtro compara el valor exacto (acepta 1.500 o 1500; la coma es decimal). */
    public function numero(string $clave, string $etiqueta, string|Expression $columna, string|Expression|Closure|false|null $orden = null, string|Expression|Closure|false|null $filtro = null): self
    {
        return $this->agregar(new Columna($clave, $etiqueta, Columna::NUMERO, $this->o($orden, $columna), $this->o($filtro, $columna)));
    }

    /**
     * Fecha: el filtro es un rango desde/hasta (dd/mm/aaaa, ambos incluidos). "utc": la columna es un
     * instante guardado en UTC (el día se toma en la hora local de la clínica).
     */
    public function fecha(string $clave, string $etiqueta, string|Expression $columna, bool $utc = false, string|Expression|Closure|false|null $orden = null): self
    {
        return $this->agregar(new Columna($clave, $etiqueta, Columna::FECHA, $this->o($orden, $columna), $columna, utc: $utc));
    }

    /**
     * Desplegable: valor => etiqueta. Sin "filtro", compara la columna con el valor elegido; con una función
     * fn ($query, $valor), la aplica (el valor ya es una de las opciones).
     *
     * "diferida": la lista completa solo viene al dibujar la página entera (en la búsqueda en vivo el navegador conserva
     * el desplegable que ya tiene). Entonces el valor no se valida contra las opciones: lo valida "filtro".
     *
     * @param  array<string|int, string>  $opciones
     */
    public function opciones(string $clave, string $etiqueta, string|Expression $columna, array $opciones, string|Expression|Closure|false|null $orden = null, Closure|false|null $filtro = null, bool $diferida = false): self
    {
        return $this->agregar(new Columna($clave, $etiqueta, Columna::OPCIONES, $this->o($orden, $columna), $this->o($filtro, $columna), $opciones, diferida: $diferida));
    }

    /**
     * Estado (tabla estados): desplegable con los estados indicados, o con los permitidos del modelo
     * (TieneEstado::estadosPermitidos) si se pasa su clase, o código => etiqueta (las ETIQUETAS del modelo);
     * por defecto ACTIVO e INACTIVO. Ordena por el nombre del estado.
     *
     * @param  list<string>|array<string, string>|class-string  $codigos
     */
    public function estado(string $clave, string $etiqueta, string $columna, array|string $codigos = [Estado::ACTIVO, Estado::INACTIVO]): self
    {
        $opciones = match (true) {
            is_string($codigos) => $codigos::estadosPermitidos()->pluck('nombre', 'codigo')->all(),
            ! array_is_list($codigos) => $codigos,
            default => Estado::query()->whereIn('codigo', $codigos)->pluck('nombre', 'codigo')
                ->sortBy(fn ($nombre, $codigo) => array_search($codigo, $codigos, true))->all(),
        };
        $opciones = $opciones ?: Estado::query()->whereIn('codigo', [Estado::ACTIVO, Estado::INACTIVO])->orderBy('id')->pluck('nombre', 'codigo')->all();

        return $this->opciones($clave, $etiqueta, $columna, $opciones,
            orden: fn ($query) => $query->select('nombre')->from('estados')->whereColumn('estados.id', $columna),
            filtro: fn ($query, string $codigo) => $query->where($columna, Estado::idDe($codigo)));
    }

    /**
     * Texto de un registro relacionado (p. ej. el nombre de la sucursal por consultorios.sucursal_id): ordena
     * y filtra con subconsultas, sin joins.
     */
    public function textoDe(string $clave, string $etiqueta, string $fk, string $tabla, string $campo = 'nombre'): self
    {
        return $this->texto($clave, $etiqueta, $fk,
            orden: fn ($query) => $query->select($campo)->from($tabla)->whereColumn("{$tabla}.id", $fk),
            filtro: fn ($query, string $valor) => $query->whereIn($fk, fn ($sub) => $sub->select('id')->from($tabla)->whereLike($campo, "%{$valor}%")));
    }

    /**
     * Nombre de una persona ("Apellidos, Nombres" o la razón social) por la columna con su id. "via": tabla de
     * un rol (proveedores, profesionales...) cuando la columna apunta al rol y no a la persona.
     */
    public function persona(string $clave, string $etiqueta, string $columna, ?string $via = null): self
    {
        $nombre = "COALESCE(personas.apellidos || ', ' || personas.nombres, personas.apellidos, personas.razon_social, personas.nombres)";

        return $this->texto($clave, $etiqueta, $columna,
            orden: fn ($query) => $this->dePersona($query->selectRaw($nombre), $columna, $via),
            filtro: fn ($query, string $valor) => $query->whereIn($columna, fn ($sub) => $this->idsDePersonas($sub, $via, fn ($personas) => $personas
                ->whereLike('personas.apellidos', "%{$valor}%")->orWhereLike('personas.nombres', "%{$valor}%")
                ->orWhereLike('personas.razon_social', "%{$valor}%")->orWhereLike(DB::raw($nombre), "%{$valor}%"))));
    }

    /**
     * Nombre de la persona cuando la tabla personas (o su alias "t") ya está unida a la consulta: ordena por
     * "Apellidos, Nombres" o la razón social, y el filtro busca en apellidos, nombres y razón social.
     */
    public function nombrePersona(string $clave, string $etiqueta, string $t = 'personas'): self
    {
        $nombre = "COALESCE({$t}.apellidos || ', ' || {$t}.nombres, {$t}.apellidos, {$t}.razon_social, {$t}.nombres)";

        return $this->texto($clave, $etiqueta, "{$t}.apellidos", orden: DB::raw($nombre),
            filtro: fn ($query, $valor) => $query->where(fn ($query) => $query
                ->whereLike("{$t}.apellidos", "%{$valor}%")->orWhereLike("{$t}.nombres", "%{$valor}%")
                ->orWhereLike("{$t}.razon_social", "%{$valor}%")->orWhereLike(DB::raw("{$t}.apellidos || ', ' || {$t}.nombres"), "%{$valor}%")));
    }

    /** Documento (número o RUC) de la persona unida a la consulta, como nombrePersona(). */
    public function documentoPersona(string $clave, string $etiqueta, string $t = 'personas'): self
    {
        return $this->texto($clave, $etiqueta, "{$t}.nro_documento", orden: DB::raw("COALESCE({$t}.nro_documento, {$t}.ruc)"),
            filtro: fn ($query, $valor) => $query->where(fn ($query) => $query
                ->whereLike("{$t}.nro_documento", "%{$valor}%")->orWhereLike("{$t}.ruc", "%{$valor}%")));
    }

    /** Documento de una persona (número de documento o RUC), como persona(). */
    public function documento(string $clave, string $etiqueta, string $columna, ?string $via = null): self
    {
        return $this->texto($clave, $etiqueta, $columna,
            orden: fn ($query) => $this->dePersona($query->selectRaw('COALESCE(personas.nro_documento, personas.ruc)'), $columna, $via),
            filtro: fn ($query, string $valor) => $query->whereIn($columna, fn ($sub) => $this->idsDePersonas($sub, $via, fn ($personas) => $personas
                ->whereLike('personas.nro_documento', "%{$valor}%")->orWhereLike('personas.ruc', "%{$valor}%"))));
    }

    /** Para intercalar columnas armadas en otro lado: ->pipe(fn (Grilla $grilla) => $this->columnasDePersona($grilla)). */
    public function pipe(Closure $paso): self
    {
        return $paso($this);
    }

    /** Columna que solo se muestra (ni orden ni filtro): para datos que no salen de la consulta. */
    public function fija(string $clave, string $etiqueta): self
    {
        return $this->agregar(new Columna($clave, $etiqueta, Columna::TEXTO, null, null));
    }

    /**
     * Aplica los filtros y el orden pedidos. Sin orden pedido (o con uno no permitido), "porDefecto" pone el
     * orden habitual del listado. Con orden pedido, se desempata por la clave primaria (paginación estable).
     */
    public function aplicar(Builder $query, ?Closure $porDefecto = null): Builder
    {
        foreach ($this->columnas as $columna) {
            if ($columna->filtrable()) {
                $this->filtrar($query, $columna, $this->valor($columna->clave));
            }
        }

        $columna = $this->ordenada();
        if ($columna === null) {
            return $porDefecto ? ($porDefecto($query) ?? $query) : $query;
        }

        $query->orderBy($columna->orden, $this->direccion());
        if (method_exists($query, 'getModel')) {
            $query->orderBy($query->getModel()->getQualifiedKeyName(), $this->direccion());
        }

        return $query;
    }

    /** @return array<string, Columna> */
    public function columnas(): array
    {
        return $this->columnas;
    }

    /** La columna por la que se ordena, si la pedida es válida. */
    public function ordenada(): ?Columna
    {
        $clave = $this->request->query('orden');
        $columna = is_string($clave) ? ($this->columnas[$clave] ?? null) : null;

        return $columna?->ordenable() ? $columna : null;
    }

    public function direccion(): string
    {
        return $this->request->query('dir') === 'desc' ? 'desc' : 'asc';
    }

    /** Lo que se ve en el campo del filtro: texto, o ['desde' => ..., 'hasta' => ...] en las fechas. */
    public function valor(string $clave): string|array
    {
        $columna = $this->columnas[$clave] ?? null;
        $valor = ($this->request->query('f') ?? [])[$clave] ?? null;
        $texto = fn (mixed $v) => is_string($v) ? mb_substr(trim($v), 0, 100) : '';

        if ($columna?->tipo === Columna::FECHA) {
            return ['desde' => $texto(is_array($valor) ? ($valor['desde'] ?? null) : null), 'hasta' => $texto(is_array($valor) ? ($valor['hasta'] ?? null) : null)];
        }

        return $texto($valor);
    }

    public function hayFiltros(): bool
    {
        foreach ($this->columnas as $columna) {
            $valor = $this->valor($columna->clave);
            if ($columna->filtrable() && (is_array($valor) ? implode('', $valor) !== '' : $valor !== '')) {
                return true;
            }
        }

        return false;
    }

    /** URL para ordenar por la columna: asc, y desc si ya estaba en asc. Vuelve a la página 1. */
    public function urlOrden(string $clave): string
    {
        $dir = $this->ordenada()?->clave === $clave && $this->direccion() === 'asc' ? 'desc' : 'asc';

        return $this->request->fullUrlWithQuery(['orden' => $clave, 'dir' => $dir, 'page' => null]);
    }

    /** El listado sin filtros por columna ni orden (conserva la búsqueda general y los filtros de arriba). */
    public function urlLimpiar(): string
    {
        return $this->request->fullUrlWithoutQuery(['f', 'orden', 'dir', 'page']);
    }

    private function filtrar(Builder $query, Columna $columna, string|array $valor): void
    {
        if ($columna->filtro instanceof Closure && $columna->tipo !== Columna::FECHA) {
            $valor = $this->normalizar($columna, $valor);
            if ($valor !== null) {
                ($columna->filtro)($query, $valor);
            }

            return;
        }

        match ($columna->tipo) {
            Columna::TEXTO => $valor !== '' && $query->whereLike($columna->filtro, "%{$valor}%"),
            Columna::NUMERO => ($numero = $this->normalizar($columna, $valor)) !== null && $query->where($columna->filtro, $numero),
            Columna::OPCIONES => ($opcion = $this->normalizar($columna, $valor)) !== null && $query->where($columna->filtro, $opcion),
            Columna::FECHA => $this->filtrarFecha($query, $columna, $valor),
        };
    }

    /** El valor listo para comparar, o null si está vacío o no es válido para la columna. */
    private function normalizar(Columna $columna, string|array $valor): mixed
    {
        if (is_array($valor) || $valor === '') {
            return null;
        }

        return match ($columna->tipo) {
            Columna::NUMERO => preg_match('/^-?\d+(,\d+)?$/', $numero = str_replace(['.', ' '], '', $valor)) ? str_replace(',', '.', $numero) : null,
            Columna::OPCIONES => $columna->diferida || array_key_exists($valor, $columna->opciones) ? $valor : null,
            default => $valor,
        };
    }

    private function filtrarFecha(Builder $query, Columna $columna, array $valor): void
    {
        $desde = $this->iso($valor['desde']);
        $hasta = $this->iso($valor['hasta']);

        if ($columna->utc) {
            $zona = config('app.zona_horaria_local');
            $desde && $query->where($columna->filtro, '>=', Carbon::parse($desde, $zona)->startOfDay()->utc());
            $hasta && $query->where($columna->filtro, '<', Carbon::parse($hasta, $zona)->addDay()->startOfDay()->utc());

            return;
        }

        $desde && $query->whereDate($columna->filtro, '>=', $desde);
        $hasta && $query->whereDate($columna->filtro, '<=', $hasta);
    }

    private function iso(string $texto): ?string
    {
        return preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) && Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO)
            && Carbon::createFromFormat(Fecha::FORMATO, $texto)->format(Fecha::FORMATO) === $texto ? Fecha::aIso($texto) : null;
    }

    /** Subconsulta de orden: la persona de la fila (directa o por la tabla del rol). */
    private function dePersona($query, string $columna, ?string $via)
    {
        return $via
            ? $query->from('personas')->join($via, "{$via}.persona_id", '=', 'personas.id')->whereColumn("{$via}.id", $columna)
            : $query->from('personas')->whereColumn('personas.id', $columna);
    }

    /** Subconsulta de filtro: ids (de persona, o del rol con "via") de las personas que cumplen la condición. */
    private function idsDePersonas($sub, ?string $via, Closure $condicion)
    {
        return $via
            ? $sub->select("{$via}.id")->from($via)->join('personas', 'personas.id', '=', "{$via}.persona_id")->where($condicion)
            : $sub->select('personas.id')->from('personas')->where($condicion);
    }

    /** null: la columna de la grilla; false: ninguno; otro: ese. */
    private function o(mixed $valor, mixed $columna): mixed
    {
        return $valor === false ? null : ($valor ?? $columna);
    }

    private function agregar(Columna $columna): self
    {
        $this->columnas[$columna->clave] = $columna;

        return $this;
    }
}
