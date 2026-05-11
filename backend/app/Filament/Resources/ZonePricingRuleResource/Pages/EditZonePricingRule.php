<?php

namespace App\Filament\Resources\ZonePricingRuleResource\Pages;

use App\Filament\Resources\ZonePricingRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditZonePricingRule extends EditRecord
{
    protected static string $resource = ZonePricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ZonePricingRuleResource::normalizeScopedData($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
