<?php

namespace App\Filament\Resources\AiAliasMapResource\Pages;

use App\Filament\Resources\AiAliasMapResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAiAliasMap extends EditRecord
{
    protected static string $resource = AiAliasMapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
