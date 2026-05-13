<?php
declare(strict_types=1);
namespace App\Filament\Resources\PricingRingResource\Pages;
use App\Filament\Resources\PricingRingResource;
use Filament\Resources\Pages\CreateRecord;
class CreatePricingRing extends CreateRecord { protected static string $resource = PricingRingResource::class; protected function afterCreate(): void { PricingRingResource::clearCache(); } }
