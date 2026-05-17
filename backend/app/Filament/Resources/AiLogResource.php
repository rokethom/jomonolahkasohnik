<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiLogResource\Pages;
use App\Models\AiLog;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AiLogResource extends Resource
{
    protected static ?string $model = AiLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'AI Logs';

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('edit_tarif') === true
            || auth()->user()?->hasPermission('manage_cms') === true
            || auth()->user()?->hasPermission('manage_system_settings') === true;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Section::make('AI Log')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('source')->disabled(),
                    Forms\Components\TextInput::make('event')->disabled(),
                    Forms\Components\TextInput::make('status')->disabled(),
                    Forms\Components\TextInput::make('queue')->disabled(),
                    Forms\Components\TextInput::make('provider')->disabled(),
                    Forms\Components\TextInput::make('model')->disabled(),
                    Forms\Components\Textarea::make('message')->rows(3)->columnSpanFull()->disabled(),
                    Forms\Components\Textarea::make('error_message')->rows(3)->columnSpanFull()->disabled(),
                    Forms\Components\Textarea::make('input_payload')
                        ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
                        ->rows(10)
                        ->columnSpanFull()
                        ->disabled(),
                    Forms\Components\Textarea::make('output_payload')
                        ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
                        ->rows(10)
                        ->columnSpanFull()
                        ->disabled(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'customer', 'actor', 'order'])->latest())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('AI Source')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Event')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AiLog::STATUS_SUCCESS => 'success',
                        AiLog::STATUS_FAILED => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('queue')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Cabang')
                    ->searchable(),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Actor')
                    ->searchable(),
                Tables\Columns\TextColumn::make('order.order_code')
                    ->label('Order')
                    ->searchable(),
                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('ms')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('message')
                    ->limit(60)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(80)
                    ->color('danger')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        AiLog::STATUS_STARTED => 'Started',
                        AiLog::STATUS_SUCCESS => 'Success',
                        AiLog::STATUS_FAILED => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('source')
                    ->options(fn (): array => AiLog::query()
                        ->whereNotNull('source')
                        ->distinct()
                        ->orderBy('source')
                        ->pluck('source', 'source')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiLogs::route('/'),
            'view' => Pages\ViewAiLog::route('/{record}'),
        ];
    }
}
