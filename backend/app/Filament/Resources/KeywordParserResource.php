<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KeywordParserResource\Pages;
use App\Models\KeywordParser;
use App\Services\KeywordParserService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class KeywordParserResource extends Resource
{
    protected static ?string $model = KeywordParser::class;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = 'JojoBot';

    protected static ?string $navigationLabel = 'Keyword & Form Builder';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Section::make('Keyword & Form Builder')
                    ->description('Atur keyword, response JojoBot, dan form inline tanpa menghapus parser lama.')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\TextInput::make('keyword')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: false)
                            ->unique(ignoreRecord: true)
                            ->helperText("Bisa satu atau banyak keyword dipisah koma, contoh: 'belanja, pasar, belikan'.")
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? app(KeywordParserService::class)->normalizeKeywordList($state) : null),
                        Forms\Components\Select::make('service_type')
                            ->required()
                            ->options(KeywordParser::SERVICE_TYPES)
                            ->native(false)
                            ->live()
                            ->helperText('Mapping layanan internal yang akan dipakai JojoBot.'),
                        Forms\Components\Select::make('parser_type')
                            ->required()
                            ->options(KeywordParser::PARSER_TYPES)
                            ->default('simple')
                            ->native(false)
                            ->live()
                            ->helperText('Simple hanya memakai response template. Advanced mencoba NLP parser existing setelah keyword match.'),
                        Forms\Components\TextInput::make('priority')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: false)
                            ->helperText('Angka lebih besar diprioritaskan saat banyak keyword cocok.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->live(),
                        Forms\Components\Textarea::make('response_template')
                            ->label('Response JojoBot')
                            ->required()
                            ->rows(5)
                            ->live(onBlur: false)
                            ->helperText('Pesan yang akan dikirim oleh JojoBot.'),
                        Forms\Components\Repeater::make('form_schema.fields')
                            ->label('Builder Form')
                            ->addActionLabel('Tambah Field')
                            ->minItems(1)
                            ->columns(2)
                            ->live(onBlur: false)
                            ->helperText('Field name harus unik. Form kosong tetap aman, tapi minimal 1 field disarankan untuk form dinamis.')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->required()
                                    ->live(onBlur: false)
                                    ->placeholder('Alamat Jemput'),
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->alphaDash()
                                    ->live(onBlur: false)
                                    ->placeholder('pickup'),
                                Forms\Components\Select::make('type')
                                    ->required()
                                    ->native(false)
                                    ->default('text')
                                    ->live()
                                    ->options([
                                        'text' => 'Text',
                                        'textarea' => 'Textarea',
                                        'number' => 'Number',
                                        'select' => 'Select',
                                        'phone' => 'Phone',
                                    ]),
                                Forms\Components\Toggle::make('required')
                                    ->default(false)
                                    ->live(),
                                Forms\Components\TagsInput::make('options')
                                    ->label('Select Options')
                                    ->visible(fn (Forms\Get $get): bool => $get('type') === 'select')
                                    ->columnSpanFull()
                                    ->placeholder('Tambah opsi'),
                            ]),
                    ]),
                Forms\Components\Section::make('Live Preview')
                    ->description('Simulasi chat, balasan JojoBot, render form, dan preview hasil input.')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\TextInput::make('preview_input')
                            ->label('Contoh input user')
                            ->placeholder('travel')
                            ->live(onBlur: false)
                            ->dehydrated(false)
                            ->helperText('Isi contoh pesan user untuk mengecek match keyword.'),
                        Forms\Components\Placeholder::make('preview_result')
                            ->label('Preview JojoBot')
                            ->content(fn (Forms\Get $get): HtmlString => self::previewHtml($get)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('keyword')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('service_type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('parser_type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'advanced' ? 'warning' : 'success')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\SelectFilter::make('service_type')
                    ->options(KeywordParser::SERVICE_TYPES),
                Tables\Filters\SelectFilter::make('parser_type')
                    ->options(KeywordParser::PARSER_TYPES),
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
            'index' => Pages\ListKeywordParsers::route('/'),
            'create' => Pages\CreateKeywordParser::route('/create'),
            'edit' => Pages\EditKeywordParser::route('/{record}/edit'),
        ];
    }

    public static function rules(): array
    {
        return [
            'keyword' => ['required', 'string', Rule::unique('keyword_parsers', 'keyword')],
            'priority' => ['required', 'numeric'],
        ];
    }

    private static function previewHtml(Forms\Get $get): HtmlString
    {
        $preview = app(KeywordParserService::class)->preview([
            'keyword' => $get('keyword'),
            'service_type' => $get('service_type'),
            'parser_type' => $get('parser_type'),
            'response_template' => $get('response_template'),
            'form_schema' => $get('form_schema'),
        ], $get('preview_input'));

        $sample = e($preview['sample_input']);
        $keyword = e($preview['keyword'] ?: '-');
        $response = nl2br(e($preview['response'] ?: 'Response JojoBot akan tampil di sini.'));
        $fields = collect($preview['form_schema']['fields'] ?? []);
        $formHtml = $fields->isEmpty()
            ? '<div style="padding:12px;border:1px dashed #cbd5e1;border-radius:10px;color:#64748b;font-size:13px">Belum ada form schema.</div>'
            : $fields->map(function (array $field): string {
                $label = e($field['label']);
                $name = e($field['name']);
                $type = e($field['type']);
                $required = $field['required'] ? '<span style="color:#dc2626">*</span>' : '';
                $input = match ($field['type']) {
                    'textarea' => '<textarea disabled style="min-height:70px;width:100%;border:1px solid #dbe4ea;border-radius:8px;padding:9px 10px;background:#f8fafc"></textarea>',
                    'select' => '<select disabled style="height:38px;width:100%;border:1px solid #dbe4ea;border-radius:8px;padding:0 10px;background:#f8fafc"><option>'.e(($field['options'][0] ?? 'Pilih opsi')).'</option></select>',
                    'number' => '<input disabled type="number" style="height:38px;width:100%;border:1px solid #dbe4ea;border-radius:8px;padding:0 10px;background:#f8fafc" />',
                    'phone' => '<input disabled type="tel" value="Auto fill profile phone" style="height:38px;width:100%;border:1px solid #dbe4ea;border-radius:8px;padding:0 10px;background:#f8fafc" />',
                    default => '<input disabled type="text" style="height:38px;width:100%;border:1px solid #dbe4ea;border-radius:8px;padding:0 10px;background:#f8fafc" />',
                };

                return <<<HTML
<label style="display:grid;gap:5px;font-size:13px;font-weight:700;color:#172033">
  <span>{$label} {$required} <small style="color:#64748b;font-weight:600">{$name} / {$type}</small></span>
  {$input}
</label>
HTML;
            })->implode('');
        $outputPreview = $fields->map(fn (array $field): string => e($field['label']).': '.match ($field['name']) {
            'nama', 'name' => 'Auto fill profile name',
            'hp', 'phone', 'wa' => 'Auto fill profile phone',
            'alamat', 'address' => 'Auto fill profile address',
            default => '['.e($field['name']).']',
        })->implode('<br>');
        $badgeColor = $preview['matched'] ? '#16a34a' : '#dc2626';
        $matchLabel = $preview['matched'] ? 'MATCH' : 'NO MATCH';
        $highlighted = $sample;
        foreach (array_filter(array_map('trim', explode(',', (string) $preview['keyword']))) as $keywordItem) {
            $highlighted = preg_replace('/('.preg_quote($keywordItem, '/').')/iu', '<mark>$1</mark>', $highlighted) ?? $highlighted;
        }

        return new HtmlString(<<<HTML
<div style="display:grid;gap:12px">
  <div style="border:1px solid #dbeafe;border-radius:10px;padding:12px;background:#f8fbff">
    <div style="font-size:12px;font-weight:700;color:#64748b">Contoh Input</div>
    <div style="margin-top:4px;font-size:15px;color:#0f172a">{$highlighted}</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <span style="display:inline-flex;padding:4px 9px;border-radius:999px;background:{$badgeColor};color:white;font-size:12px;font-weight:800">{$matchLabel}</span>
    <span style="display:inline-flex;padding:4px 9px;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:12px;font-weight:800">Keyword: {$keyword}</span>
    <span style="display:inline-flex;padding:4px 9px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:12px;font-weight:800">Service: {$preview['service_type']}</span>
    <span style="display:inline-flex;padding:4px 9px;border-radius:999px;background:#ede9fe;color:#5b21b6;font-size:12px;font-weight:800">Mode: {$preview['parser_mode']}</span>
  </div>
  <div style="border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:white">
    <div style="font-size:12px;font-weight:700;color:#64748b">Response JojoBot</div>
    <div style="margin-top:6px;white-space:normal;line-height:1.55;color:#111827">{$response}</div>
  </div>
  <div style="border:1px solid #dbeafe;border-radius:12px;padding:12px;background:#ffffff;display:grid;gap:10px">
    <div style="font-size:12px;font-weight:800;color:#0369a1">Inline Dynamic Form</div>
    {$formHtml}
    <div style="display:flex;gap:8px">
      <button type="button" style="height:34px;border:0;border-radius:999px;background:#e0f2fe;color:#0369a1;padding:0 12px;font-weight:800">+ Tambah Titik</button>
      <button type="button" style="height:34px;border:0;border-radius:999px;background:#00b7ff;color:#fff;padding:0 12px;font-weight:800">Preview Order</button>
    </div>
  </div>
  <div style="border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:#f8fafc">
    <div style="font-size:12px;font-weight:700;color:#64748b">Preview hasil input</div>
    <div style="margin-top:6px;line-height:1.55;color:#111827">{$outputPreview}</div>
  </div>
</div>
HTML);
    }
}
