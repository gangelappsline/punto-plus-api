<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Business>
 */
class BusinessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Café '.fake()->unique()->city();

        return [
            'user_id' => User::factory()->negocio(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(12),
            'category' => fake()->randomElement(['cafetería', 'panadería', 'peluquería', 'gimnasio', 'restaurante']),
            'phone' => fake()->numerify('+34#########'),
            'email' => fake()->companyEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => 'ES',
            'latitude' => fake()->latitude(36, 43),
            'longitude' => fake()->longitude(-9, 3),
            'card_settings' => [
                'primary_color' => '#F59E0B',
                'stamp_icon' => 'coffee',
            ],
            'is_active' => true,
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (): array => ['user_id' => $owner->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
