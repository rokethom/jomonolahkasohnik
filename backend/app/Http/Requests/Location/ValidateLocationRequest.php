<?php

namespace App\Http\Requests\Location;

use Illuminate\Foundation\Http\FormRequest;

class ValidateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'altitude' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'gps_timestamp' => ['nullable', 'date'],
            'provider' => ['nullable', 'string', 'max:50'],
            'is_mock_location' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
