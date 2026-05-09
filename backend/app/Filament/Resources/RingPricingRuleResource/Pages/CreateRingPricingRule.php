<?php

namespace App\Filament\Resources\RingPricingRuleResource\Pages;

use App\Filament\Resources\RingPricingRuleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateRingPricingRule extends CreateRecord
{
    protected static string $resource = RingPricingRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return $data;
    }
}
