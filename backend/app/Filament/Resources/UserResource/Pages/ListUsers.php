<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportCurrentView')
                ->label('Export current view')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadUsers(
                    $this->getFilteredSortedTableQuery(),
                    $this->exportFilename('current-view'),
                )),
            Actions\Action::make('exportByRole')
                ->label('Export by role')
                ->icon('heroicon-o-funnel')
                ->color('info')
                ->form([
                    \Filament\Forms\Components\Select::make('role')
                        ->label('Visible role')
                        ->options(UserResource::roleOptions())
                        ->native(false)
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): StreamedResponse {
                    $role = (string) $data['role'];

                    return $this->downloadUsers(
                        UserResource::getEloquentQuery()->where('role', $role),
                        $this->exportFilename($role),
                    );
                }),
            Actions\CreateAction::make()
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    private function downloadUsers(Builder $query, string $filename): StreamedResponse
    {
        $headers = [
            'Username',
            'Name',
            'Role',
            'Branch',
            'Status',
            'Driver Active',
            'Email',
            'Phone',
            'Created At',
        ];

        return response()->streamDownload(function () use ($query, $headers): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            $query
                ->with(['branch', 'driver'])
                ->orderBy('created_at', 'desc')
                ->chunk(200, function ($users) use ($handle): void {
                    foreach ($users as $user) {
                        /** @var User $user */
                        $role = $user->role instanceof UserRole ? $user->role : UserRole::tryFrom((string) $user->role);

                        fputcsv($handle, [
                            $user->username,
                            $user->name,
                            $role?->label() ?? (string) $user->role,
                            $user->branch?->display_name ?? 'No branch',
                            $user->is_suspended ? 'suspended' : ($user->is_active ? 'active' : 'inactive'),
                            $user->driver?->is_available ? 'yes' : 'no',
                            $user->email,
                            $user->phone,
                            $user->created_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportFilename(string $scope): string
    {
        return 'users-'.str($scope)->slug()->toString().'-'.now()->format('Ymd-His').'.csv';
    }
}
