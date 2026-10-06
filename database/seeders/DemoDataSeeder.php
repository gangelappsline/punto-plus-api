<?php

namespace Database\Seeders;

use App\Enums\RewardType;
use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\Promotion;
use App\Models\Reward;
use App\Models\User;
use App\Services\CustomerCardService;
use App\Services\RedemptionService;
use App\Services\StampService;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración: un negocio con tarjetas, recompensas, promociones y
 * clientes con distinto progreso (uno de ellos con un canje completado).
 *
 * Los sellos y canjes se crean a través de los servicios de dominio para que el
 * dataset respete exactamente las mismas reglas que la API.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin Punto Plus',
            'email' => 'admin@puntoplus.test',
        ]);

        $owner = User::factory()->negocio()->create([
            'name' => 'Lucía Gómez',
            'email' => 'negocio@puntoplus.test',
        ]);

        $business = Business::factory()->ownedBy($owner)->create([
            'name' => 'Café Aurora',
            'slug' => 'cafe-aurora',
            'description' => 'Cafetería de especialidad en el centro.',
            'category' => 'cafetería',
            'city' => 'Valencia',
            'card_settings' => [
                'primary_color' => '#F59E0B',
                'secondary_color' => '#78350F',
                'text_color' => '#FFFFFF',
                'stamp_icon' => 'coffee',
                'card_shape' => 'rounded',
                'welcome_message' => '¡Bienvenido al club del café de Aurora!',
                'required_stamps' => 6,
            ],
        ]);

        $card = LoyaltyCard::factory()->forBusiness($business)->create([
            'name' => 'Club del café',
            'slug' => 'club-del-cafe',
            'description' => '6 sellos = 1 café de especialidad gratis.',
            'required_stamps' => 6,
            'reward_description' => 'Un café de especialidad gratis',
            'join_code' => 'CAFE2026',
            'primary_color' => '#F59E0B',
            'secondary_color' => '#78350F',
            'settings' => ['stamp_icon' => 'coffee', 'card_shape' => 'rounded'],
        ]);

        $reward = Reward::factory()->forCard($card)->create([
            'name' => 'Café de especialidad gratis',
            'description' => 'Válido para cualquier café de la carta de filtrados.',
            'reward_type' => RewardType::FreeProduct,
            'stock' => 100,
        ]);

        Reward::factory()->forCard($card, 3)->create([
            'name' => 'Croissant de mantequilla',
            'description' => 'Recompensa intermedia a los 3 sellos.',
            'reward_type' => RewardType::Gift,
            'stock' => 50,
        ]);

        Promotion::factory()->forBusiness($business)->create([
            'title' => '2x1 en cafés de especialidad los martes',
            'description' => 'Presenta tu tarjeta del club del café y llévate dos cafés por el precio de uno.',
            'loyalty_card_id' => $card->getKey(),
            'code' => 'MARTES2X1',
        ]);

        $stampService = app(StampService::class);
        $customerCardService = app(CustomerCardService::class);
        $redemptionService = app(RedemptionService::class);

        $progress = [2, 4, 6];

        foreach ($progress as $index => $stamps) {
            $customer = User::factory()->cliente()->create([
                'name' => ['Marta Ruiz', 'Diego Sanz', 'Ana Torres'][$index],
                'email' => ['marta@puntoplus.test', 'diego@puntoplus.test', 'ana@puntoplus.test'][$index],
            ]);

            $customerCard = $customerCardService->join($customer, $card->join_code);

            foreach (range(1, $stamps) as $n) {
                $stampService->register($business, $customerCard, $owner, [
                    'purchase_amount' => 3 + $n,
                    'stamped_at' => now()->subDays(30 - $n * 2),
                    'notes' => 'Café '.$n,
                ]);
            }

            $customerCard->refresh();

            if ($customerCard->isCompleted() && $index === count($progress) - 1) {
                $redemption = $redemptionService->request($customerCard, $reward, $customer);
                $redemptionService->approve($redemption, $owner);
            }
        }

        $this->command?->info('Demo: negocio '.$business->name.' (código de alta '.$card->join_code
            ."), clientes: negocio@puntoplus.test / marta@ / diego@ / ana@ (password: 'password'), admin: {$admin->email}");
    }
}
