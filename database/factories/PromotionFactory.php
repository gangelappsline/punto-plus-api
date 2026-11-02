<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\PromotionScheduleType;
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
            'schedule_type' => PromotionScheduleType::DateRange,
            'days_of_week' => null,
            'start_time' => null,
            'end_time' => null,
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
            'schedule_type' => PromotionScheduleType::DateRange,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Promoción sin fecha de fin que se muestra ciertos días de la semana.
     *
     * @param  array<int, int>  $days  Días ISO-8601 (1 = lunes … 7 = domingo).
     */
    public function indefinite(array $days = [1, 2, 3, 4, 5]): static
    {
        return $this->state(fn (): array => [
            'schedule_type' => PromotionScheduleType::Indefinite,
            'days_of_week' => $days,
            'starts_at' => null,
            'ends_at' => null,
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    /**
     * Hora feliz: ciertos días dentro de un rango de hora.
     */
    public function happyHour(string $from = '18:00', string $to = '21:00', array $days = [5, 6]): static
    {
        return $this->state(fn (): array => [
            'schedule_type' => PromotionScheduleType::TimeRange,
            'days_of_week' => $days,
            'start_time' => $from,
            'end_time' => $to,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }
}
