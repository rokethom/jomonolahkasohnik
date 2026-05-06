<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages;
use App\Models\Service;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Services';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Service Template')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('code')
                        ->required()
                        ->maxLength(10)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),
                    Forms\Components\Textarea::make('template_text')
                        ->label('Paste Template')
                        ->placeholder("Nama:\nHP:\nAlamat:\nBelikan:")
                        ->live(onBlur: false)
                        ->dehydrated(false)
                        ->rows(10)
                        ->afterStateUpdated(function (?string $state, Forms\Set $set): void {
                            $schema = self::parseTemplate($state ?? '');
                            $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            $set('form_schema_preview', $json);
                            $set('form_schema', $json);
                        })
                        ->hintAction(
                            Forms\Components\Actions\Action::make('generateJson')
                                ->label('Generate JSON')
                                ->icon('heroicon-o-sparkles')
                                ->action(function (Forms\Get $get, Forms\Set $set): void {
                                    $schema = self::parseTemplate((string) $get('template_text'));
                                    $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                    $set('form_schema_preview', $json);
                                    $set('form_schema', $json);
                                }),
                        ),
                    Forms\Components\Textarea::make('form_schema_preview')
                        ->label('Preview JSON')
                        ->rows(10)
                        ->disabled()
                        ->dehydrated(false)
                        ->formatStateUsing(fn ($state, Forms\Get $get): string => $state ?: (is_array($get('form_schema')) ? json_encode($get('form_schema'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $get('form_schema'))),
                    Forms\Components\Hidden::make('form_schema')
                        ->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $state)
                        ->dehydrateStateUsing(fn (?string $state): ?array => $state ? json_decode($state, true) : null)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('code')->badge()->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListServices::route('/'),
            'create' => Pages\CreateService::route('/create'),
            'edit' => Pages\EditService::route('/{record}/edit'),
        ];
    }

    public static function parseTemplate(string $template): array
    {
        $fields = collect(preg_split('/\r\n|\r|\n/', $template) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->map(function (string $line): array {
                $label = trim(str($line)->before(':')->toString());

                return [
                    'label' => $label,
                    'name' => str($label)->slug('_')->toString(),
                    'type' => str_contains(strtolower($label), 'belikan') ? 'textarea' : 'text',
                ];
            })
            ->values()
            ->all();

        return ['fields' => $fields];
    }
}
