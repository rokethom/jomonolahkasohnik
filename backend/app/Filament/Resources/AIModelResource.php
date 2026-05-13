<?php
declare(strict_types=1);
namespace App\Filament\Resources;
use App\Filament\Resources\AIModelResource\Pages; use App\Models\AiModel; use Filament\Forms; use Filament\Forms\Form; use Filament\Resources\Resource; use Filament\Tables; use Filament\Tables\Table;
class AIModelResource extends Resource
{
    protected static ?string $model = AiModel::class; protected static ?string $navigationIcon = 'heroicon-o-circle-stack'; protected static ?string $navigationGroup = 'AI Management'; protected static ?string $navigationLabel = 'AI Models';
    public static function form(Form $form): Form { return $form->schema([Forms\Components\TextInput::make('name')->required(), Forms\Components\TextInput::make('provider')->default('native_laravel')->required(), Forms\Components\TextInput::make('model_key')->required(), Forms\Components\Textarea::make('configuration')->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : (string) ($state ?? ''))->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null), Forms\Components\Toggle::make('is_active')->default(true)]); }
    public static function table(Table $table): Table { return $table->columns([Tables\Columns\TextColumn::make('name')->searchable(), Tables\Columns\TextColumn::make('provider')->badge(), Tables\Columns\TextColumn::make('model_key'), Tables\Columns\IconColumn::make('is_active')->boolean()])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]); }
    public static function getPages(): array { return ['index' => Pages\ListAIModels::route('/'), 'create' => Pages\CreateAIModel::route('/create'), 'edit' => Pages\EditAIModel::route('/{record}/edit')]; }
}
