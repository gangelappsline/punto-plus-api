<?php

namespace App\Http\Requests\LoyaltyCard;

use App\Enums\CardPeriodType;
use App\Enums\LoyaltyCardType;
use App\Enums\RewardType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una tarjeta de fidelidad.
 *
 * El campo `type` decide qué configuración es obligatoria:
 *  - `visits`: `required_stamps` (+ opcionalmente `period_type`/`period_days`).
 *  - `spend`: `spend_amount_per_block`, `points_per_block` y al menos una
 *    recompensa con `points_required`.
 */
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
        $type = $this->input('type') ?? LoyaltyCardType::Visits->value;
        $isSpend = $type === LoyaltyCardType::Spend->value;

        // Una tarjeta por consumo debe traer al menos una recompensa con puntos;
        // en las de visitas el arreglo es opcional.
        $rewardRules = ['array', 'max:'.config('punto_plus.cards.max_rewards')];

        if ($isSpend) {
            array_unshift($rewardRules, 'required');
            $rewardRules[] = 'min:1';
        } else {
            $rewardRules[] = 'nullable';
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],

            // Tipo de tarjeta: visitas (por defecto) o consumo por puntos.
            'type' => ['nullable', Rule::enum(LoyaltyCardType::class)],

            // ---- Tarjetas por cantidad de visitas ----
            'required_stamps' => [
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'reward_description' => ['nullable', 'string', 'max:180'],

            // ---- Tarjetas por consumo ----
            'spend_amount_per_block' => [
                'nullable',
                'numeric',
                'min:1',
                'max:'.config('punto_plus.cards.max_spend_amount_per_block'),
                Rule::requiredIf($isSpend),
            ],
            'points_per_block' => [
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_points_per_block'),
                'max:'.config('punto_plus.cards.max_points_per_block'),
                Rule::requiredIf($isSpend),
            ],
            'currency' => ['nullable', 'string', 'size:3'],

            // ---- Vigencia del ciclo (aplica a ambos tipos) ----
            'period_type' => ['nullable', Rule::enum(CardPeriodType::class)],
            'period_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.config('punto_plus.cards.max_period_days'),
                Rule::requiredIf($this->input('period_type') === CardPeriodType::Days->value),
            ],

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

            // ---- Recompensas iniciales del programa ----
            'rewards' => $rewardRules,
            'rewards.*.name' => ['required_with:rewards', 'string', 'max:120'],
            'rewards.*.description' => ['nullable', 'string', 'max:500'],
            'rewards.*.reward_type' => ['nullable', Rule::enum(RewardType::class)],
            'rewards.*.value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            // Visitas necesarias (tarjetas por visitas). Si se omite, se usa `required_stamps`.
            'rewards.*.required_stamps' => [
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],

            // Puntos necesarios (tarjetas por consumo).
            'rewards.*.points_required' => [
                'nullable',
                'integer',
                'min:'.config('punto_plus.cards.min_points_required'),
                'max:'.config('punto_plus.cards.max_points_required'),
                Rule::requiredIf($isSpend),
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
            'type.enum' => 'El tipo de tarjeta debe ser `visits` (visitas) o `spend` (consumo).',
            'required_stamps.max' => 'El máximo de sellos es :max.',
            'spend_amount_per_block.required' => 'Indica cada cuánto consumo (monto) se otorgan puntos.',
            'points_per_block.required' => 'Indica cuántos puntos se otorgan por cada bloque de consumo.',
            'period_days.required' => 'Indica en cuántos días debe completarse la tarjeta.',
            'rewards.required' => 'Una tarjeta por consumo necesita al menos una recompensa con sus puntos.',
            'rewards.*.points_required.required' => 'Cada recompensa de una tarjeta por consumo necesita sus puntos.',
            'expires_at.after' => 'La fecha de caducidad debe ser futura.',
        ];
    }
}
