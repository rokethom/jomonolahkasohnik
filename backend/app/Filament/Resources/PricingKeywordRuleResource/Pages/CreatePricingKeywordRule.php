<?php

namespace App\Filament\Resources\PricingKeywordRuleResource\Pages;

use App\Filament\Resources\PricingKeywordRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingKeywordRule extends CreateRecord
{
    protected static string $resource = PricingKeywordRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
