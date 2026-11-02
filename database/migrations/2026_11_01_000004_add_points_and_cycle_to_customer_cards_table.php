<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_cards', function (Blueprint $table) {
            // Puntos acumulados en las tarjetas por consumo (y su histórico).
            $table->unsignedInteger('points_count')->default(0)->after('total_stamps_earned');
            $table->unsignedInteger('total_points_earned')->default(0)->after('points_count');

            // Vigencia del ciclo cuando la tarjeta define un lapso de días.
            $table->timestamp('cycle_started_at')->nullable()->after('last_stamp_at');
            $table->timestamp('cycle_expires_at')->nullable()->after('cycle_started_at');

            $table->index(['loyalty_card_id', 'cycle_expires_at']);
        });

        Schema::table('redemptions', function (Blueprint $table) {
            // Puntos consumidos por el canje en las tarjetas por consumo.
            $table->unsignedInteger('points_used')->default(0)->after('stamps_used');
        });
    }

    public function down(): void
    {
        Schema::table('redemptions', function (Blueprint $table) {
            $table->dropColumn('points_used');
        });

        Schema::table('customer_cards', function (Blueprint $table) {
            $table->dropIndex(['loyalty_card_id', 'cycle_expires_at']);
            $table->dropColumn(['points_count', 'total_points_earned', 'cycle_started_at', 'cycle_expires_at']);
        });
    }
};
