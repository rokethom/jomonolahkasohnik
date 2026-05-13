<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\RolePermissionSettingService;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'branch_id',
        'lat',
        'lng',
        'address',
        'profile_photo_path',
        'is_staff',
        'is_active',
        'is_suspended',
        'suspension_reason',
        'suspended_until',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
        'is_staff' => 'boolean',
        'is_active' => 'boolean',
        'is_suspended' => 'boolean',
        'suspended_until' => 'datetime',
        'lat' => 'decimal:8',
        'lng' => 'decimal:8',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! $user->username) {
                $base = $user->email
                    ? str($user->email)->before('@')->slug('_')->toString()
                    : str($user->name ?: 'user')->slug('_')->toString();

                $user->username = $base.'_'.str()->lower(str()->random(5));
            }
        });

        static::saving(function (User $user): void {
            $role = $user->role instanceof UserRole
                ? $user->role
                : UserRole::tryFrom((string) $user->role);

            $user->is_staff = $role?->isStaff() ?? false;
        });

        static::saved(function (User $user): void {
            try {
                if (Schema::hasTable('roles') && Schema::hasTable('user_roles')) {
                    $user->syncPrimaryRole();
                }
            } catch (\Throwable) {
                // RBAC tables may not exist yet while running early migrations.
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $role = $this->role instanceof UserRole
            ? $this->role
            : UserRole::tryFrom((string) $this->role);

        return $this->is_active
            && ! $this->is_suspended
            && in_array($role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager, UserRole::SPV, UserRole::Operator, UserRole::Eksekutor, UserRole::WebAdmin, UserRole::CmsEditor], true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchScopes(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch_scopes')->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function permissions(): array
    {
        $permissions = $this->rbacPermissions() ?? $this->legacyRolePermissions();

        try {
            $pricingRoles = app(RolePermissionSettingService::class);
            $permissions = array_values(array_diff($permissions, ['edit_tarif']));

            if ($pricingRoles->roleHasEditTarif($this->role)) {
                $permissions[] = 'edit_tarif';
            }
        } catch (\Throwable) {
            // Keep legacy permissions during early bootstrap/migration when settings are unavailable.
        }

        return array_values(array_unique($permissions));
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function syncPrimaryRole(): void
    {
        $roleName = $this->role?->value ?? (string) $this->role;
        $role = Role::query()->firstOrCreate(['name' => $roleName]);
        $this->roles()->syncWithoutDetaching([$role->id]);
    }

    private function legacyRolePermissions(): array
    {
        return match ($this->role?->value ?? (string) $this->role) {
            'admin' => ['create_user', 'suspend_driver', 'unsuspend_driver', 'view_report', 'export_report', 'edit_tarif', 'monitor_live_order', 'monitor_live_chat', 'approve_cancel_order', 'reject_cancel_order', 'manual_order', 'internal_chat', 'manage_system_settings', 'manage_manual_order', 'manage_cms'],
            'gm' => ['create_user', 'suspend_driver', 'unsuspend_driver', 'view_report', 'export_report', 'edit_tarif', 'monitor_live_order', 'monitor_live_chat', 'approve_cancel_order', 'reject_cancel_order', 'manual_order', 'internal_chat', 'manage_system_settings', 'manage_manual_order', 'manage_cms'],
            'hrd' => ['create_user', 'suspend_driver', 'view_report', 'monitor_live_order', 'monitor_live_chat', 'internal_chat', 'edit_tarif', 'manage_system_settings', 'manage_manual_order'],
            'manager' => ['create_user', 'suspend_driver', 'view_report', 'monitor_live_order', 'monitor_live_chat', 'internal_chat', 'export_report', 'manage_system_settings', 'manage_manual_order'],
            'spv' => ['suspend_driver', 'unsuspend_driver', 'monitor_live_order', 'approve_cancel_order', 'reject_cancel_order', 'monitor_live_chat', 'internal_chat', 'manage_system_settings', 'manage_manual_order'],
            'operator' => ['monitor_live_order', 'assign_driver', 'approve_cancel_order', 'reject_cancel_order', 'monitor_live_chat', 'internal_chat', 'manual_order'],
            'eksekutor' => ['monitor_live_order', 'assign_driver', 'approve_cancel_order', 'reject_cancel_order', 'monitor_live_chat', 'internal_chat', 'manual_order'],
            'web_admin', 'cms_editor' => ['manage_cms'],
            default => [],
        };
    }

    private function rbacPermissions(): ?array
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return null;
        }

        $primaryRoleName = $this->role?->value ?? (string) $this->role;
        $roles = $this->roles()
            ->with('permissions:id,name')
            ->get();

        if ($primaryRoleName !== '' && ! $roles->contains('name', $primaryRoleName)) {
            $primaryRole = Role::query()
                ->where('name', $primaryRoleName)
                ->with('permissions:id,name')
                ->first();

            if ($primaryRole) {
                $roles->push($primaryRole);
            }
        }

        if ($roles->isEmpty()) {
            return null;
        }

        return $roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function locationLogs(): HasMany
    {
        return $this->hasMany(LocationLog::class);
    }

    public function latestLocationLog(): HasOne
    {
        return $this->hasOne(LocationLog::class)->latestOfMany();
    }

    public function currentLocation(): HasOne
    {
        return $this->hasOne(UserLocation::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(UserDeviceToken::class);
    }
}
