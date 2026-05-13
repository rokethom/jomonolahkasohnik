<?php

namespace App\Filament\Resources\RingPricingRuleResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\RingPricingRuleResource;
use App\Models\Branch;
use App\Models\Service;
use App\Services\RingPricingGeojsonImportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ListRingPricingRules extends ListRecords
{
    protected static string $resource = RingPricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import_geojson')
                ->label('Upload GeoJSON')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->modalHeading('Import GeoJSON ke Master Ring')
                ->modalDescription('Upload file dari geojson.io. Setiap Feature polygon akan dibuat menjadi data Master Ring dan tetap bisa diedit dari CMS.')
                ->form([
                    Forms\Components\FileUpload::make('geojson_file')
                        ->label('File GeoJSON')
                        ->disk('local')
                        ->directory('imports/ring-geojson')
                        ->acceptedFileTypes([
                            'application/json',
                            'application/geo+json',
                            'application/octet-stream',
                            'text/plain',
                            '.json',
                            '.geojson',
                        ])
                        ->helperText('FeatureCollection harus punya properties ring: 1, 2, 3 atau ring_1, ring_2, ring_3.')
                        ->required(),
                    Forms\Components\Select::make('branch_id')
                        ->label('Cabang')
                        ->options(fn (): array => static::branchOptions())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->default(fn (): ?int => static::canManageGlobalPricing() ? null : Auth::user()?->branch_id)
                        ->disabled(fn (): bool => ! static::canManageGlobalPricing())
                        ->dehydrated()
                        ->helperText('Kosongkan untuk auto cabang terdekat. Role cabang otomatis memakai cabangnya sendiri.'),
                    Forms\Components\Select::make('service_type')
                        ->label('Layanan')
                        ->options(fn (): array => static::serviceOptions())
                        ->searchable()
                        ->native(false)
                        ->helperText('Kosongkan jika berlaku untuk semua layanan.'),
                    Forms\Components\Select::make('polygon_match_point')
                        ->label('Titik pengecekan polygon')
                        ->default('destination_then_pickup')
                        ->native(false)
                        ->options([
                            'destination_then_pickup' => 'Tujuan, fallback pickup',
                            'destination' => 'Tujuan saja',
                            'pickup' => 'Pickup saja',
                            'either' => 'Pickup atau tujuan',
                            'both' => 'Pickup dan tujuan',
                        ]),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif setelah import')
                        ->default(true),
                    Forms\Components\Toggle::make('replace_existing')
                        ->label('Replace import GeoJSON lama untuk cabang ini')
                        ->helperText('Hanya menghapus data source geojson pada cabang yang dipilih. Jika cabang dikosongkan, data lama tidak dihapus.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $storedPath = is_array($data['geojson_file'] ?? null)
                        ? (string) reset($data['geojson_file'])
                        : (string) ($data['geojson_file'] ?? '');

                    if ($storedPath === '') {
                        Notification::make()
                            ->title('Import gagal')
                            ->body('File GeoJSON belum dipilih.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $result = app(RingPricingGeojsonImportService::class)->import(
                        Storage::disk('local')->path($storedPath),
                        Auth::user(),
                        [
                            'file_name' => basename($storedPath),
                            'branch_id' => $data['branch_id'] ?? null,
                            'service_type' => $data['service_type'] ?? null,
                            'polygon_match_point' => $data['polygon_match_point'] ?? 'destination_then_pickup',
                            'is_active' => (bool) ($data['is_active'] ?? true),
                            'replace_existing' => (bool) ($data['replace_existing'] ?? false),
                        ],
                    );

                    $body = 'Created: '.$result['created'].' | Updated: '.$result['updated'].' | Skipped: '.$result['skipped'];
                    if (($result['errors'] ?? []) !== []) {
                        $body .= "\n\n".implode("\n", array_slice($result['errors'], 0, 12));
                    }

                    $notification = Notification::make()
                        ->title($result['skipped'] > 0 ? 'Import selesai dengan catatan' : 'Import GeoJSON selesai')
                        ->body($body);

                    $result['skipped'] > 0
                        ? $notification->warning()
                        : $notification->success();

                    $notification->send();
                }),
            Actions\CreateAction::make(),
        ];
    }

    private static function canManageGlobalPricing(): bool
    {
        return in_array(Auth::user()?->role, [UserRole::Admin, UserRole::GM, UserRole::HRD], true);
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->when(! static::canManageGlobalPricing(), fn (Builder $query) => $query->whereKey(Auth::user()?->branch_id ?? 0))
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }

    private static function serviceOptions(): array
    {
        return Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Service $service): array => [$service->code => $service->name])
            ->all();
    }
}
