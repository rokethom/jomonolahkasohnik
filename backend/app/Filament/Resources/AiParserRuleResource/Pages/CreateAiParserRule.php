<?php

namespace App\Filament\Resources\AiParserRuleResource\Pages;

use App\Filament\Resources\AiParserRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiParserRule extends CreateRecord
{
    protected static string $resource = AiParserRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
