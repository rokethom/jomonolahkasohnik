<?php
declare(strict_types=1);
namespace App\Filament\Resources;
use App\Filament\Resources\SpatialAnalyticsResource\Pages; use App\Models\GeojsonRegion; use Filament\Forms\Form; use Filament\Resources\Resource; use Filament\Tables; use Filament\Tables\Table;
class SpatialAnalyticsResource extends Resource
{
    protected static ?string $model = GeojsonRegion::class; protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line'; protected static ?string $navigationGroup = 'Spatial Management'; protected static ?string $navigationLabel = 'Spatial Analytics';
    public static function form(Form $form): Form { return $form->schema([]); }
    public static function table(Table $table): Table { return $table->columns([Tables\Columns\TextColumn::make('branch.name')->label('Branch'), Tables\Columns\TextColumn::make('area.name')->label('Area'), Tables\Columns\TextColumn::make('name'), Tables\Columns\TextColumn::make('geometry_type')->badge(), Tables\Columns\TextColumn::make('centroid_lat'), Tables\Columns\TextColumn::make('centroid_lng'), Tables\Columns\TextColumn::make('version')]); }
    public static function canCreate(): bool { return false; } public static function canEdit($record): bool { return false; }
    public static function getPages(): array { return ['index' => Pages\ListSpatialAnalytics::route('/')]; }
}
