<?php

namespace App\Filament\Resources\InternalChatRoomResource\Pages;

use App\Filament\Resources\InternalChatRoomResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInternalChatRoom extends CreateRecord
{
    protected static string $resource = InternalChatRoomResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
