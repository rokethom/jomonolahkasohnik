<?php

namespace App\Filament\Resources\ChatStickerResource\Pages;

use App\Filament\Resources\ChatStickerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChatSticker extends EditRecord
{
    protected static string $resource = ChatStickerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
