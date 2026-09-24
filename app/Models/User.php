<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
        'name',
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
     * Mensaje que se muestra al usuario si su estado no le permite entrar, o null si puede.
     */
    public function motivoAccesoDenegado(): ?string
    {
        return match ($this->estado) {
            'ACTIVO' => null,
            'BLOQUEADO' => 'Tu usuario está bloqueado. Contactá al administrador.',
            default => 'Tu usuario está inactivo. Contactá al administrador.',
        };
    }
}
