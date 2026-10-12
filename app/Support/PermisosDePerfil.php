<?php

namespace App\Support;

use App\Enums\AccionAuditoria;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edición de los permisos de un perfil, en UN solo lugar: la usan Perfiles de acceso y la Matriz de permisos.
 *
 * - Los permisos se manejan como claves "MÓDULO:ACCIÓN" (MÓDULO = modulos_sistema.codigo).
 * - El formulario manda permisos[modulo_id][] = ACCIÓN. Un módulo inexistente o una acción que no existe se
 *   rechazan siempre; una acción que no tiene efecto en el módulo (Permiso::accionesDe) se rechaza en modo
 *   estricto (Matriz) y se ignora en Perfiles de acceso (donde esas celdas no tienen casilla).
 * - Sin escalada: solo se AGREGAN permisos que quien edita ya tiene; quitar siempre se puede.
 * - Guardar: en una transacción, deja en perfil_permiso exactamente esas claves y registra UN evento EDITAR del
 *   perfil con lo agregado y lo quitado, agrupado por módulo ("RECETAS: ver, crear"), y el motivo en el detalle.
 *   Sin cambios, no registra nada.
 */
final class PermisosDePerfil
{
    public const PERFIL_MODIFICADO = 'El perfil fue modificado desde otra ventana. Recargue la página.';

    /** Acciones de escritura que sobre USUARIOS o PERFILES_ACCESO convierten en administradores. */
    public const MODULOS_DE_ADMINISTRACION = ['USUARIOS', 'PERFILES_ACCESO'];

    /** Las claves actuales del perfil, ordenadas. */
    public static function claves(PerfilAcceso $perfil): array
    {
        $claves = $perfil->relationLoaded('permisos')
            ? $perfil->permisos->map(fn (Permiso $permiso) => self::clave($permiso))->all()
            : $perfil->clavesPermisos();
        sort($claves);

        return array_values(array_unique($claves));
    }

    /** Huella del conjunto de permisos del perfil (la pivote no toca updated_at): control de concurrencia. */
    public static function huella(PerfilAcceso $perfil): string
    {
        return hash('sha256', $perfil->id.'|'.implode(',', self::claves($perfil)));
    }

    /**
     * Claves pedidas en el formulario (permisos[modulo_id][] = ACCIÓN). Lanza ValidationException ('permisos') con
     * un módulo inexistente, una acción inexistente o (estricto) una acción sin efecto en el módulo.
     */
    public static function desdeFormulario(mixed $matriz, bool $estricto): array
    {
        if ($matriz === null) {
            return [];
        }
        if (! is_array($matriz)) {
            throw ValidationException::withMessages(['permisos' => 'La matriz de permisos no es válida.']);
        }

        $codigos = ModuloSistema::pluck('codigo', 'id');
        $claves = [];
        foreach ($matriz as $moduloId => $acciones) {
            $codigo = $codigos[(int) $moduloId] ?? null;
            if ($codigo === null || ! ctype_digit((string) $moduloId) || ! is_array($acciones)) {
                throw ValidationException::withMessages(['permisos' => 'La matriz de permisos incluye un módulo inexistente.']);
            }
            foreach ($acciones as $accion) {
                if (! is_string($accion) || ! in_array($accion, Permiso::ACCIONES, true)) {
                    throw ValidationException::withMessages(['permisos' => 'La matriz de permisos incluye una acción inexistente.']);
                }
                if (! in_array($accion, Permiso::accionesDe($codigo), true)) {
                    if ($estricto) {
                        throw ValidationException::withMessages(['permisos' => "La acción {$accion} no tiene efecto en el módulo {$codigo}."]);
                    }

                    continue;
                }
                $claves[] = "{$codigo}:{$accion}";
            }
        }
        sort($claves);

        return array_values(array_unique($claves));
    }

    /** Lo que cambia: [agregadas, quitadas]. */
    public static function cambios(array $actuales, array $nuevas): array
    {
        return [array_values(array_diff($nuevas, $actuales)), array_values(array_diff($actuales, $nuevas))];
    }

    /**
     * Sin escalada de privilegios: las claves que se AGREGAN tienen que ser permisos que quien edita ya tiene
     * (el Administrador tiene todo). Quitar siempre se puede.
     */
    public static function validarSinEscalada(User $quien, array $agregadas): void
    {
        if ($quien->tieneTodos($agregadas)) {
            return;
        }
        $ajenas = array_diff($agregadas, array_keys($quien->permisosEfectivos()));
        throw ValidationException::withMessages([
            'permisos' => 'No puede agregar permisos que usted no tiene: '.implode(', ', array_map(fn ($clave) => str_replace(':', ': ', $clave), $ajenas)).'.',
        ]);
    }

    /**
     * Las claves agregadas que piden la confirmación reforzada: en un módulo sensible, o de escritura sobre
     * USUARIOS o PERFILES_ACCESO (en la práctica, hacen administradores a los usuarios del perfil).
     */
    public static function delicadas(array $agregadas): array
    {
        $sensibles = ModuloSistema::where('es_sensible', true)->pluck('codigo')->all();

        return array_values(array_filter($agregadas, function (string $clave) use ($sensibles) {
            [$modulo, $accion] = explode(':', $clave, 2);

            return in_array($modulo, $sensibles, true)
                || (in_array($modulo, self::MODULOS_DE_ADMINISTRACION, true) && $accion !== 'VER');
        }));
    }

    /**
     * Deja el perfil con exactamente esas claves, en una transacción, con UN evento EDITAR (agregados y quitados)
     * si algo cambió. Crea en permisos las combinaciones que falten. Devuelve [agregadas, quitadas].
     */
    public static function guardar(PerfilAcceso $perfil, array $nuevas, ?string $motivo = null): array
    {
        return DB::transaction(function () use ($perfil, $nuevas, $motivo) {
            // Con el perfil bloqueado: dos guardados a la vez no se mezclan.
            PerfilAcceso::whereKey($perfil->id)->lockForUpdate()->first();
            $actuales = $perfil->clavesPermisos();
            [$agregadas, $quitadas] = self::cambios($actuales, $nuevas);
            if ($agregadas === [] && $quitadas === []) {
                return [[], []];
            }

            $modulos = ModuloSistema::pluck('id', 'codigo');
            $ids = array_map(function (string $clave) use ($modulos) {
                [$modulo, $accion] = explode(':', $clave, 2);

                return Permiso::firstOrCreate(['modulo_sistema_id' => $modulos[$modulo], 'accion' => $accion])->id;
            }, $nuevas);
            $perfil->permisos()->sync($ids);
            $perfil->unsetRelation('permisos');

            if (Auditoria::activa()) {
                Auditoria::registrar(AccionAuditoria::EDITAR, $perfil->getTable(), $perfil->id,
                    ['permisos_quitados' => self::legible($quitadas)], ['permisos_agregados' => self::legible($agregadas)],
                    filled($motivo) ? 'Motivo: '.trim($motivo) : null);
            }

            return [$agregadas, $quitadas];
        });
    }

    /** ["RECETAS:VER", "RECETAS:CREAR"] -> ["RECETAS: ver, crear"], por módulo y en el orden de Permiso::ACCIONES. */
    public static function legible(array $claves): array
    {
        return collect($claves)
            ->map(fn (string $clave) => explode(':', $clave, 2))
            ->groupBy(0)
            ->sortKeys()
            ->map(fn ($filas, string $modulo) => $modulo.': '.collect(Permiso::ACCIONES)
                ->filter(fn (string $accion) => $filas->contains(1, $accion))
                ->map(fn (string $accion) => mb_strtolower($accion))->implode(', '))
            ->values()->all();
    }

    private static function clave(Permiso $permiso): string
    {
        return "{$permiso->moduloSistema->codigo}:{$permiso->accion}";
    }
}
