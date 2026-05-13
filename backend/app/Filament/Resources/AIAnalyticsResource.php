<?php
declare(strict_types=1);
namespace App\Filament\Resources;
use App\Filament\Resources\AIAnalyticsResource\Pages; use App\Models\AiLog; use Filament\Forms\Form; use Filament\Resources\Resource; use Filament\Tables; use Filament\Tables\Table;
class AIAnalyticsResource extends Resource
{
    protected static ?string $model = AiLog::class; protected static ?string $navigationIcon = 'heroicon-o-chart-pie'; protected static ?string $navigationGroup = 'AI Management'; protected static ?string $navigationLabel = 'AI Analytics';
    public static function form(Form $form): Form { return $form->schema([]); }
    public static function table(Table $table): Table { return $table->defaultSort('created_at', 'desc')->columns([Tables\Columns\TextColumn::make('workflow'), Tables\Columns\TextColumn::make('agent'), Tables\Columns\TextColumn::make('duration_ms')->sortable(), Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()]); }
    public static function canCreate(): bool { return false; } public static function canEdit($record): bool { return false; }
    public static function getPages(): array { return ['index' => Pages\ListAIAnalytics::route('/')]; }
}
