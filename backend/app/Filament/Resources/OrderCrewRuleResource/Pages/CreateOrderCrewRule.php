<?php

namespace App\Filament\Resources\OrderCrewRuleResource\Pages;

use App\Filament\Resources\OrderCrewRuleResource;
use App\Services\OrderCrewDecisionService;
use Filament\Resources\Pages\CreateRecord;

class CreateOrderCrewRule extends CreateRecord
{
    protected static string $resource = OrderCrewRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        app(OrderCrewDecisionService::class)->clearCache();
    }
}
