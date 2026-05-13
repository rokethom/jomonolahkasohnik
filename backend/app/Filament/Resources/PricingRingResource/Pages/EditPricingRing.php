<?php
declare(strict_types=1);
namespace App\Filament\Resources\PricingRingResource\Pages;
use App\Filament\Resources\PricingRingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
class EditPricingRing extends EditRecord { protected static string $resource = PricingRingResource::class; protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; } protected function afterSave(): void { PricingRingResource::clearCache(); } }
