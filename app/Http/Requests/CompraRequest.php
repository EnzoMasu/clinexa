<?php

namespace App\Http\Requests;

use App\Enums\CondicionCompra;
use App\Models\Estado;
use App\Models\TipoComprobanteCompra;
use App\Support\Cobros\Guaranies;
use App\Support\Compras\IvaCompra;
use App\Support\Compras\ValidarComprobante;
use App\Support\Fecha;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Alta y edición del borrador de una compra (docs/compras-gastos.md, FR-001/003/004/013/014). El permiso lo controla la ruta y la policy
 * (solo se edita el borrador). Proveedor, categoría y tipo de comprobante: activos. Fecha de la compra y del comprobante: no futuras.
 * Vencimiento: no anterior a la fecha de compra. Total mayor que cero. Contado: sin vencimiento ni anticipo. Crédito: vencimiento
 * obligatorio y 0 <= anticipo < total. Desglose por tasa solo si el tipo discrimina IVA (si no, solo el total). Lo que exige el
 * comprobante lo dicen las casillas del tipo (ValidarComprobante), nunca su nombre. Importes en guaraníes enteros ("150000" o "150.000").
 */
class CompraRequest extends FormRequest
{
    private const IMPORTES = ['monto_10', 'monto_5', 'monto_exento', 'total', 'anticipo'];

    public function authorize(): bool
    {
        return true;
    }

    /** Un importe numérico llega como texto (las reglas de Guaranies lo piden así). */
    protected function prepareForValidation(): void
    {
        $this->merge(collect(self::IMPORTES)->filter(fn (string $campo) => is_int($this->input($campo)))
            ->mapWithKeys(fn (string $campo) => [$campo => (string) $this->input($campo)])->all());
    }

    public function rules(): array
    {
        $activo = Estado::idDe(Estado::ACTIVO);

        return [
            'proveedor_id' => ['required', 'integer', Rule::exists('proveedores', 'id')->where('estado_id', $activo)],
            'categoria_gasto_id' => ['required', 'integer', Rule::exists('categorias_gasto', 'id')->where('estado_id', $activo)],
            'fecha' => ['required', ...Fecha::regla(hastaHoy: true)],
            'tipo_comprobante_id' => ['required', 'integer', Rule::exists('tipos_comprobante_compra', 'id')->where('estado_id', $activo)],
            'numero_comprobante' => ['nullable', 'string', 'max:50'],
            'timbrado' => ['nullable', 'string', 'max:20'],
            'fecha_comprobante' => ['nullable', ...Fecha::regla()],
            'monto_10' => Guaranies::regla(admiteCero: true, requerido: false),
            'monto_5' => Guaranies::regla(admiteCero: true, requerido: false),
            'monto_exento' => Guaranies::regla(admiteCero: true, requerido: false),
            'total' => Guaranies::regla(admiteCero: true, requerido: false),
            'condicion' => ['required', Rule::enum(CondicionCompra::class)],
            'vencimiento' => ['nullable', ...Fecha::regla()],
            'anticipo' => Guaranies::regla(admiteCero: true, requerido: false),
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $validator->errors()->isEmpty() ? $this->reglasCruzadas($validator) : null];
    }

    /** Lo que depende de varios campos: el tipo elegido, la condición y los importes. */
    private function reglasCruzadas(Validator $validator): void
    {
        $datos = $validator->validated();
        $tipo = TipoComprobanteCompra::findOrFail($datos['tipo_comprobante_id']);
        $errores = ValidarComprobante::errores($tipo, $datos['numero_comprobante'] ?? null, $datos['timbrado'] ?? null, Fecha::aIso($datos['fecha_comprobante'] ?? null));

        $importes = $this->importes($tipo, $datos);
        if ($importes['total'] === null || $importes['total'] < 1) {
            $errores[$tipo->discrimina_iva ? 'monto_10' : 'total'] = $tipo->discrimina_iva ? 'Indique al menos un monto: el total debe ser mayor que cero.' : 'El total debe ser mayor que cero.';
        }

        $fecha = Carbon::parse(Fecha::aIso($datos['fecha']));
        $anticipo = Guaranies::entero($datos['anticipo'] ?? null);
        if (CondicionCompra::from($datos['condicion'])->esCredito()) {
            if (($datos['vencimiento'] ?? null) === null) {
                $errores['vencimiento'] = 'Indique la fecha de vencimiento de la compra a crédito.';
            } elseif (Carbon::parse(Fecha::aIso($datos['vencimiento']))->lessThan($fecha)) {
                $errores['vencimiento'] = 'El vencimiento no puede ser anterior a la fecha de la compra ('.Fecha::mostrar($fecha).').';
            }
            if ($importes['total'] !== null && $importes['total'] > 0 && $anticipo >= $importes['total']) {
                $errores['anticipo'] = 'El anticipo debe ser menor al total de la compra.';
            }
        } else {
            if (($datos['vencimiento'] ?? null) !== null) {
                $errores['vencimiento'] = 'Una compra al contado no lleva vencimiento.';
            }
            if ($anticipo > 0) {
                $errores['anticipo'] = 'Una compra al contado no lleva anticipo.';
            }
        }

        foreach ($errores as $campo => $mensaje) {
            $validator->errors()->add($campo, $mensaje);
        }
    }

    /** @return array{monto_10: ?int, monto_5: ?int, monto_exento: ?int, iva_10: ?int, iva_5: ?int, total: ?int} */
    private function importes(TipoComprobanteCompra $tipo, array $datos): array
    {
        $monto = fn (string $campo) => Guaranies::entero($datos[$campo] ?? null);
        if ($tipo->discrimina_iva) {
            return IvaCompra::importes($tipo, $monto('monto_10'), $monto('monto_5'), $monto('monto_exento'), 0);
        }
        $total = ($datos['total'] ?? '') === '' ? null : $monto('total');

        return ['monto_10' => null, 'monto_5' => null, 'monto_exento' => null, 'iva_10' => null, 'iva_5' => null, 'total' => $total];
    }

    /**
     * Los datos para guardar la compra: fechas en ISO, el comprobante según las casillas del tipo, importes enteros (con desglose e IVA
     * solo si el tipo discrimina IVA) y la condición con su vencimiento y anticipo.
     *
     * @return array<string, mixed>
     */
    public function datosCompra(): array
    {
        $datos = $this->validated();
        $tipo = TipoComprobanteCompra::findOrFail($datos['tipo_comprobante_id']);
        $credito = CondicionCompra::from($datos['condicion'])->esCredito();

        return [
            'proveedor_id' => (int) $datos['proveedor_id'],
            'categoria_gasto_id' => (int) $datos['categoria_gasto_id'],
            'fecha' => Fecha::aIso($datos['fecha']),
            'tipo_comprobante_id' => $tipo->id,
            ...ValidarComprobante::guardables($tipo, $datos['numero_comprobante'] ?? null, $datos['timbrado'] ?? null, Fecha::aIso($datos['fecha_comprobante'] ?? null)),
            ...$this->importes($tipo, $datos),
            'condicion' => $datos['condicion'],
            'vencimiento' => $credito ? Fecha::aIso($datos['vencimiento']) : null,
            'anticipo' => $credito ? Guaranies::entero($datos['anticipo'] ?? null) : 0,
            'observaciones' => ($datos['observaciones'] ?? '') === '' ? null : $datos['observaciones'],
        ];
    }

    public function messages(): array
    {
        return [
            ...Fecha::mensajes('fecha'), ...Fecha::mensajes('fecha_comprobante'), ...Fecha::mensajes('vencimiento'),
            'proveedor_id.exists' => 'Elija un proveedor activo.',
            'categoria_gasto_id.exists' => 'Elija una categoría de gasto activa.',
            'tipo_comprobante_id.exists' => 'Elija un tipo de comprobante activo.',
            'fecha.before_or_equal' => 'La fecha de la compra no puede ser posterior a hoy.',
            'condicion.enum' => 'Elija si la compra es al contado o a crédito.',
            '*.regex' => Guaranies::MENSAJE,
        ];
    }

    public function attributes(): array
    {
        return [
            'proveedor_id' => 'proveedor', 'categoria_gasto_id' => 'categoría de gasto', 'fecha' => 'fecha de la compra',
            'tipo_comprobante_id' => 'tipo de comprobante', 'numero_comprobante' => 'número del comprobante', 'fecha_comprobante' => 'fecha del comprobante',
            'monto_10' => 'monto con IVA 10 %', 'monto_5' => 'monto con IVA 5 %', 'monto_exento' => 'monto exento', 'condicion' => 'condición',
        ];
    }
}
