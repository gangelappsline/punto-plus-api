<?php

namespace Database\Factories;

use App\Enums\CardPeriodType;
use App\Enums\LoyaltyCardType;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LoyaltyCard>
 */
class LoyaltyCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Tarjeta de sellos', 'Club del café', 'Puntos fidelidad', 'Tarjeta VIP'])
            .' '.fake()->unique()->numberBetween(1, 99999);

        return [
            'business_id' => Business::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(10),
            'type' => LoyaltyCardType::Visits,
            'required_stamps' => fake()->numberBetween(5, 12),
            'period_type' => CardPeriodType::Unlimited,
            'period_days' => null,
            'reward_description' => 'Un producto gratis a elegir',
            'primary_color' => '#F59E0B',
            'secondary_color' => '#111827',
            'text_color' => '#FFFFFF',
            'join_code' => strtoupper(Str::random(8)),
            'is_active' => true,
            'is_public' => true,
            'settings' => ['stamp_icon' => 'star', 'card_shape' => 'rounded'],
        ];
    }

    public function forBusiness(Business $business): static
    {
        return $this->state(fn (): array => ['business_id' => $business->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Tarjeta por consumo: cada `$spendAmount` de compra suma `$points` puntos.
     */
    public function spend(float $spendAmount = 100, int $points = 10): static
    {
        return $this->state(fn (): array => [
            'type' => LoyaltyCardType::Spend,
            'required_stamps' => null,
            'currency' => 'MXN',
            'spend_amount_per_block' => $spendAmount,
            'points_per_block' => $points,
        ]);
    }

    /**
     * Tarjeta cuyo ciclo dura `$days` días.
     */
    public function withPeriod(int $days = 30): static
    {
        return $this->state(fn (): array => [
            'period_type' => CardPeriodType::Days,
            'period_days' => $days,
        ]);
    }
}
