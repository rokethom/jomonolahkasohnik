<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LiveChatResource\Pages;
use App\Models\ChatConversation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class LiveChatResource extends Resource
{
    protected static ?string $model = ChatConversation::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Live Chat';

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('monitor_live_chat') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('type')->disabled(),
            Forms\Components\TextInput::make('status')->disabled(),
            Forms\Components\TextInput::make('sla_status')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('customer.name')->searchable(),
                Tables\Columns\TextColumn::make('driver.name')->searchable(),
                Tables\Columns\TextColumn::make('latestMessage.message')->limit(50),
                Tables\Columns\BadgeColumn::make('sla_status')
                    ->colors(['warning' => 'waiting', 'success' => 'on_time', 'danger' => 'late']),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options([
                    'customer_driver' => 'Customer Driver',
                    'customer_operator' => 'Customer Operator',
                    'driver_operator' => 'Driver Operator',
                ]),
                Tables\Filters\SelectFilter::make('sla_status')->options([
                    'waiting' => 'Waiting',
                    'on_time' => 'On time',
                    'late' => 'Late',
                ]),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLiveChats::route('/'),
            'view' => Pages\ViewLiveChat::route('/{record}'),
        ];
    }
}
