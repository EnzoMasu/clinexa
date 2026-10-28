<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Clinica;
use App\Models\Sucursal;
use App\Models\Timbrado;
use App\Support\Facturacion\EmitirFactura;
use App\Support\Facturacion\GestionTimbrados;
use App\Support\Fecha;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Timbrados (docs/facturacion.md), una pestaña de Facturas: el listado (VER sobre TIMBRADOS), "Cargar timbrado" (CREAR: si ya hay uno
 * vigente para ese punto, pasa a histórico) y "Pasar a histórico" (EDITAR). Gerencia y el Administrador. Nada se borra ni se edita.
 */
class TimbradoController extends Controller
{
    public function index(): View
    {
        [, $aviso] = EmitirFactura::timbradoParaEmitir();

        return view('admin.facturas.timbrados', [
            'timbrados' => Timbrado::query()->with(['sucursal', 'cargadoPor.persona'])->orderByRaw('historico_en IS NOT NULL')->orderByDesc('id')->paginate(20),
            'aviso' => $aviso,
            'puedeCargar' => Permisos::puedeRuta('admin.facturas.timbrados.create'),
            'puedeHistorico' => Permisos::puedeRuta('admin.facturas.timbrados.historico'),
        ]);
    }

    public function create(): View
    {
        $clinica = Clinica::actual();

        return view('admin.facturas.timbrado-form', [
            'clinica' => $clinica,
            'sucursales' => $clinica ? Sucursal::activos()->where('clinica_id', $clinica->id)->orderBy('id')->pluck('nombre', 'id')->all() : [],
            'principal' => Sucursal::principal()?->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'sucursal_id' => ['required', 'integer'],
            'numero' => ['required', 'regex:/^\d{8}$/'],
            'vigente_desde' => ['required', 'date_format:'.Fecha::FORMATO],
            'vigente_hasta' => ['required', 'date_format:'.Fecha::FORMATO],
            'establecimiento' => ['required', 'regex:/^\d{3}$/'],
            'punto_expedicion' => ['required', 'regex:/^\d{3}$/'],
            'numero_inicial' => ['required', 'integer', 'min:1', 'max:9999999'],
            'numero_final' => ['required', 'integer', 'min:1', 'max:9999999'],
        ], [
            'numero.regex' => 'El número de timbrado tiene 8 dígitos.',
            'establecimiento.regex' => 'El establecimiento tiene 3 dígitos (p. ej. 001).',
            'punto_expedicion.regex' => 'El punto de expedición tiene 3 dígitos (p. ej. 001).',
        ], ['numero' => 'número de timbrado', 'punto_expedicion' => 'punto de expedición', 'numero_inicial' => 'número inicial', 'numero_final' => 'número final']);
        $datos['vigente_desde'] = Fecha::aIso($datos['vigente_desde']);
        $datos['vigente_hasta'] = Fecha::aIso($datos['vigente_hasta']);

        try {
            $timbrado = GestionTimbrados::cargar($request->user(), $datos);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.facturas.timbrados')->with('status', "Timbrado N.º {$timbrado->numero} cargado: emite desde el {$timbrado->punto()}-".str_pad((string) $timbrado->numero_inicial, 7, '0', STR_PAD_LEFT).'.');
    }

    public function historico(Request $request, Timbrado $timbrado): RedirectResponse
    {
        try {
            GestionTimbrados::pasarAHistorico($request->user(), $timbrado);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Timbrado N.º {$timbrado->numero} pasado a histórico: ya no emite.");
    }
}
