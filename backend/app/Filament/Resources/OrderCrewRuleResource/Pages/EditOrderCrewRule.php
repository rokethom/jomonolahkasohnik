<?php

namespace App\Filament\Resources\OrderCrewRuleResource\Pages;

use App\Filament\Resources\OrderCrewRuleResource;
use App\Services\OrderCrewDecisionService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrderCrewRule extends EditRecord
{
    protected static string $resource = OrderCrewRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->after(fn () => app(OrderCrewDecisionService::class)->clearCache()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        app(OrderCrewDecisionService::class)->clearCache();
    }
}
