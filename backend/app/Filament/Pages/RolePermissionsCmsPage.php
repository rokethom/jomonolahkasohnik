<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Services\BranchAccessSettingService;
use App\Services\RolePermissionCatalog;
use App\Services\RolePermissionSettingService;
use App\Services\SettingService;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RolePermissionsCmsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Role Permissions CMS';

    protected static ?string $title = 'Role Permissions CMS';

    protected static ?string $slug = 'role-permissions-cms';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.role-permissions-cms-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM], true);
    }

    public function mount(RolePermissionCatalog $catalog, RolePermissionSettingService $pricingRoles, BranchAccessSettingService $branchAccess): void
    {
        $state = [];

        foreach ($catalog->managedRoles() as $role => $label) {
            $permissions = $this->permissionsForRole($role, $pricingRoles);

            foreach ($catalog->groups() as $group => $options) {
                $state['roles'][$role][$this->groupKey($group)] = array_values(array_intersect(array_keys($options), $permissions));
            }
        }

        $state['branch_access']['global_roles'] = $branchAccess->globalAccessRoles();

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        $catalog = app(RolePermissionCatalog::class);

        return $form
            ->schema([
                Forms\Components\Section::make('Kontrol akses role')
                    ->description('Checklist ini mengatur permission runtime FE Admin, API, dan menu Filament. Admin dan GM selalu full access agar CMS tidak terkunci.')
                    ->schema([
                        Tabs::make('Role')
                            ->tabs(collect($catalog->managedRoles())->map(function (string $label, string $role) use ($catalog): Tabs\Tab {
                                return Tabs\Tab::make($label)
                                    ->schema($this->roleSchema($role, $catalog));
                            })->values()->all()),
                    ]),
                Forms\Components\Section::make('Akses lintas cabang')
                    ->description('Role yang dicentang dapat melihat, membuat, dan mengedit data semua cabang tanpa terkunci branch akun. Admin dan GM selalu lintas cabang.')
                    ->schema([
                        Forms\Components\CheckboxList::make('branch_access.global_roles')
                            ->label('Role dengan akses semua cabang')
                            ->options([
                                UserRole::HRD->value => UserRole::HRD->label(),
                                UserRole::Manager->value => UserRole::Manager->label(),
                                UserRole::SPV->value => UserRole::SPV->label(),
                                UserRole::Operator->value => UserRole::Operator->label(),
                                UserRole::Eksekutor->value => UserRole::Eksekutor->label(),
                            ])
                            ->columns(2)
                            ->bulkToggleable()
                            ->helperText('Gunakan ini jika akses lintas cabang perlu diubah tanpa edit kode.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(RolePermissionCatalog $catalog, RolePermissionSettingService $pricingRoles, BranchAccessSettingService $branchAccess, SettingService $settings): void
    {
        $state = $this->form->getState();
        $permissionModels = collect($catalog->permissions())
            ->keys()
            ->mapWithKeys(fn (string $name): array => [$name => Permission::query()->firstOrCreate(['name' => $name])]);
        $editTarifRoles = [];

        foreach ($catalog->managedRoles() as $roleName => $label) {
            $selected = collect($state['roles'][$roleName] ?? [])
                ->flatten()
                ->map(fn (mixed $permission): string => (string) $permission)
                ->filter(fn (string $permission): bool => $permissionModels->has($permission))
                ->unique()
                ->values()
                ->all();

            $role = Role::query()->firstOrCreate(['name' => $roleName]);
            $role->permissions()->sync($permissionModels->only($selected)->pluck('id')->all());

            if (in_array('edit_tarif', $selected, true)) {
                $editTarifRoles[] = $roleName;
            }
        }

        $settings->set(
            RolePermissionSettingService::EDIT_TARIF_ALLOWED_ROLES_KEY,
            json_encode($pricingRoles->normalizeEditTarifRoles($editTarifRoles)),
            true,
            ['type' => 'json'],
        );

        $settings->set(
            BranchAccessSettingService::GLOBAL_ACCESS_ROLES_KEY,
            json_encode($branchAccess->normalizeRoles($state['branch_access']['global_roles'] ?? [])),
            true,
            ['type' => 'json'],
        );

        Notification::make()
            ->title('Role permissions tersimpan')
            ->body('Hak akses role, menu FE Admin, scope cabang, dan permission API akan mengikuti checklist terbaru.')
            ->success()
            ->send();

        $this->mount($catalog, $pricingRoles, $branchAccess);
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function roleSchema(string $role, RolePermissionCatalog $catalog): array
    {
        return collect($catalog->groups())
            ->map(function (array $options, string $group) use ($role): Forms\Components\Section {
                return Forms\Components\Section::make($group)
                    ->schema([
                        Forms\Components\CheckboxList::make("roles.{$role}.{$this->groupKey($group)}")
                            ->hiddenLabel()
                            ->options($options)
                            ->columns(2)
                            ->bulkToggleable(),
                    ]);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function permissionsForRole(string $role, RolePermissionSettingService $pricingRoles): array
    {
        $permissions = Role::query()
            ->where('name', $role)
            ->with('permissions:id,name')
            ->first()
            ?->permissions
            ->pluck('name')
            ->all() ?? [];

        $permissions = array_values(array_diff($permissions, ['edit_tarif']));

        if ($pricingRoles->roleHasEditTarif($role)) {
            $permissions[] = 'edit_tarif';
        }

        return array_values(array_unique($permissions));
    }

    private function groupKey(string $group): string
    {
        return str($group)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }
}
