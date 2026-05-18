<?php

namespace App\Filament\Resources\AiParserRuleResource\Pages;

use App\Filament\Resources\AiParserRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAiParserRule extends EditRecord
{
    protected static string $resource = AiParserRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
