<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ciudad;
use App\Models\Clinica;
use App\Models\Sucursal;
use App\Rules\RucValido;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Datos de la clínica como contribuyente emisor (docs/facturacion.md), sin entrada en el menú (desde Sucursales y desde Facturas →
 * Timbrados): verlos (VER sobre DATOS_FISCALES) y cargarlos o cambiarlos (EDITAR: Gerencia y el Administrador). Una sola fila; nada se
 * borra. Al cargarla, las sucursales sin clínica pasan a ser suyas. Las facturas ya emitidas no cambian (tienen su snapshot).
 */
class ClinicaController extends Controller
{
    public const LOGO_MAXIMO_KB = 1024;

    public function edit(): View
    {
        return view('admin.clinica.form', [
            'clinica' => Clinica::actual() ?? new Clinica(['tipo_contribuyente' => Clinica::SOCIEDAD]),
            'puedeEditar' => Permisos::puedeRuta('admin.clinica.update'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $clinica = Clinica::actual();
        $datos = $request->validate([
            'tipo_contribuyente' => ['required', Rule::in(array_keys(Clinica::TIPOS))],
            'razon_social' => ['required', 'string', 'max:200'],
            'nombre_fantasia' => ['nullable', 'string', 'max:200'],
            'ruc' => ['required', 'string', 'max:12', new RucValido, Rule::unique('clinicas', 'ruc')->ignore($clinica)],
            'actividad_economica' => ['required', 'string', 'max:200'],
            'direccion_fiscal' => ['required', 'string', 'max:200'],
            'ciudad_id' => Ciudad::reglaOpcional($clinica?->ciudad_id),
            'telefono' => ['nullable', 'string', 'max:20'],
            'correo' => ['nullable', 'email', 'max:150'],
            'logotipo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:'.self::LOGO_MAXIMO_KB],
        ], [], ['tipo_contribuyente' => 'tipo de contribuyente', 'razon_social' => 'nombre o razón social', 'nombre_fantasia' => 'nombre de fantasía',
            'actividad_economica' => 'actividad económica', 'direccion_fiscal' => 'dirección fiscal', 'ciudad_id' => 'ciudad']);
        unset($datos['logotipo']);
        // El logotipo anterior no se borra: queda en el disco (y su ruta, en Auditoría).
        if ($request->hasFile('logotipo')) {
            $datos['logotipo'] = $request->file('logotipo')->store('clinica', 'local');
        }

        DB::transaction(function () use ($clinica, $datos) {
            if ($clinica === null) {
                $clinica = Clinica::create($datos);
                foreach (Sucursal::whereNull('clinica_id')->get() as $sucursal) {
                    $sucursal->update(['clinica_id' => $clinica->id]);
                }
            } else {
                $clinica->update($datos);
            }
        });

        return redirect()->route('admin.clinica.edit')->with('status', 'Datos de la clínica guardados. Las facturas ya emitidas no cambian.');
    }
}
