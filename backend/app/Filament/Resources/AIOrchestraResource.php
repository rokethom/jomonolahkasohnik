<?php
declare(strict_types=1);
namespace App\Filament\Resources;
use App\Filament\Resources\AIOrchestraResource\Pages; use App\Models\AiLog; use Filament\Forms\Form; use Filament\Resources\Resource; use Filament\Tables; use Filament\Tables\Table;
class AIOrchestraResource extends Resource
{
    protected static ?string $model = AiLog::class; protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square'; protected static ?string $navigationGroup = 'AI Management'; protected static ?string $navigationLabel = 'AI Orchestra';
    public static function form(Form $form): Form { return $form->schema([]); }
    public static function table(Table $table): Table { return $table->defaultSort('created_at', 'desc')->columns([Tables\Columns\TextColumn::make('workflow'), Tables\Columns\TextColumn::make('agent'), Tables\Columns\TextColumn::make('status')->badge(), Tables\Columns\TextColumn::make('duration_ms'), Tables\Columns\TextColumn::make('created_at')->dateTime()]); }
    public static function canCreate(): bool { return false; } public static function canEdit($record): bool { return false; }
    public static function getPages(): array { return ['index' => Pages\ListAIOrchestra::route('/')]; }
}
