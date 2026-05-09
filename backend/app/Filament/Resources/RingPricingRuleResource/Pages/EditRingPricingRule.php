<?php

namespace App\Filament\Resources\RingPricingRuleResource\Pages;

use App\Filament\Resources\RingPricingRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditRingPricingRule extends EditRecord
{
    protected static string $resource = RingPricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();

        return $data;
    }
}
