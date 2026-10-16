<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Support\PdfClinico\GenerarPdf;
use App\Support\PdfClinico\PdfIndicaciones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF de triaje, de la consulta y de sus indicaciones generales (abierta o cerrada), al vuelo. Quién puede lo decide ConsultaPolicy::generarPdf; la ruta
 * exige EXPORTAR sobre PDF_CLINICO. Una consulta ANULADA no existe (404). Ver docs/pdf-clinico.md.
 */
class PdfClinicoController extends Controller
{
    public function triaje(Request $request, Consulta $consulta): Response
    {
        return $this->generar($request, $consulta, GenerarPdf::TRIAJE);
    }

    public function consulta(Request $request, Consulta $consulta): Response
    {
        return $this->generar($request, $consulta, GenerarPdf::CONSULTA);
    }

    /**
     * Indicaciones generales en hoja aparte: los mismos que pueden sacar el PDF de la consulta. Sin indicaciones vigentes no
     * hay PDF vacío: un aviso, sin registro en Auditoría (no se generó nada).
     */
    public function indicaciones(Consulta $consulta): Response
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('generarPdf', [$consulta, PdfIndicaciones::DOCUMENTO]);

        $indicaciones = PdfIndicaciones::vigentes($consulta);
        if ($indicaciones->isEmpty()) {
            return response()->view('admin.consultas.sin-indicaciones', ['consulta' => $consulta, 'mensaje' => PdfIndicaciones::SIN_INDICACIONES]);
        }

        return PdfIndicaciones::ejecutar($consulta, $indicaciones);
    }

    private function generar(Request $request, Consulta $consulta, string $documento): Response
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('generarPdf', [$consulta, $documento]);

        return GenerarPdf::ejecutar($request->user(), $consulta, $documento);
    }
}
