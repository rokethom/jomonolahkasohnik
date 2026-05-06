<?php

namespace App\Filament\Resources\KeywordParserResource\Pages;

use App\Filament\Resources\KeywordParserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditKeywordParser extends EditRecord
{
    protected static string $resource = KeywordParserResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validateFormSchema($data);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function validateFormSchema(array $data): void
    {
        $fields = $data['form_schema']['fields'] ?? [];
        $names = collect($fields)->pluck('name')->filter()->map(fn ($name): string => (string) $name);

        if ($fields !== [] && $names->count() !== $names->unique()->count()) {
            throw ValidationException::withMessages([
                'form_schema' => 'Field name pada form schema harus unik.',
            ]);
        }
    }
}
