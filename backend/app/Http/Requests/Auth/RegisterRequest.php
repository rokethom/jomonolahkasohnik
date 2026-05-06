<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'lat' => ['required_without:location_lat', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:location_lng', 'numeric', 'between:-180,180'],
            'location_lat' => ['required_without:lat', 'numeric', 'between:-90,90'],
            'location_lng' => ['required_without:lng', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0'],
            'gps_timestamp' => ['nullable', 'date'],
        ];
    }
}
