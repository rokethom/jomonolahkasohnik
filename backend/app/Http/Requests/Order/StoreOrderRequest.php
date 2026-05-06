<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'service_type' => ['required', 'string', 'max:50'],
            'pickup_address' => ['required', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'destination_address' => ['required', 'string', 'max:255'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'stops' => ['sometimes', 'integer', 'min:1'],
            'stop_count' => ['sometimes', 'integer', 'min:1'],
            'minimum_price' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', 'in:cash,transfer,qris'],
            'destination_text' => ['nullable', 'string', 'max:255'],
            'service_payload' => ['sometimes', 'array'],
            'items' => ['sometimes', 'array'],
            'items.*.name' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
            'items.*.price' => ['sometimes', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
            'points' => ['sometimes', 'array', 'max:5'],
            'points.*.label' => ['nullable', 'string', 'max:80'],
            'points.*.address' => ['required_with:points', 'string', 'max:255'],
            'device_location' => ['sometimes', 'array'],
            'device_location.lat' => ['required_with:device_location', 'numeric', 'between:-90,90'],
            'device_location.lng' => ['required_with:device_location', 'numeric', 'between:-180,180'],
            'device_location.accuracy' => ['nullable', 'numeric'],
            'device_location.speed' => ['nullable', 'numeric'],
            'device_location.timestamp' => ['nullable', 'date'],
            'device_location.is_mock_location' => ['sometimes', 'boolean'],
        ];
    }
}
