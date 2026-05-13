<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeojsonRegionResource\Pages;

use App\Filament\Resources\GeojsonRegionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGeojsonRegions extends ListRecords
{
    protected static string $resource = GeojsonRegionResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
