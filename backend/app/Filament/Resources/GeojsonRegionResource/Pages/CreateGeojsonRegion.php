<?php

namespace App\Filament\Resources\GeojsonRegionResource\Pages;

use App\Filament\Resources\GeojsonRegionResource;
use App\Models\GeojsonRegion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateGeojsonRegion extends CreateRecord
{
    protected static string $resource = GeojsonRegionResource::class;

    private int $createdRows = 0;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $records = collect(GeojsonRegionResource::normalizeGeojsonRows($data))
                ->map(fn (array $row): GeojsonRegion => GeojsonRegion::query()->create($row));
        } catch (RuntimeException $exception) {
            Notification::make()
                ->danger()
                ->title('GeoJSON gagal disimpan')
                ->body($exception->getMessage())
                ->send();

            throw ValidationException::withMessages([
                'geojson' => $exception->getMessage(),
            ]);
        }

        $this->createdRows = $records->count();

        return $records->firstOrFail();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('GeoJSON berhasil disimpan')
            ->body($this->createdRows > 1
                ? $this->createdRows.' data region berhasil dibuat dan ditampilkan di tabel.'
                : '1 data region berhasil dibuat dan ditampilkan di tabel.');
    }
}
