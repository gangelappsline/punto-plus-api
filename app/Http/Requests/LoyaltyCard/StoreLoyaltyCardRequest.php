<?php

namespace App\Http\Requests\LoyaltyCard;

use App\Enums\RewardType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoyaltyCardRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'required_stamps' => [
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'reward_description' => ['nullable', 'string', 'max:180'],
            'terms' => ['nullable', 'string', 'max:2000'],

            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],

            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'settings' => ['nullable', 'array'],
            'settings.stamp_icon' => ['nullable', 'string', 'in:star,heart,coffee,leaf,bolt,crown,circle'],
            'settings.card_shape' => ['nullable', 'string', 'in:rounded,square,pill'],
            'settings.welcome_message' => ['nullable', 'string', 'max:180'],
            'settings.stamps_lifetime_days' => ['nullable', 'integer', 'min:1', 'max:3650'],

            // Copia el logo/fondo/sello y los colores configurados en el negocio.
            'inherit_business_branding' => ['sometimes', 'boolean'],

            // Recompensas iniciales del programa (opcional).
            'rewards' => ['nullable', 'array', 'max:10'],
            'rewards.*.name' => ['required_with:rewards', 'string', 'max:120'],
            'rewards.*.description' => ['nullable', 'string', 'max:500'],
            'rewards.*.reward_type' => ['nullable', Rule::enum(RewardType::class)],
            'rewards.*.value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'rewards.*.required_stamps' => [
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'rewards.*.stock' => ['nullable', 'integer', 'min:0'],
            'rewards.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required_stamps.max' => 'El máximo de sellos es :max.',
            'expires_at.after' => 'La fecha de caducidad debe ser futura.',
        ];
    }
}
