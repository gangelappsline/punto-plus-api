<?php

namespace App\Http\Requests\Redemption;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRedemptionRequest extends FormRequest
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
            'reward_id' => ['required', 'uuid', Rule::exists('rewards', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
