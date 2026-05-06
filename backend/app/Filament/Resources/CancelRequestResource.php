<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CancelRequestResource\Pages;
use App\Models\CancelRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CancelRequestResource extends Resource
{
    protected static ?string $model = CancelRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Operations';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('reason')->disabled(),
            Forms\Components\TextInput::make('status')->disabled(),
            Forms\Components\TextInput::make('image_url')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order.order_code')->searchable(),
                Tables\Columns\TextColumn::make('requester.name')->label('Requested by')->searchable(),
                Tables\Columns\TextColumn::make('reason')->limit(40),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors(['warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected']),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CancelRequest $record): bool => $record->status === 'pending')
                    ->action(fn (CancelRequest $record) => app(\App\Services\CancelService::class)->approve($record, auth()->user())),
                Tables\Actions\Action::make('reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CancelRequest $record): bool => $record->status === 'pending')
                    ->action(fn (CancelRequest $record) => app(\App\Services\CancelService::class)->reject($record, auth()->user())),
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCancelRequests::route('/'),
            'view' => Pages\ViewCancelRequest::route('/{record}'),
        ];
    }
}
