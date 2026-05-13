<?php
declare(strict_types=1);
namespace App\Filament\Resources;
use App\Filament\Resources\AIRuleResource\Pages;
use App\Models\AiRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
class AIRuleResource extends Resource
{
    protected static ?string $model = AiRule::class; protected static ?string $navigationIcon = 'heroicon-o-cpu-chip'; protected static ?string $navigationGroup = 'AI Management'; protected static ?string $navigationLabel = 'AI Rules';
    public static function form(Form $form): Form { return $form->schema([Forms\Components\TextInput::make('name')->required(), Forms\Components\TextInput::make('code')->required(), Forms\Components\TextInput::make('agent')->required(), Forms\Components\Textarea::make('conditions')->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : (string) ($state ?? ''))->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null), Forms\Components\Textarea::make('actions')->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : (string) ($state ?? ''))->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null), Forms\Components\TextInput::make('priority')->numeric()->default(0), Forms\Components\Toggle::make('is_active')->default(true)]); }
    public static function table(Table $table): Table { return $table->columns([Tables\Columns\TextColumn::make('name')->searchable(), Tables\Columns\TextColumn::make('code'), Tables\Columns\TextColumn::make('agent')->badge(), Tables\Columns\TextColumn::make('priority')->sortable(), Tables\Columns\IconColumn::make('is_active')->boolean()])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]); }
    public static function getPages(): array { return ['index' => Pages\ListAIRules::route('/'), 'create' => Pages\CreateAIRule::route('/create'), 'edit' => Pages\EditAIRule::route('/{record}/edit')]; }
}
