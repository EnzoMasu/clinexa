<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Exceptions\AccionRechazada;
use App\Exceptions\AdministradorPrincipalInamovible;
use App\Exceptions\UltimoAdministrador;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Notifications\InvitacionUsuario;
use App\Support\AdministradorPrincipal;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use LogicException;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable, TieneEstado;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'persona_id',
        'email',
        'password',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'USUARIOS';
    }

    protected static function booted(): void
    {
        // La marca administrador_principal: nunca desde la aplicación (fuera de $fillable, y el modelo la
        // rechaza en un pedido web); a lo sumo un usuario la tiene (también el índice único parcial).
        static::saving(function (User $usuario) {
            if (! $usuario->isDirty('administrador_principal') || ($usuario->exists === false && ! $usuario->administrador_principal)) {
                return;
            }
            if (AdministradorPrincipal::aplica()) {
                throw new AccionRechazada(AdministradorPrincipal::MARCA_SOLO_POR_CONSOLA);
            }
            if ($usuario->administrador_principal && self::where('administrador_principal', true)->whereKeyNot($usuario->getKey())->exists()) {
                throw new LogicException(AdministradorPrincipal::YA_HAY_OTRO);
            }
        });

        static::updating(function (User $usuario) {
            // El administrador principal (en un pedido web): nadie cambia su estado, tampoco él mismo; su correo y
            // su persona solo él (la contraseña la cambia él con la actual, o por el link que le llega a su correo).
            if ($usuario->getOriginal('administrador_principal') && AdministradorPrincipal::aplica()
                && ($usuario->isDirty('estado_id') || ($usuario->isDirty(['email', 'persona_id']) && (int) Auth::id() !== (int) $usuario->id))) {
                throw new AdministradorPrincipalInamovible;
            }
            // El último administrador activo no se desactiva ni se bloquea, por ningún camino.
            if ($usuario->isDirty('estado_id') && (int) $usuario->estado_id !== Estado::idDe(Estado::ACTIVO)
                && (int) $usuario->getOriginal('estado_id') === Estado::idDe(Estado::ACTIVO) && $usuario->esUltimoAdministrador()) {
                throw new UltimoAdministrador;
            }
        });
    }

    /** Si es el administrador principal (users.administrador_principal). */
    public function esAdministradorPrincipal(): bool
    {
        return (bool) $this->administrador_principal;
    }

    /**
     * Si es el único que hoy entra con el perfil Administrador (según lo guardado en la base): sacarlo
     * dejaría el sistema sin administración.
     */
    public function esUltimoAdministrador(): bool
    {
        return self::administradoresActivos()->whereKey($this->getKey())->exists()
            && ! self::administradoresActivos()->whereKeyNot($this->getKey())->exists();
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ultimo_acceso' => 'datetime',
            'administrador_principal' => 'boolean',
        ];
    }

    /**
     * La persona que es este usuario: de ahí salen nombre, documento, teléfono, etc.
     * El email se guarda también en users (lo usa el login) y se mantiene sincronizado
     * desde Persona (ver Persona::booted).
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * $user->name sale de la persona, así el código de Breeze que lo usa sigue funcionando.
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->persona?->nombre_completo ?? 'Usuario');
    }

    /**
     * El registro de profesional de la persona del usuario (si lo tiene): es el que atiende y
     * firma las consultas de la historia clínica.
     */
    public function profesional(): HasOne
    {
        return $this->hasOne(Profesional::class, 'persona_id', 'persona_id');
    }

    /**
     * Los perfiles de acceso del usuario (usuario_perfil). Puede tener varios: sus permisos se suman.
     * La columna vieja users.perfil_acceso_id quedó sin uso (se elimina en una migración posterior).
     */
    public function perfiles(): BelongsToMany
    {
        return $this->belongsToMany(PerfilAcceso::class, 'usuario_perfil', 'usuario_id', 'perfil_acceso_id')->using(UsuarioPerfil::class)->withTimestamps();
    }

    /** En el log, los perfiles como lista de nombres ordenada (como las categorías de un proveedor). */
    public function relacionesAuditadas(): array
    {
        return [
            'perfiles' => fn (Collection $perfiles) => $perfiles->pluck('nombre')->sort()->values()->all(),
        ];
    }

    /** Sus perfiles ACTIVOS (los inactivos no aportan permisos). Se cargan una vez por pedido. */
    public function perfilesActivos(): Collection
    {
        return $this->loadMissing('perfiles')->perfiles->filter(fn (PerfilAcceso $perfil) => $perfil->estaActivo())->values();
    }

    /**
     * Permisos efectivos como ['PERSONAS:VER' => true, ...]: la unión de los de sus perfiles ACTIVOS, sin
     * los de módulos INACTIVOS. Una sola consulta, una vez por pedido (y por instancia).
     *
     * @var array<string, true>|null
     */
    private ?array $permisosCargados = null;

    /** @return array<string, true> */
    public function permisosEfectivos(): array
    {
        return $this->permisosCargados ??= Permiso::query()
            ->join('perfil_permiso', 'perfil_permiso.permiso_id', '=', 'permisos.id')
            ->join('modulos_sistema', 'modulos_sistema.id', '=', 'permisos.modulo_sistema_id')
            ->whereIn('perfil_permiso.perfil_acceso_id', $this->perfilesActivos()->modelKeys())
            ->where('modulos_sistema.estado_id', Estado::idDe(Estado::ACTIVO))
            ->distinct()
            ->get(['modulos_sistema.codigo', 'permisos.accion'])
            ->mapWithKeys(fn ($permiso) => ["{$permiso->codigo}:{$permiso->accion}" => true])
            ->all();
    }

    /**
     * Si alguno de sus perfiles activos tiene el permiso. Los módulos INACTIVOS no otorgan acceso.
     */
    public function tienePermiso(string $modulo, string $accion): bool
    {
        return isset($this->permisosEfectivos()["{$modulo}:{$accion}"]);
    }

    /** Si tiene el perfil Administrador, activo. */
    public function esAdministrador(): bool
    {
        return $this->perfilesActivos()->contains(fn (PerfilAcceso $perfil) => $perfil->esAdministrador());
    }

    /**
     * Si puede asignar ese perfil (a otro usuario o a sí mismo) sin escalar privilegios: ya tiene todos sus
     * permisos. El Administrador tiene todo, así que puede asignar cualquiera.
     */
    public function puedeAsignarPerfil(PerfilAcceso $perfil): bool
    {
        return $this->esAdministrador() || $this->tieneTodos($perfil->clavesPermisos());
    }

    /** Si tiene todos estos permisos ("MÓDULO:ACCIÓN"). El Administrador, siempre. */
    public function tieneTodos(array $claves): bool
    {
        return $this->esAdministrador() || array_diff($claves, array_keys($this->permisosEfectivos())) === [];
    }

    /** Olvida los perfiles y permisos cargados (después de cambiarle los perfiles en el mismo pedido). */
    public function olvidarPermisos(): void
    {
        $this->permisosCargados = null;
        $this->unsetRelation('perfiles');
    }

    /**
     * Envía el link para definir la contraseña (mismo token que "olvidé mi contraseña"). Si el
     * usuario nunca entró al sistema, el email es la invitación de bienvenida; si ya lo usó,
     * es el de restablecer contraseña. Devuelve el status de Password (p. ej. RESET_LINK_SENT).
     */
    public function enviarLinkContrasena(): string
    {
        // Desde Usuarios no se le manda invitación ni restablecimiento al administrador principal (quien se lo
        // mandara no recibe el link, pero no hace falta abrir ese camino). Él usa "¿Olvidó su contraseña?".
        if ($this->esAdministradorPrincipal() && AdministradorPrincipal::aplica()) {
            throw new AdministradorPrincipalInamovible;
        }

        return Password::sendResetLink(['email' => $this->email], function (User $usuario, string $token) {
            $usuario->notify($usuario->ultimo_acceso === null
                ? new InvitacionUsuario($token)
                : new ResetPassword($token));
        });
    }

    /**
     * Si este usuario es el único que hoy puede entrar con el perfil Administrador (usuario ACTIVO
     * y persona ACTIVA). Sirve para no dejar el sistema sin nadie que lo administre.
     */
    public function esUnicoAdministradorActivo(): bool
    {
        if (! $this->esAdministrador() || $this->motivoAccesoDenegado() !== null) {
            return false;
        }

        return ! self::administradoresActivos()->whereKeyNot($this->getKey())->exists();
    }

    /**
     * Usuarios que hoy pueden entrar con el perfil Administrador: usuario ACTIVO, persona ACTIVA y el perfil
     * asignado (el Administrador no se desactiva). Es la regla del "último administrador".
     */
    public static function administradoresActivos(): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()
            ->activos()
            ->whereHas('persona', fn ($query) => $query->activos())
            ->whereHas('perfiles', fn ($query) => $query->where('codigo', PerfilAcceso::ADMINISTRADOR));
    }

    /**
     * Mensaje que se muestra al usuario si su estado no le permite entrar, o null si puede.
     * Se compara por el código del Estado relacionado (no por el nombre).
     */
    public function motivoAccesoDenegado(): ?string
    {
        return match (true) {
            $this->tieneEstado(Estado::BLOQUEADO) => 'Su usuario está bloqueado. Contacte al administrador.',
            // También queda inactivo si se desactivó la persona, aunque el usuario siga ACTIVO.
            ! $this->estaActivo(), $this->persona?->estaActivo() !== true => 'Su usuario está inactivo. Contacte al administrador.',
            // Hace falta al menos un perfil de acceso ACTIVO (los inactivos no aportan permisos).
            $this->perfilesActivos()->isEmpty() => 'Su usuario no tiene ningún perfil de acceso activo. Contacte al administrador.',
            default => null,
        };
    }
}
