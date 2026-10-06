<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string'],
            'scope' => ['nullable', 'string', Rule::in(array_keys(config('punto_plus.scopes')))],
        ];
    }
}
