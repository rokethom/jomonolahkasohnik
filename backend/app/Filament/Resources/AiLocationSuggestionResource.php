<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiLocationSuggestionResource\Pages;
use App\Models\AiLocationSuggestion;
use App\Models\Area;
use App\Models\Branch;
use App\Services\AiLocationLearningService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AiLocationSuggestionResource extends Resource
{
    protected static ?string $model = AiLocationSuggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'AI Location Learning';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('edit_tarif') === true || auth()->user()?->hasPermission('manage_cms') === true;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Section::make('Suggestion')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('location_text')->required()->maxLength(255),
                    Forms\Components\Select::make('role')->options(['pickup' => 'Pickup', 'destination' => 'Tujuan', 'store' => 'Toko'])->native(false),
                    Forms\Components\Select::make('branch_id')->options(fn (): array => self::branchOptions())->searchable()->preload()->native(false),
                    Forms\Components\Select::make('area_id')->options(fn (): array => self::areaOptions())->searchable()->preload()->native(false),
                    Forms\Components\TextInput::make('latitude')->numeric()->rules(['nullable', 'between:-90,90']),
                    Forms\Components\TextInput::make('longitude')->numeric()->rules(['nullable', 'between:-180,180']),
                    Forms\Components\TagsInput::make('aliases')->columnSpanFull(),
                    Forms\Components\Select::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'])->native(false),
                    Forms\Components\TextInput::make('confidence')->numeric()->minValue(1)->maxValue(100),
                    Forms\Components\Textarea::make('raw_text')->rows(6)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'area', 'locationPoi'])->latest('updated_at'))
            ->headerActions([
                Tables\Actions\Action::make('paste_whatsapp')
                    ->label('Paste WhatsApp Orders')
                    ->icon('heroicon-o-document-text')
                    ->color('warning')
                    ->form([
                        Forms\Components\Select::make('branch_id')
                            ->label('Cabang')
                            ->options(fn (): array => self::branchOptions())
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Forms\Components\Select::make('area_id')
                            ->label('Area')
                            ->options(fn (): array => self::areaOptions())
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Forms\Components\Textarea::make('raw_text')
                            ->label('Paste chat/order WhatsApp mentah')
                            ->rows(12)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $result = app(AiLocationLearningService::class)->learnFromWhatsappText(
                            (string) $data['raw_text'],
                            $data['branch_id'] ? (int) $data['branch_id'] : null,
                            $data['area_id'] ? (int) $data['area_id'] : null,
                        );

                        Notification::make()
                            ->title('Location learning selesai')
                            ->body("Created: {$result['created']}, updated: {$result['updated']}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('location_text')->label('Lokasi')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('aliases')->formatStateUsing(fn (mixed $state): string => collect($state ?? [])->take(4)->implode(', '))->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('branch.display_name')->label('Cabang')->searchable(),
                Tables\Columns\TextColumn::make('role')->badge(),
                Tables\Columns\TextColumn::make('occurrence_count')->label('Muncul')->sortable(),
                Tables\Columns\TextColumn::make('latitude')
                    ->label('Lat')
                    ->state(fn (AiLocationSuggestion $record): ?string => self::coordinateState($record->latitude ?? $record->locationPoi?->latitude))
                    ->placeholder('-')
                    ->copyable(),
                Tables\Columns\TextColumn::make('longitude')
                    ->label('Lng')
                    ->state(fn (AiLocationSuggestion $record): ?string => self::coordinateState($record->longitude ?? $record->locationPoi?->longitude))
                    ->placeholder('-')
                    ->copyable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                }),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                Tables\Filters\SelectFilter::make('branch_id')->label('Cabang')->options(fn (): array => self::branchOptions()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve_to_poi')
                    ->label('Approve POI')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AiLocationSuggestion $record): bool => $record->status !== 'approved')
                    ->form([
                        Forms\Components\TextInput::make('name')->label('Nama POI')->required(),
                        Forms\Components\TagsInput::make('aliases')->label('Alias'),
                        Forms\Components\TextInput::make('latitude')->numeric()->rules(['nullable', 'between:-90,90']),
                        Forms\Components\TextInput::make('longitude')->numeric()->rules(['nullable', 'between:-180,180']),
                        Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                    ])
                    ->fillForm(fn (AiLocationSuggestion $record): array => [
                        'name' => $record->location_text,
                        'aliases' => $record->aliases ?? [],
                        'latitude' => $record->latitude,
                        'longitude' => $record->longitude,
                        'is_active' => filled($record->latitude) && filled($record->longitude),
                    ])
                    ->action(function (AiLocationSuggestion $record, array $data): void {
                        app(AiLocationLearningService::class)->approve($record, $data);
                        Notification::make()->title('Suggestion masuk Master POI')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve_selected_to_poi')
                        ->label('Approve POI terpilih')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Approve masal AI Location Suggestions?')
                        ->modalDescription('Suggestion terpilih akan dibuat/diupdate ke Master Location POI. Data yang sudah approved akan dilewati.')
                        ->action(function (Collection $records): void {
                            $service = app(AiLocationLearningService::class);
                            $approved = 0;
                            $skipped = 0;

                            $records->each(function (AiLocationSuggestion $record) use ($service, &$approved, &$skipped): void {
                                if ($record->status === 'approved') {
                                    $skipped++;

                                    return;
                                }

                                $service->approve($record);
                                $approved++;
                            });

                            Notification::make()
                                ->title('Approve masal selesai')
                                ->body("Approved: {$approved}, dilewati: {$skipped}.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiLocationSuggestions::route('/'),
            'create' => Pages\CreateAiLocationSuggestion::route('/create'),
            'edit' => Pages\EditAiLocationSuggestion::route('/{record}/edit'),
        ];
    }

    private static function branchOptions(): array
    {
        return Branch::query()->operationalAreas()->orderBy('branch_code')->get()->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])->all();
    }

    private static function areaOptions(): array
    {
        return Area::query()->with('branch')->orderBy('name')->get()->mapWithKeys(fn (Area $area): array => [$area->id => trim(($area->branch?->display_name ? $area->branch->display_name.' - ' : '').$area->name)])->all();
    }

    private static function coordinateState(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 7, '.', '') : null;
    }
}
