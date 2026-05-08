<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
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
        $menus = $this->menusFor($role, $permissions);

        return [
            'role' => $role,
            'label' => $this->roleOptions()[$role] ?? $role,
            'permissions' => $permissions,
            'menus' => $menus,
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
        return match ($role) {
            'hrd' => ['create_user', 'suspend_driver', 'view_report', 'monitor_live_order', 'monitor_live_chat', 'internal_chat'],
            'manager' => ['create_user', 'suspend_driver', 'view_report', 'export_report', 'edit_tarif', 'monitor_live_order', 'monitor_live_chat', 'internal_chat'],
            'spv' => ['suspend_driver', 'unsuspend_driver', 'monitor_live_order', 'monitor_live_chat', 'edit_tarif', 'approve_cancel_order', 'reject_cancel_order', 'internal_chat'],
            'operator' => ['monitor_live_order', 'assign_driver', 'monitor_live_chat', 'approve_cancel_order', 'reject_cancel_order', 'edit_tarif', 'manual_order', 'internal_chat'],
            'eksekutor' => ['monitor_live_order', 'assign_driver', 'monitor_live_chat', 'approve_cancel_order', 'reject_cancel_order', 'manual_order', 'internal_chat'],
            'web_admin', 'cms_editor' => ['manage_cms', 'internal_chat'],
            default => ['internal_chat'],
        };
    }

    private function menusFor(string $role, array $permissions): array
    {
        $menus = ['Dashboard'];

        if (in_array('monitor_live_order', $permissions, true)) {
            $menus[] = 'Order Operations';
            $menus[] = 'Request Order';
        }

        if (in_array('monitor_live_chat', $permissions, true)) {
            $menus[] = 'Chat Monitor';
        }

        if (in_array('internal_chat', $permissions, true)) {
            $menus[] = 'Internal Chat';
        }

        if (in_array('manual_order', $permissions, true) || in_array($role, ['operator', 'eksekutor'], true)) {
            $menus[] = 'Manual Order';
        }

        if (in_array('create_user', $permissions, true)) {
            $menus[] = 'Users';
        }

        if (in_array('suspend_driver', $permissions, true) || in_array('assign_driver', $permissions, true)) {
            $menus[] = 'Driver Management';
        }

        if (in_array('view_report', $permissions, true)) {
            $menus[] = 'Reports';
        }

        if (in_array('edit_tarif', $permissions, true)) {
            $menus[] = 'Pricing & Policy';
        }

        if (in_array($role, ['manager', 'spv', 'operator', 'eksekutor'], true)) {
            $menus[] = 'Location Logs';
        }

        return array_values(array_unique($menus));
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
            'hrd' => ['Fokus pada user, driver status, dan laporan HR.', 'Tidak disarankan melihat setting tarif/global.'],
            'manager' => ['Melihat area/cabang sendiri dan laporan operasional.', 'Bisa evaluasi operator, driver, dan pricing policy sesuai izin.'],
            'spv' => ['Monitoring live order, suspend/release driver, dan approval cancel.', 'Tidak punya menu create user penuh.'],
            'operator' => ['Fokus live chat, manual order, order operations, dan assign driver.', 'Data mengikuti area/jadwal yang melekat pada akun.'],
            'eksekutor' => ['Fokus dispatch area, backup operator, dan pending order.', 'Tidak melihat laporan global atau area lain.'],
            default => ['Role CMS hanya untuk konten yang diizinkan.', 'Dashboard operasional utama disembunyikan.'],
        };
    }
}
