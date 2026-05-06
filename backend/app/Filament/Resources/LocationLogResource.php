<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationLogResource\Pages;
use App\Models\Branch;
use App\Models\LocationLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationLogResource extends Resource
{
    protected static ?string $model = LocationLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Location';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Location')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('user.name')
                            ->label('User')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('branch.name')
                            ->label('Branch')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('geofenceArea.name')
                            ->label('Geofence')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('latitude')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('longitude')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('accuracy')
                            ->suffix('m')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Toggle::make('is_valid')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Toggle::make('is_suspicious')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Textarea::make('suspicion_reason')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Forms\Components\View::make('filament.forms.components.location-log-map')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('latitude')
                    ->sortable(),
                Tables\Columns\TextColumn::make('longitude')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_valid')
                    ->boolean()
                    ->label('Valid'),
                Tables\Columns\IconColumn::make('is_suspicious')
                    ->boolean()
                    ->label('Suspicious')
                    ->trueColor('danger')
                    ->falseColor('success'),
                Tables\Columns\TextColumn::make('branch_id')
                    ->label('Branch')
                    ->formatStateUsing(fn (LocationLog $record): string => $record->branch?->display_name ?? '-')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('geofenceArea.name')
                    ->label('Geofence')
                    ->placeholder('-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('suspicion_reason')
                    ->limit(32)
                    ->placeholder('-')
                    ->color('danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordClasses(fn (LocationLog $record): string => $record->is_suspicious ? 'bg-danger-50 dark:bg-danger-950/20' : '')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_suspicious')
                    ->label('Suspicious'),
                Tables\Filters\TernaryFilter::make('is_valid')
                    ->label('Valid'),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn (): array => self::branchOptions())
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'branch', 'geofenceArea']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocationLogs::route('/'),
            'view' => Pages\ViewLocationLog::route('/{record}'),
        ];
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }
}
