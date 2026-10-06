<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Promotion>
 */
class PromotionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'loyalty_card_id' => null,
            'title' => '2x1 en '.fake()->word(),
            'description' => fake()->sentence(12),
            'discount_type' => DiscountType::Percentage,
            'discount_value' => fake()->randomElement([10, 15, 20, 25]),
            'code' => strtoupper(fake()->lexify('PROMO???')),
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(10),
            'is_active' => true,
            'max_redemptions' => fake()->numberBetween(20, 200),
            'redemptions_count' => 0,
        ];
    }

    public function forBusiness(Business $business): static
    {
        return $this->state(fn (): array => ['business_id' => $business->getKey()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDay(),
        ]);
    }
}
