<?php

namespace App\Http\Requests\LoyaltyCard;

use App\Enums\CardPeriodType;
use App\Enums\LoyaltyCardType;
use App\Models\LoyaltyCard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Actualización parcial de una tarjeta de fidelidad.
 *
 * El tipo (`visits`/`spend`) sólo puede cambiarse mientras la tarjeta no tenga
 * clientes, porque el progreso acumulado depende de él.
 */
class UpdateLoyaltyCardRequest extends FormRequest
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
        $isSpend = $this->resolvedType() === LoyaltyCardType::Spend;
        $card = $this->card();

        $maxSpendAmount = config('punto_plus.cards.max_spend_amount_per_block');
        $minPoints = config('punto_plus.cards.min_points_per_block');
        $maxPoints = config('punto_plus.cards.max_points_per_block');

        // En una tarjeta por consumo estos dos campos no pueden quedar vacíos: si aún
        // no tienen valor se exigen, y si ya lo tienen basta con validarlos al enviarlos.
        $spendAmountRules = match (true) {
            ! $isSpend => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:'.$maxSpendAmount],
            $card?->spend_amount_per_block === null => ['required', 'numeric', 'min:1', 'max:'.$maxSpendAmount],
            default => ['sometimes', 'numeric', 'min:1', 'max:'.$maxSpendAmount],
        };

        $pointsPerBlockRules = match (true) {
            ! $isSpend => ['sometimes', 'nullable', 'integer', 'min:'.$minPoints, 'max:'.$maxPoints],
            $card?->points_per_block === null => ['required', 'integer', 'min:'.$minPoints, 'max:'.$maxPoints],
            default => ['sometimes', 'integer', 'min:'.$minPoints, 'max:'.$maxPoints],
        };

        $periodType = $this->input('period_type');
        $needsPeriodDays = $periodType === CardPeriodType::Days->value && $card?->period_days === null;

        $periodDaysRules = $needsPeriodDays
            ? ['required', 'integer', 'min:1', 'max:'.config('punto_plus.cards.max_period_days')]
            : ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.config('punto_plus.cards.max_period_days')];

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'type' => [
                'sometimes',
                Rule::enum(LoyaltyCardType::class),
                $this->typeChangeGuard(),
            ],

            // ---- Tarjetas por cantidad de visitas ----
            'required_stamps' => [
                'sometimes',
                'integer',
                'min:'.config('punto_plus.cards.min_required_stamps'),
                'max:'.config('punto_plus.cards.max_required_stamps'),
            ],
            'reward_description' => ['sometimes', 'nullable', 'string', 'max:180'],

            // ---- Tarjetas por consumo ----
            'spend_amount_per_block' => $spendAmountRules,
            'points_per_block' => $pointsPerBlockRules,
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],

            // ---- Vigencia del ciclo ----
            'period_type' => ['sometimes', 'nullable', Rule::enum(CardPeriodType::class)],
            'period_days' => $periodDaysRules,

            'terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.stamp_icon' => ['sometimes', 'nullable', 'string', 'in:star,heart,coffee,leaf,bolt,crown,circle'],
            'settings.card_shape' => ['sometimes', 'nullable', 'string', 'in:rounded,square,pill'],
            'settings.welcome_message' => ['sometimes', 'nullable', 'string', 'max:180'],
            'settings.stamps_lifetime_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.enum' => 'El tipo de tarjeta debe ser `visits` (visitas) o `spend` (consumo).',
            'spend_amount_per_block.required' => 'Indica cada cuánto consumo (monto) se otorgan puntos.',
            'points_per_block.required' => 'Indica cuántos puntos se otorgan por cada bloque de consumo.',
        ];
    }

    /**
     * Tipo final de la tarjeta tras aplicar el cambio solicitado.
     */
    private function resolvedType(): LoyaltyCardType
    {
        return LoyaltyCardType::tryFrom((string) $this->input('type'))
            ?? $this->card()?->type
            ?? LoyaltyCardType::Visits;
    }

    private function card(): ?LoyaltyCard
    {
        $card = $this->route('loyaltyCard');

        return $card instanceof LoyaltyCard ? $card : null;
    }

    private function typeChangeGuard(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $card = $this->card();

            if ($card === null || $value === $card->type->value) {
                return;
            }

            if ($card->customerCards()->exists()) {
                $fail('No puedes cambiar el tipo de una tarjeta que ya tiene clientes: el progreso acumulado depende de él. Crea una tarjeta nueva.');

                return;
            }

            if ($card->rewards()->exists()) {
                $fail('Antes de cambiar el tipo de tarjeta elimina sus recompensas: los umbrales se miden en visitas o en puntos según el tipo.');
            }
        };
    }
}
