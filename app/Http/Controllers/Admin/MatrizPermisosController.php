<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Support\PerfilesPredefinidos;
use App\Support\PermisosDePerfil;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Matriz de permisos (Seguridad). Dos pestañas:
 * - Editar (VER sobre PERFILES_ACCESO para mirar, EDITAR para guardar): un perfil a la vez, módulos en filas
 *   agrupados como en el menú, una casilla por acción con efecto. Guardar pasa por una confirmación con la lista
 *   de cambios (y una confirmación reforzada para lo delicado) y se aplica en una transacción, con UN evento de
 *   auditoría. Funciona sin JavaScript; el JS solo agrega atajos y el buscador.
 * - Comparar: todos los perfiles en columnas, solo lectura, marcando dónde un predefinido difiere de su matriz.
 *
 * La lógica de permisos (formulario, escalada, huella, guardado) es la de PermisosDePerfil, la misma que usa
 * Perfiles de acceso. Acá no se crean ni se desactivan perfiles. PERFILES_ACCESO es sensible: abrir la pantalla
 * registra VER (middleware de permisos).
 */
class MatrizPermisosController extends Controller
{
    /** Módulos por grupo, como en el menú (resources/views/layouts/navigation.blade.php). */
    public const GRUPOS = [
        'Seguridad' => ['USUARIOS', 'PERFILES_ACCESO', 'AUDITORIA'],
        'Personas y Roles' => ['PERSONAS', 'PACIENTES', 'PROFESIONALES', 'PERSONAL_SALUD', 'PROVEEDORES', 'CATEGORIAS_PROVEEDOR', 'TIPOS_RED_SOCIAL', 'RESPONSABLES_PAGO'],
        'Agenda' => ['TURNOS', 'DISPONIBILIDAD', 'CONSULTORIOS', 'ORIGENES_TURNO', 'CONSTANCIAS_RETIRO', 'CONSTANCIAS_ESTADO'],
        'Clínica' => ['HISTORIA_CLINICA', 'PREPARACION', 'RECETAS', 'CORRECCIONES_HC', 'PDF_CLINICO', 'REPOSO_MEDICO', 'ORDENES_ESTUDIO'],
        'Económico' => ['TARIFAS', 'PRESTACIONES'],
        'Catálogos' => ['ESPECIALIDADES', 'SUCURSALES', 'TIPOS_DOCUMENTO', 'PROCEDIMIENTOS', 'MEDIOS_PAGO', 'CATEGORIAS_GASTO', 'CIE10',
            'TIPOS_BLOQUE_ANAMNESIS', 'TIPOS_INDICACION', 'DIETAS', 'ESTUDIOS', 'CARGOS_SALUD', 'PARAMETROS_CLINICOS', 'GEOGRAFIA'],
    ];

    /** Grupo de los módulos que no están en el menú (p. ej. MODULOS_SISTEMA, sin pantalla). */
    public const OTROS = 'Otros';

    public const ADMINISTRADOR_FIJO = 'El perfil Administrador no se edita: tiene siempre todos los permisos.';

    public const FALTA_CONFIRMAR = 'Marque la casilla de confirmación: estos permisos son delicados.';

    public function index(Request $request): View
    {
        // Una sola carga para todo (sin N+1): perfiles con sus permisos y el módulo de cada uno, y los módulos.
        $perfiles = PerfilAcceso::with('permisos.moduloSistema')->orderByDesc('predefinido')->orderBy('nombre')->get();
        $modulos = ModuloSistema::orderBy('nombre')->get();

        if ($request->query('vista') === 'comparar') {
            return view('admin.matriz-permisos.comparar', $this->datosComparar($perfiles, $modulos));
        }

        $perfil = $perfiles->firstWhere('id', (int) $request->query('perfil'))
            ?? $perfiles->first(fn (PerfilAcceso $perfil) => ! $perfil->esAdministrador())
            ?? $perfiles->first();
        $predefinida = $perfil && $request->boolean('predefinido') && isset(PerfilesPredefinidos::MATRIZ[$perfil->codigo]);

        return view('admin.matriz-permisos.index', [
            'perfiles' => $perfiles,
            'perfil' => $perfil,
            'grupos' => $this->agrupar($modulos),
            'tildados' => match (true) {
                $perfil === null => [],
                old('permisos') !== null => $this->clavesDeModulos($modulos, (array) old('permisos')),
                $predefinida => self::clavesPredefinidas($perfil->codigo),
                default => PermisosDePerfil::claves($perfil),
            },
            'predefinida' => $predefinida,
            'huella' => $perfil ? PermisosDePerfil::huella($perfil) : null,
            'puedeEditar' => $perfil && ! $perfil->esAdministrador() && $request->user()->tienePermiso('PERFILES_ACCESO', 'EDITAR'),
        ]);
    }

    /**
     * Guardar: sin "confirmado", muestra la confirmación con la lista de cambios (nada se guarda); con
     * "confirmado" (y "confirmar_delicados" si hace falta), aplica todo en una transacción.
     */
    public function update(Request $request, PerfilAcceso $perfilAcceso): View|RedirectResponse
    {
        $volver = redirect()->route('admin.matriz-permisos.index', ['perfil' => $perfilAcceso->id]);
        if ($perfilAcceso->esAdministrador()) {
            return $volver->with('error', self::ADMINISTRADOR_FIJO);
        }

        $datos = $request->validate([
            'huella' => ['required', 'string', 'size:64'],
            'motivo' => ['nullable', 'string', 'max:300'],
            'confirmado' => ['nullable', 'in:1'],
            'confirmar_delicados' => ['nullable', 'in:1'],
        ], [], ['motivo' => 'motivo']);
        if (! hash_equals(PermisosDePerfil::huella($perfilAcceso), $datos['huella'])) {
            return $volver->with('error', PermisosDePerfil::PERFIL_MODIFICADO);
        }

        $nuevas = PermisosDePerfil::desdeFormulario($request->input('permisos', []), estricto: true);
        [$agregadas, $quitadas] = PermisosDePerfil::cambios(PermisosDePerfil::claves($perfilAcceso), $nuevas);
        if ($agregadas === [] && $quitadas === []) {
            return $volver->with('status', 'No hay cambios para guardar.');
        }
        PermisosDePerfil::validarSinEscalada($request->user(), $agregadas);
        $delicadas = PermisosDePerfil::delicadas($agregadas);

        if (empty($datos['confirmado']) || ($delicadas !== [] && empty($datos['confirmar_delicados']))) {
            return view('admin.matriz-permisos.confirmar', [
                'perfil' => $perfilAcceso,
                'agregadas' => PermisosDePerfil::legible($agregadas),
                'quitadas' => PermisosDePerfil::legible($quitadas),
                'delicadas' => PermisosDePerfil::legible($delicadas),
                'permisos' => $this->matrizDeClaves($nuevas),
                'huella' => $datos['huella'],
                'motivo' => $datos['motivo'] ?? '',
                'faltaConfirmar' => ! empty($datos['confirmado']),
                'usuarios' => $perfilAcceso->users()->count(),
            ]);
        }

        PermisosDePerfil::guardar($perfilAcceso, $nuevas, $datos['motivo'] ?? null);

        return $volver->with('status', sprintf('Permisos de «%s» guardados: %d agregados y %d quitados. Valen desde el próximo pedido de cada usuario del perfil.',
            $perfilAcceso->nombre, count($agregadas), count($quitadas)));
    }

    /** Los módulos agrupados como en el menú; los que no están en el menú, en "Otros". */
    private function agrupar(Collection $modulos): array
    {
        $porCodigo = $modulos->keyBy('codigo');
        $grupos = [];
        foreach (self::GRUPOS as $grupo => $codigos) {
            $grupos[$grupo] = collect($codigos)->map(fn ($codigo) => $porCodigo->get($codigo))->filter()->values();
        }
        $enGrupos = collect(self::GRUPOS)->flatten()->all();
        $grupos[self::OTROS] = $modulos->reject(fn (ModuloSistema $modulo) => in_array($modulo->codigo, $enGrupos, true))->values();

        return array_filter($grupos, fn (Collection $lista) => $lista->isNotEmpty());
    }

    private function datosComparar(Collection $perfiles, Collection $modulos): array
    {
        $matriz = [];   // [perfil_id][modulo_codigo] => ['VER', ...]
        $difiere = [];  // [perfil_id][modulo_codigo] => lo predefinido, si difiere
        foreach ($perfiles as $perfil) {
            $claves = PermisosDePerfil::claves($perfil);
            $predefinidas = isset(PerfilesPredefinidos::MATRIZ[$perfil->codigo]) ? self::clavesPredefinidas($perfil->codigo) : null;
            foreach ($modulos as $modulo) {
                $acciones = self::accionesEn($claves, $modulo->codigo);
                $matriz[$perfil->id][$modulo->codigo] = $acciones;
                if ($predefinidas !== null && $acciones !== ($esperadas = self::accionesEn($predefinidas, $modulo->codigo))) {
                    $difiere[$perfil->id][$modulo->codigo] = $esperadas;
                }
            }
        }

        return ['perfiles' => $perfiles, 'grupos' => $this->agrupar($modulos), 'matriz' => $matriz, 'difiere' => $difiere];
    }

    /** Acciones de esas claves en un módulo, en el orden de Permiso::ACCIONES. */
    private static function accionesEn(array $claves, string $modulo): array
    {
        return array_values(array_filter(Permiso::ACCIONES, fn (string $accion) => in_array("{$modulo}:{$accion}", $claves, true)));
    }

    /** Las claves de la matriz predefinida de ese código (PerfilesPredefinidos::MATRIZ; no se cambia acá). */
    private static function clavesPredefinidas(string $codigo): array
    {
        return collect(PerfilesPredefinidos::MATRIZ[$codigo] ?? [])
            ->flatMap(fn (array $acciones, string $modulo) => array_map(fn ($accion) => "{$modulo}:{$accion}", $acciones))->all();
    }

    /** Lo que volvió del formulario (old) como claves, sin validar: solo para volver a tildar. */
    private function clavesDeModulos(Collection $modulos, array $matriz): array
    {
        $codigos = $modulos->pluck('codigo', 'id');

        return collect($matriz)->flatMap(fn ($acciones, $moduloId) => collect((array) $acciones)
            ->map(fn ($accion) => ($codigos[(int) $moduloId] ?? '?').':'.$accion))->all();
    }

    /** Claves -> [modulo_id => [acciones]], para los campos ocultos de la confirmación. */
    private function matrizDeClaves(array $claves): array
    {
        $ids = ModuloSistema::pluck('id', 'codigo');

        return collect($claves)->map(fn ($clave) => explode(':', $clave, 2))
            ->groupBy(fn ($par) => $ids[$par[0]])->map(fn ($pares) => $pares->pluck(1)->all())->all();
    }
}
