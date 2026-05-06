<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Enums\UserRole;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private ?string $generatedPassword = null;
    private ?int $driverBansosAmount = null;
    private bool $driverBpjsJhtEnabled = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->driverBansosAmount = isset($data['driver_bansos_amount']) && $data['driver_bansos_amount'] !== ''
            ? (int) $data['driver_bansos_amount']
            : null;
        $this->driverBpjsJhtEnabled = (bool) ($data['driver_bpjs_jht_enabled'] ?? true);
        unset($data['driver_bansos_amount'], $data['driver_bpjs_jht_enabled']);

        if (filled($data['password'] ?? null)) {
            $this->generatedPassword = $data['password'];
        } else {
            $this->generatedPassword = UserResource::generatePassword();
            $data['password'] = $this->generatedPassword;
        }

        return $data;
    }

    protected function beforeCreate(): void
    {
        abort_unless(Gate::allows('create', User::class), 403);
    }

    protected function afterCreate(): void
    {
        if ($this->record->role === UserRole::Driver) {
            $this->record->driver()->firstOrCreate([], [
                'is_available' => true,
                'status' => 'active',
                'bpjs_jht_enabled' => $this->driverBpjsJhtEnabled,
                'bansos_amount' => $this->driverBansosAmount,
            ]);
        }

        Notification::make()
            ->title('User berhasil dibuat')
            ->body("Password sementara {$this->record->username}: {$this->generatedPassword}")
            ->success()
            ->persistent()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
