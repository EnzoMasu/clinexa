<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\InvitacionUsuario;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Password;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'persona_id',
        'email',
        'password',
        'perfil_acceso_id',
        'estado',
    ];

    /**
     * Mismo default que la columna, para que el modelo recién creado ya lo tenga en memoria.
     */
    protected $attributes = [
        'estado' => 'ACTIVO',
    ];

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

    public function perfilAcceso(): BelongsTo
    {
        return $this->belongsTo(PerfilAcceso::class, 'perfil_acceso_id');
    }

    /**
     * Permisos del perfil como ['PERSONAS:VER' => true, ...], cargados una vez por request.
     *
     * @var array<string, true>|null
     */
    private ?array $permisosCargados = null;

    /**
     * Si el perfil del usuario tiene el permiso. Los módulos INACTIVOS no otorgan acceso.
     */
    public function tienePermiso(string $modulo, string $accion): bool
    {
        $this->permisosCargados ??= Permiso::query()
            ->join('perfil_permiso', 'perfil_permiso.permiso_id', '=', 'permisos.id')
            ->join('modulos_sistema', 'modulos_sistema.id', '=', 'permisos.modulo_sistema_id')
            ->where('perfil_permiso.perfil_acceso_id', $this->perfil_acceso_id)
            ->where('modulos_sistema.estado', 'ACTIVO')
            ->get(['modulos_sistema.codigo', 'permisos.accion'])
            ->mapWithKeys(fn ($permiso) => ["{$permiso->codigo}:{$permiso->accion}" => true])
            ->all();

        return isset($this->permisosCargados["{$modulo}:{$accion}"]);
    }

    /**
     * Envía el link para definir la contraseña (mismo token que "olvidé mi contraseña"). Si el
     * usuario nunca entró al sistema, el email es la invitación de bienvenida; si ya lo usó,
     * es el de restablecer contraseña. Devuelve el status de Password (p. ej. RESET_LINK_SENT).
     */
    public function enviarLinkContrasena(): string
    {
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
        if ($this->perfilAcceso?->nombre !== PerfilAcceso::ADMINISTRADOR || $this->motivoAccesoDenegado() !== null) {
            return false;
        }

        return ! self::query()
            ->whereKeyNot($this->getKey())
            ->where('estado', 'ACTIVO')
            ->where('perfil_acceso_id', $this->perfil_acceso_id)
            ->whereHas('persona', fn ($query) => $query->where('estado', 'ACTIVO'))
            ->exists();
    }

    /**
     * Mensaje que se muestra al usuario si su estado no le permite entrar, o null si puede.
     */
    public function motivoAccesoDenegado(): ?string
    {
        return match (true) {
            $this->estado === 'BLOQUEADO' => 'Su usuario está bloqueado. Contacte al administrador.',
            // También queda inactivo si se desactivó la persona, aunque users.estado siga ACTIVO.
            $this->estado !== 'ACTIVO', $this->persona?->estado !== 'ACTIVO' => 'Su usuario está inactivo. Contacte al administrador.',
            default => null,
        };
    }
}
