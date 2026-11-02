<?php

use App\Enums\CardPeriodType;
use App\Enums\LoyaltyCardType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            // Tipo de tarjeta: por visitas (por defecto) o por consumo (puntos).
            $table->string('type', 20)->default(LoyaltyCardType::Visits->value)->after('description');

            // Vigencia del ciclo (visitas y puntos).
            $table->string('period_type', 20)->default(CardPeriodType::Unlimited->value)->after('required_stamps');
            $table->unsignedSmallInteger('period_days')->nullable()->after('period_type');

            // Configuración de las tarjetas por consumo.
            $table->string('currency', 3)->default('MXN')->after('period_days');
            $table->decimal('spend_amount_per_block', 10, 2)->nullable()->after('currency');
            $table->unsignedInteger('points_per_block')->nullable()->after('spend_amount_per_block');

            $table->index(['business_id', 'type']);
        });

        // En las tarjetas por consumo no hay "visitas requeridas".
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->unsignedSmallInteger('required_stamps')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'type']);
            $table->dropColumn([
                'type', 'period_type', 'period_days', 'currency',
                'spend_amount_per_block', 'points_per_block',
            ]);
        });

        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->unsignedSmallInteger('required_stamps')->nullable(false)->default(10)->change();
        });
    }
};
