<?php

namespace App\Filament\Resources\InternalChatRoomResource\Pages;

use App\Filament\Resources\InternalChatRoomResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInternalChatRooms extends ListRecords
{
    protected static string $resource = InternalChatRoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
