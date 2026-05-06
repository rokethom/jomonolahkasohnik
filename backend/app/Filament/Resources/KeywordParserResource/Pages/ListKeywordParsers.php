<?php

namespace App\Filament\Resources\KeywordParserResource\Pages;

use App\Filament\Resources\KeywordParserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKeywordParsers extends ListRecords
{
    protected static string $resource = KeywordParserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tambah Keyword'),
        ];
    }
}
