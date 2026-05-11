<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Role;
use App\Services\AdminRoleMenuOverrideService;
use App\Services\RolePermissionSettingService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class AdminRolePreviewPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationGroup = 'Dokumentasi';

    protected static ?string $navigationLabel = 'FE Role Preview';

    protected static ?string $title = 'Preview Tampilan FE Admin per Role';

    protected static ?string $slug = 'admin-role-preview';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.admin-role-preview-page';

    public ?string $rolePreview = 'operator';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM], true);
    }

    public function mount(): void
    {
        $this->form->fill([
            'rolePreview' => $this->rolePreview,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('rolePreview')
                ->label('Simulasi role')
                ->options($this->roleOptions())
                ->live()
                ->native(false)
                ->helperText('Preview ini hanya simulasi visual menu FE Admin, bukan impersonate login.'),
        ]);
    }

    public function roleData(): array
    {
        $role = $this->rolePreview ?: 'operator';
        $permissions = $this->permissionsFor($role);
        $menuService = app(AdminRoleMenuOverrideService::class);
        $visibleViews = $menuService->allowedViewsForRole($role, $this->permissionFlags($role, $permissions));
        $menus = $this->menusFor($visibleViews);

        return [
            'role' => $role,
            'label' => $this->roleOptions()[$role] ?? $role,
            'permissions' => $permissions,
            'visible_views' => $visibleViews,
            'menus' => $menus,
            'menu_groups' => $menuService->menuGroups(),
            'cards' => $this->cardsFor($role, $permissions),
            'notes' => $this->notesFor($role),
        ];
    }

    private function roleOptions(): array
    {
        return [
            'hrd' => 'HRD',
            'manager' => 'Manager',
            'spv' => 'SPV',
            'operator' => 'Operator',
            'eksekutor' => 'Eksekutor',
            'web_admin' => 'Web Admin',
            'cms_editor' => 'CMS Editor',
        ];
    }

    private function permissionsFor(string $role): array
    {
        $permissions = Role::query()
            ->where('name', $role)
            ->with('permissions:id,name')
            ->first()
            ?->permissions
            ->pluck('name')
            ->all();

        $permissions ??= match ($role) {
            'hrd' => ['create_user', 'suspend_driver', 'view_report', 'monitor_live_order', 'monitor_live_chat', 'internal_chat'],
            'manager' => ['create_user', 'suspend_driver', 'view_report', 'export_report', 'monitor_live_order', 'monitor_live_chat', 'internal_chat'],
            'spv' => ['suspend_driver', 'unsuspend_driver', 'monitor_live_order', 'monitor_live_chat', 'approve_cancel_order', 'reject_cancel_order', 'internal_chat'],
            'operator' => ['monitor_live_order', 'assign_driver', 'monitor_live_chat', 'approve_cancel_order', 'reject_cancel_order', 'manual_order', 'internal_chat'],
            'eksekutor' => ['monitor_live_order', 'assign_driver', 'monitor_live_chat', 'approve_cancel_order', 'reject_cancel_order', 'manual_order', 'internal_chat'],
            'web_admin', 'cms_editor' => ['manage_cms', 'internal_chat'],
            default => ['internal_chat'],
        };

        $permissions = array_values(array_diff($permissions, ['edit_tarif']));

        if (app(RolePermissionSettingService::class)->roleHasEditTarif($role)) {
            $permissions[] = 'edit_tarif';
        }

        return array_values(array_unique($permissions));
    }

    public function toggleMenu(string $view): void
    {
        $role = $this->rolePreview ?: 'operator';
        $menuService = app(AdminRoleMenuOverrideService::class);
        $visible = in_array($view, $menuService->allowedViewsForRole($role, $this->permissionFlags($role, $this->permissionsFor($role))), true);

        $menuService->setViewVisible($role, $view, ! $visible);
    }

    /**
     * @param  array<int, string>  $views
     * @return array<int, string>
     */
    private function menusFor(array $views): array
    {
        $labels = collect(app(AdminRoleMenuOverrideService::class)->menuGroups())->collapse();

        return collect($views)
            ->map(fn (string $view): string => (string) ($labels[$view] ?? $view))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<string, mixed>
     */
    private function permissionFlags(string $role, array $permissions): array
    {
        return [
            'can_manage_policy' => in_array('edit_tarif', $permissions, true),
            'can_manage_users' => in_array('create_user', $permissions, true),
            'can_suspend_drivers' => in_array('suspend_driver', $permissions, true),
            'can_unsuspend_drivers' => in_array('unsuspend_driver', $permissions, true),
            'can_edit_order_price' => in_array('edit_tarif', $permissions, true),
            'can_create_manual_order' => in_array('manual_order', $permissions, true) || in_array($role, ['operator', 'eksekutor'], true),
            'can_assign_driver' => in_array('assign_driver', $permissions, true),
            'can_view_report' => in_array('view_report', $permissions, true),
            'can_monitor_live_order' => in_array('monitor_live_order', $permissions, true),
            'can_monitor_live_chat' => in_array('monitor_live_chat', $permissions, true),
            'can_use_internal_chat' => in_array('internal_chat', $permissions, true),
            'can_manage_cms' => in_array('manage_cms', $permissions, true),
            'can_manage_system_settings' => in_array('manage_system_settings', $permissions, true),
        ];
    }

    public function resetRoleOverride(): void
    {
        $role = $this->rolePreview ?: 'operator';
        app(AdminRoleMenuOverrideService::class)->resetRole($role);
    }

    private function cardsFor(string $role, array $permissions): array
    {
        $cards = [
            ['label' => 'Active Order Realtime', 'value' => 'Area scope', 'tone' => 'blue'],
            ['label' => 'Chat Belum Dibalas', 'value' => in_array('monitor_live_chat', $permissions, true) ? 'Visible' : 'Hidden', 'tone' => 'green'],
            ['label' => 'Internal Chat', 'value' => 'Visible', 'tone' => 'cyan'],
        ];

        if ($role === 'eksekutor') {
            $cards[] = ['label' => 'Pending Dispatch Area', 'value' => 'Primary', 'tone' => 'orange'];
            $cards[] = ['label' => 'Driver Idle Area', 'value' => 'Visible', 'tone' => 'purple'];
        }

        if (in_array($role, ['manager', 'spv'], true)) {
            $cards[] = ['label' => 'Driver Performance Area', 'value' => 'Visible', 'tone' => 'purple'];
            $cards[] = ['label' => 'Operator Performance', 'value' => 'Visible', 'tone' => 'orange'];
        }

        if ($role === 'hrd') {
            $cards[] = ['label' => 'User & Driver Audit', 'value' => 'Visible', 'tone' => 'purple'];
        }

        return $cards;
    }

    private function notesFor(string $role): array
    {
        return match ($role) {
            'hrd' => ['Fokus pada user, driver status, laporan HR, dan pricing sesuai CMS.', 'Akses pricing HRD bersifat global agar tidak mentok akun tanpa cabang.'],
            'manager' => ['Melihat area/cabang sendiri dan laporan operasional.', 'Bisa evaluasi operator, driver, dan pricing policy sesuai izin.'],
            'spv' => ['Monitoring live order, suspend/release driver, dan approval cancel.', 'Tidak punya menu create user penuh.'],
            'operator' => ['Fokus live chat, manual order, order operations, dan assign driver.', 'Data mengikuti area/jadwal yang melekat pada akun.'],
            'eksekutor' => ['Fokus dispatch area, backup operator, dan pending order.', 'Tidak melihat laporan global atau area lain.'],
            default => ['Role CMS hanya untuk konten yang diizinkan.', 'Dashboard operasional utama disembunyikan.'],
        };
    }
}
