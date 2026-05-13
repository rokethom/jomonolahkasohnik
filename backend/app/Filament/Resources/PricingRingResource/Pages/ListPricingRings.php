<?php
declare(strict_types=1);
namespace App\Filament\Resources\PricingRingResource\Pages;
use App\Filament\Resources\PricingRingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
class ListPricingRings extends ListRecords { protected static string $resource = PricingRingResource::class; protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; } }
