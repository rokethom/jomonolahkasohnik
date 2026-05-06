<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PricingKeywordRuleResource\Pages;
use App\Models\PricingKeywordRule;
use App\Services\PricingKeywordRuleService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PricingKeywordRuleResource extends Resource
{
    protected static ?string $model = PricingKeywordRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Keyword Charge Rules';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('edit_tarif') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Section::make('Rule')
                    ->description('Rule aktif dari database akan menggantikan fallback hardcoded lama. Jika tidak ada rule aktif, sistem otomatis fallback.')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: false),
                        Forms\Components\TextInput::make('keywords')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: false)
                            ->helperText("Bisa banyak keyword dipisah koma, contoh: 'pasar, roxy, royal'.")
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? app(PricingKeywordRuleService::class)->normalizeKeywordList($state) : null),
                        Forms\Components\TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->live(onBlur: false),
                        Forms\Components\Select::make('service_scopes')
                            ->label('Service Scope')
                            ->multiple()
                            ->native(false)
                            ->options(PricingKeywordRule::SERVICE_SCOPES)
                            ->helperText('Kosongkan atau pilih All services untuk berlaku ke semua layanan.')
                            ->live(),
                        Forms\Components\TextInput::make('priority')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: false)
                            ->helperText('Urutan evaluasi rule. Semua rule yang match akan dijumlahkan.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->live(),
                        Forms\Components\Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Live Preview')
                    ->description('Cek apakah keyword pada rule ini akan menambah service charge.')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\Select::make('preview_service')
                            ->label('Service')
                            ->options(PricingKeywordRule::SERVICE_SCOPES)
                            ->default('belanja')
                            ->native(false)
                            ->dehydrated(false)
                            ->live(),
                        Forms\Components\Textarea::make('preview_text')
                            ->label('Contoh text order')
                            ->default('Belikan sayur di pasar')
                            ->rows(5)
                            ->dehydrated(false)
                            ->live(onBlur: false),
                        Forms\Components\Placeholder::make('preview_result')
                            ->label('Result')
                            ->content(fn (Forms\Get $get): string => self::previewText($get)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('keywords')->badge()->color('info')->searchable(),
                Tables\Columns\TextColumn::make('amount')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('service_scopes')
                    ->label('Scope')
                    ->formatStateUsing(fn ($state): string => collect($state ?: ['all'])->map(fn (string $scope): string => PricingKeywordRule::SERVICE_SCOPES[$scope] ?? $scope)->implode(', '))
                    ->wrap(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('priority')->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPricingKeywordRules::route('/'),
            'create' => Pages\CreatePricingKeywordRule::route('/create'),
            'edit' => Pages\EditPricingKeywordRule::route('/{record}/edit'),
        ];
    }

    private static function previewText(Forms\Get $get): string
    {
        $keywords = array_filter(array_map('trim', explode(',', mb_strtolower((string) $get('keywords')))));
        $text = mb_strtolower((string) $get('preview_text'));
        $scope = app(PricingKeywordRuleService::class)->normalizeScopes($get('service_scopes')) ?: ['all'];
        $service = (string) ($get('preview_service') ?: 'belanja');
        $scopeMatch = in_array('all', $scope, true) || in_array(app(PricingKeywordRuleService::class)->normalizeScopes([$service])[0] ?? $service, $scope, true);
        $matched = $scopeMatch && collect($keywords)->contains(fn (string $keyword): bool => $keyword !== '' && str_contains($text, $keyword));
        $amount = (int) ($get('amount') ?? 0);

        return $matched
            ? 'MATCH - tambahan Rp '.number_format($amount, 0, ',', '.').' akan masuk ke service charge.'
            : 'NO MATCH - text atau service scope belum cocok.';
    }
}
