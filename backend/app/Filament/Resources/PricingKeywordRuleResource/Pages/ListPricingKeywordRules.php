<?php

namespace App\Filament\Resources\PricingKeywordRuleResource\Pages;

use App\Filament\Resources\PricingKeywordRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPricingKeywordRules extends ListRecords
{
    protected static string $resource = PricingKeywordRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add Keyword Rule'),
        ];
    }
}
