<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\HermesReportResource\Pages;
use App\Models\HermesReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HermesReportResource extends Resource
{
    protected static ?string $model = HermesReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'AI Reports';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager], true);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('command')->disabled(),
                Forms\Components\TextInput::make('model')->disabled(),
                Forms\Components\Textarea::make('instruction')->rows(4)->disabled()->columnSpanFull(),
                Forms\Components\KeyValue::make('context_summary')->disabled()->columnSpanFull(),
                Forms\Components\Textarea::make('report')->rows(24)->disabled()->columnSpanFull(),
                Forms\Components\Textarea::make('error_message')->rows(6)->disabled()->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('command')
                    ->label('Command')
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title())
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('model')->limit(34)->searchable(),
                Tables\Columns\TextColumn::make('user.name')->label('Actor')->searchable(),
                Tables\Columns\TextColumn::make('duration_ms')->label('Duration')->suffix(' ms')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHermesReports::route('/'),
            'view' => Pages\ViewHermesReport::route('/{record}'),
        ];
    }
}
