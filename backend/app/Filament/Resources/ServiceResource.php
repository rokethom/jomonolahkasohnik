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
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Urutan tampilan')
                        ->helperText('Bisa diatur cepat dengan drag & drop di halaman list.')
                        ->numeric()
                        ->default(0)
                        ->required(),
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
            Forms\Components\Section::make('WhatsApp Handling')
                ->description('Atur handling khusus layanan, termasuk layanan yang hanya muncul untuk customer luar area.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('whatsapp_redirect_enabled')
                        ->label('Arahkan customer ke WhatsApp')
                        ->default(false)
                        ->live(),
                    Forms\Components\Toggle::make('outside_area_only')
                        ->label('Layanan khusus luar area')
                        ->helperText('Jika aktif, layanan ini menjadi pilihan untuk customer di luar cabang/geofence.')
                        ->default(false),
                    Forms\Components\TextInput::make('whatsapp_number')
                        ->label('Nomor WhatsApp')
                        ->placeholder('62812xxxxxxx')
                        ->helperText('Gunakan format internasional tanpa +. Contoh: 6281299232918.')
                        ->maxLength(32)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? preg_replace('/\D+/', '', $state) : null),
                    Forms\Components\Textarea::make('whatsapp_message_template')
                        ->label('Pesan pembuka')
                        ->placeholder('Halo JojoApp, saya ingin pesan layanan {service_name}. Nama saya {customer_name}.')
                        ->helperText('Placeholder: {service_name}, {service_code}, {customer_name}, {customer_phone}.')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('code')->badge()->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
                Tables\Columns\IconColumn::make('whatsapp_redirect_enabled')->label('WA')->boolean()->sortable(),
                Tables\Columns\IconColumn::make('outside_area_only')->label('Luar Area')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('whatsapp_number')->label('WA Number')->toggleable(),
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
