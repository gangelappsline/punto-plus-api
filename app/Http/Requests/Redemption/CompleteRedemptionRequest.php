<?php

namespace App\Http\Requests\Redemption;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El negocio valida el código del canje (el que muestra la app del cliente).
 */
class CompleteRedemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
