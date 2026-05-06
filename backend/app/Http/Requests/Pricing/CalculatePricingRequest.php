<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class CalculatePricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'service_type' => ['required', 'string', 'in:ojek,kurir,delivery,do,belanja,gift,gift_order,travel,joker_mobil'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'distance' => ['sometimes', 'numeric', 'min:0'],
            'distance_km' => ['sometimes', 'numeric', 'min:0'],
            'route' => ['nullable'],
            'travel_route' => ['nullable'],
            'service_payload' => ['nullable', 'array'],
            'service_payload.route' => ['nullable'],
            'pickup_address' => ['sometimes', 'string', 'max:255'],
            'pickup_lat' => ['required_without_all:distance,distance_km', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required_without_all:distance,distance_km', 'numeric', 'between:-180,180'],
            'destination_address' => ['sometimes', 'string', 'max:255'],
            'destination_text' => ['nullable', 'string', 'max:255'],
            'destination_lat' => ['required_without_all:distance,distance_km,destination_text', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required_without_all:distance,distance_km,destination_text', 'numeric', 'between:-180,180'],
            'dest_lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'dest_lon' => ['sometimes', 'numeric', 'between:-180,180'],
            'stops' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'destination_lat' => $this->input('destination_lat', $this->input('dest_lat')),
            'destination_lng' => $this->input('destination_lng', $this->input('dest_lon')),
            'destination_address' => $this->input('destination_address', $this->input('destination_text', 'Destination')),
            'pickup_address' => $this->input('pickup_address', 'Pickup'),
            'stops' => $this->input('stops', 1),
            'service_type' => $this->input('service_type', 'ojek'),
        ]);
    }
}
