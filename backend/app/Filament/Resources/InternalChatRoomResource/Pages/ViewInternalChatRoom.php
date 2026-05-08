<?php

namespace App\Filament\Resources\InternalChatRoomResource\Pages;

use App\Filament\Resources\InternalChatRoomResource;
use App\Models\InternalChatMessage;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewInternalChatRoom extends ViewRecord
{
    protected static string $resource = InternalChatRoomResource::class;

    protected static string $view = 'filament.resources.internal-chat-room.view';

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function latestMessages(): array
    {
        return $this->record->messages()
            ->with('sender:id,name,role')
            ->latest()
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (InternalChatMessage $message): array => [
                'sender' => $message->sender?->name ?? 'System',
                'role' => $message->sender?->role?->value ?? '-',
                'message' => $message->message,
                'time' => $message->created_at?->format('d M H:i'),
            ])
            ->values()
            ->all();
    }
}
