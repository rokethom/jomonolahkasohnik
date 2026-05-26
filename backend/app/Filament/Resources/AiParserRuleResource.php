<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiParserRuleResource\Pages;
use App\Filament\Support\RequiresAiDataAccess;
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
    use RequiresAiDataAccess;

    protected static ?string $model = AiParserRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'AI Parser';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('AI Parser CMS')
                    ->description('Tambah atau koreksi parser memory JOJOBOT. Data ini dibaca sebelum/fallback AI supaya parser tidak hanya bergantung hardcode.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('service_type')
                            ->label('Service')
                            ->required()
                            ->maxLength(30)
                            ->placeholder('ojek, delivery, joker_mobil'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Forms\Components\TextInput::make('provider')
                            ->default('manual_cms')
                            ->maxLength(40),
                        Forms\Components\TextInput::make('model')
                            ->default('cms')
                            ->maxLength(120),
                        Forms\Components\Textarea::make('normalized_text')
                            ->label('Normalized text')
                            ->rows(3)
                            ->helperText('Opsional. Kosongkan agar sistem membuat normalisasi otomatis dari contoh input.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('example_text')
                            ->label('Contoh input user')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('ai_data')
                            ->label('Parser result')
                            ->required()
                            ->rows(10)
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
                                : (string) ($state ?: '{}'))
                            ->dehydrateStateUsing(fn (mixed $state): array => json_decode((string) $state, true) ?: [])
                            ->rules(['json'])
                            ->helperText('Isi JSON parser. Contoh: {"service_type":"ojek","pickup_address":"rumah saya","destination_address":"panarukan","customer_name":"Budi","customer_phone":"08xx","notes":"..."}')
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (AiParserRule $record): string => $record->is_active ? 'Disable' : 'Enable')
                    ->icon(fn (AiParserRule $record): string => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (AiParserRule $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (AiParserRule $record): bool => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiParserRules::route('/'),
            'create' => Pages\CreateAiParserRule::route('/create'),
            'edit' => Pages\EditAiParserRule::route('/{record}/edit'),
            'view' => Pages\ViewAiParserRule::route('/{record}'),
        ];
    }
}
