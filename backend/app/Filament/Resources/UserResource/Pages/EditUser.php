<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    private ?int $driverBansosAmount = null;
    private ?bool $driverBpjsJhtEnabled = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resetPassword')
                ->label('Reset Password')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('resetPassword', $this->record))
                ->action(function (): void {
                    $password = UserResource::generatePassword();
                    $this->record->update(['password' => $password]);

                    Notification::make()
                        ->title('Password berhasil di-reset')
                        ->body("Password baru {$this->record->username}: {$password}")
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        abort_unless(Gate::allows('update', $this->record), 403);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('driver_bansos_amount', $data)) {
            $this->driverBansosAmount = $data['driver_bansos_amount'] !== null && $data['driver_bansos_amount'] !== ''
                ? (int) $data['driver_bansos_amount']
                : null;
        }

        if (array_key_exists('driver_bpjs_jht_enabled', $data)) {
            $this->driverBpjsJhtEnabled = (bool) $data['driver_bpjs_jht_enabled'];
        }

        unset($data['driver_bansos_amount'], $data['driver_bpjs_jht_enabled']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->record->role === UserRole::Driver) {
            $this->record->driver()->firstOrCreate([], [
                'is_available' => true,
                'status' => 'active',
                'bpjs_jht_enabled' => $this->driverBpjsJhtEnabled ?? true,
                'bansos_amount' => $this->driverBansosAmount,
            ])->update([
                'bpjs_jht_enabled' => $this->driverBpjsJhtEnabled ?? true,
                'bansos_amount' => $this->driverBansosAmount,
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
