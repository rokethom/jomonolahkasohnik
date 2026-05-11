<?php

namespace App\Filament\Resources\ZonePricingRuleResource\Pages;

use App\Filament\Resources\ZonePricingRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateZonePricingRule extends CreateRecord
{
    protected static string $resource = ZonePricingRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ZonePricingRuleResource::normalizeScopedData($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
