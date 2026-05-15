<?php

namespace App\Filament\Resources\InternalChatRoomResource\Pages;

use App\Filament\Resources\InternalChatRoomResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInternalChatRoom extends EditRecord
{
    protected static string $resource = InternalChatRoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (): bool => InternalChatRoomResource::canDeleteAny()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
