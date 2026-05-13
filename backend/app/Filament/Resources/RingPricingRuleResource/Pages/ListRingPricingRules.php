<?php

namespace App\Filament\Resources\RingPricingRuleResource\Pages;

use App\Filament\Resources\RingPricingRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRingPricingRules extends ListRecords
{
    protected static string $resource = RingPricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
