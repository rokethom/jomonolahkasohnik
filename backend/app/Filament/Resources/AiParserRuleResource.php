<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiParserRuleResource\Pages;
use App\Models\AiParserRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AiParserRuleResource extends Resource
{
    protected static ?string $model = AiParserRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'AI Parser Memory';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Readonly AI Parser Result')
                    ->description('Data ini disimpan dari hasil AI parser yang valid. Sistem bisa memakai data ini lagi saat API key AI dimatikan untuk input yang sama.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('service_type')
                            ->label('Service')
                            ->disabled(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->disabled(),
                        Forms\Components\TextInput::make('provider')
                            ->disabled(),
                        Forms\Components\TextInput::make('model')
                            ->disabled(),
                        Forms\Components\Textarea::make('example_text')
                            ->rows(4)
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\KeyValue::make('ai_data')
                            ->label('Parser schema')
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('updated_at'))
            ->columns([
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Service')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('example_text')
                    ->label('Input user')
                    ->limit(70)
                    ->searchable(),
                Tables\Columns\TextColumn::make('ai_data.store_location')
                    ->label('Lokasi pembelian')
                    ->limit(32)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ai_data.pickup_address')
                    ->label('Alamat jemput')
                    ->limit(32)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ai_data.destination_address')
                    ->label('Alamat antar/tujuan')
                    ->limit(32)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('provider')
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('model')
                    ->limit(24)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hit_count')
                    ->label('Used')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_used_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('service_type')
                    ->label('Visible service')
                    ->placeholder('All services')
                    ->native(false)
                    ->options([
                        'DO' => 'DO',
                        'ojek' => 'Ojek',
                        'kurir' => 'Kurir',
                        'belanja' => 'Belanja',
                        'gift_order' => 'Gift Order',
                        'travel' => 'Travel',
                        'joker_mobil' => 'Joker Mobil',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(2)
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Detail'),
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (AiParserRule $record): string => $record->is_active ? 'Disable' : 'Enable')
                    ->icon(fn (AiParserRule $record): string => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (AiParserRule $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (AiParserRule $record): bool => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->bulkActions([])
            ->defaultSort('updated_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiParserRules::route('/'),
            'view' => Pages\ViewAiParserRule::route('/{record}'),
        ];
    }
}
