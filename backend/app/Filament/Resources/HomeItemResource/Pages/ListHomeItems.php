<?php

namespace App\Filament\Resources\HomeItemResource\Pages;

use App\Filament\Resources\HomeItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHomeItems extends ListRecords
{
    protected static string $resource = HomeItemResource::class;

    protected static string $view = 'filament.resources.home-cms.list-with-preview';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
