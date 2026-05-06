<?php

namespace App\Filament\Resources\UserDeviceTokenResource\Pages;

use App\Filament\Resources\UserDeviceTokenResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUserDeviceTokens extends ListRecords
{
    protected static string $resource = UserDeviceTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => null),
        ];
    }
}
