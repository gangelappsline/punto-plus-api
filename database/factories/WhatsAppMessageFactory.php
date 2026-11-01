<?php

namespace Database\Factories;

use App\Enums\WhatsAppDirection;
use App\Enums\WhatsAppStatus;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction' => WhatsAppDirection::Outgoing,
            'status' => WhatsAppStatus::Queued,
            'wa_message_id' => 'wamid.'.fake()->unique()->bothify('********************'),
            'business_id' => Business::factory(),
            'from_phone' => '+5215512345678',
            'to_phone' => '+5215598765432',
            'type' => 'text',
            'body' => fake()->sentence(8),
            'sent_at' => now(),
        ];
    }

    public function incoming(): static
    {
        return $this->state(fn (): array => [
            'direction' => WhatsAppDirection::Incoming,
            'status' => WhatsAppStatus::Received,
            'from_phone' => '+5215598765432',
            'to_phone' => '+5215512345678',
        ]);
    }

    public function template(string $name = 'promo_sellos'): static
    {
        return $this->state(fn (): array => [
            'type' => 'template',
            'template_name' => $name,
            'template_language' => 'es_MX',
            'body' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => WhatsAppStatus::Failed,
            'error' => 'Recipient phone number not in allowed list',
            'error_code' => '131030',
        ]);
    }
}
