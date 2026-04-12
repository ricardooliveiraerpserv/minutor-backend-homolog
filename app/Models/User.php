<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Traits\HasRoles;
use App\Notifications\ResetPasswordNotification;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'enabled',
        'hourly_rate',
        'rate_type',
        'theme_preference',
        'has_temporary_password',
        'temporary_password_expires_at',
        'profile_photo',
        'customer_id',
        'partner_id',
        'is_executive',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'profile_photo_url'
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
            'enabled' => 'boolean',
            'hourly_rate' => 'decimal:2',
            'has_temporary_password' => 'boolean',
            'temporary_password_expires_at' => 'datetime',
            'is_executive' => 'boolean',
        ];
    }

    /**
     * Método seguro para verificar senha
     */
    public function verifyPassword(string $password): bool
    {
        // Lê o hash direto do banco, sem passar pelo cast 'hashed'
        $stored = \DB::table('users')->where('id', $this->id)->value('password');
        return Hash::check($password, $stored);
    }

    /**
     * Método seguro para atualizar senha
     */
    public function updatePassword(string $newPassword): void
    {
        \DB::table('users')->where('id', $this->id)->update([
            'password'   => Hash::make($newPassword),
            'updated_at' => now(),
        ]);
        $this->refresh();
    }

    /**
     * Relacionamento com cliente (se o usuário for de um cliente específico)
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relacionamento com parceiro (se o usuário for do tipo Parceiro / Parceiro ADM)
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Verifica se o usuário é um usuário de cliente
     */
    public function isCustomerUser(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * Verifica se o usuário é um usuário interno
     */
    public function isInternalUser(): bool
    {
        return $this->customer_id === null;
    }

    /**
     * Relacionamento com projetos onde o usuário é consultor
     */
    public function consultantProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_consultants')
                    ->withTimestamps();
    }

    /**
     * Relacionamento com projetos onde o usuário é coordenador
     */
    public function coordinatorProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_coordinators')
                    ->withTimestamps();
    }

    /**
     * @deprecated Use coordinatorProjects()
     */
    public function approverProjects(): BelongsToMany
    {
        return $this->coordinatorProjects();
    }

    /**
     * Relacionamento com grupos de consultores
     */
    public function consultantGroups(): BelongsToMany
    {
        return $this->belongsToMany(ConsultantGroup::class, 'consultant_group_user')
                    ->withTimestamps();
    }

    /**
     * Todos os projetos do usuário (consultor ou aprovador)
     */
    public function allProjects(): BelongsToMany
    {
        return $this->consultantProjects()->union($this->approverProjects()->getQuery());
    }

    /**
     * Relacionamento com logs de alteração de valor hora
     */
    public function hourlyRateLogs(): HasMany
    {
        return $this->hasMany(UserHourlyRateLog::class, 'user_id');
    }

    /**
     * Relacionamento com logs de alterações feitas por este usuário
     */
    public function hourlyRateChangesMade(): HasMany
    {
        return $this->hasMany(UserHourlyRateLog::class, 'changed_by');
    }

    /**
     * Send the password reset notification.
     *
     * @param string $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Verifica se o usuário está usando uma senha temporária
     */
    public function hasTemporaryPassword(): bool
    {
        return $this->has_temporary_password &&
               $this->temporary_password_expires_at &&
               $this->temporary_password_expires_at->isFuture();
    }

    /**
     * Verifica se a senha temporária expirou
     */
    public function temporaryPasswordExpired(): bool
    {
        return $this->has_temporary_password &&
               $this->temporary_password_expires_at &&
               $this->temporary_password_expires_at->isPast();
    }

    /**
     * Define uma senha temporária com expiração
     */
    public function setTemporaryPassword(string $password, int $hoursToExpire = 24): void
    {
        \DB::table('users')->where('id', $this->id)->update([
            'password'                      => Hash::make($password),
            'has_temporary_password'        => true,
            'temporary_password_expires_at' => now()->addHours($hoursToExpire),
            'updated_at'                    => now(),
        ]);
        $this->refresh();
    }

    /**
     * Remove a marcação de senha temporária
     */
    public function clearTemporaryPassword(): void
    {
        $this->update([
            'has_temporary_password' => false,
            'temporary_password_expires_at' => null,
        ]);
    }

    /**
     * Obtém a URL da foto de perfil
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (!$this->profile_photo) {
            return null;
        }

        return asset('storage/' . $this->profile_photo);
    }

    /**
     * Remove a foto de perfil
     */
    public function removeProfilePhoto(): void
    {
        if ($this->profile_photo) {
            // Remove o arquivo físico
            $path = storage_path('app/public/' . $this->profile_photo);
            if (file_exists($path)) {
                unlink($path);
            }

            // Remove a referência do banco
            $this->update(['profile_photo' => null]);
        }
    }


    /**
     * Obtém os tipos de dashboard permitidos para o usuário
     */
    public function getAllowedDashboardTypes(): array
    {
        return \DB::table('user_dashboard_types')
            ->where('user_id', $this->id)
            ->pluck('dashboard_type')
            ->toArray();
    }

    /**
     * Verifica se o usuário tem acesso a um tipo específico de dashboard
     */
    public function hasDashboardAccess(string $dashboardType): bool
    {
        return \DB::table('user_dashboard_types')
            ->where('user_id', $this->id)
            ->where('dashboard_type', $dashboardType)
            ->exists();
    }

    /**
     * Adiciona acesso a um tipo de dashboard
     */
    public function addDashboardAccess(string $dashboardType): void
    {
        \DB::table('user_dashboard_types')->updateOrInsert(
            [
                'user_id' => $this->id,
                'dashboard_type' => $dashboardType
            ],
            [
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Remove acesso a um tipo de dashboard
     */
    public function removeDashboardAccess(string $dashboardType): void
    {
        \DB::table('user_dashboard_types')
            ->where('user_id', $this->id)
            ->where('dashboard_type', $dashboardType)
            ->delete();
    }

    /**
     * Sincroniza os tipos de dashboard permitidos
     */
    public function syncDashboardTypes(array $dashboardTypes): void
    {
        // Remove todos os tipos atuais
        \DB::table('user_dashboard_types')
            ->where('user_id', $this->id)
            ->delete();

        // Adiciona os novos tipos
        foreach ($dashboardTypes as $dashboardType) {
            $this->addDashboardAccess($dashboardType);
        }
    }
}
