<?php

namespace App\Filament\Resources\OrderCrewRuleResource\Pages;

use App\Filament\Resources\OrderCrewRuleResource;
use App\Services\OrderCrewDecisionService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrderCrewRules extends ListRecords
{
    protected static string $resource = OrderCrewRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->after(fn () => app(OrderCrewDecisionService::class)->clearCache()),
        ];
    }
}
