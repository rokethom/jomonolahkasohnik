<?php

namespace App\Filament\Resources\HomeItemResource\Pages;

use App\Filament\Resources\HomeItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHomeItem extends EditRecord
{
    protected static string $resource = HomeItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
