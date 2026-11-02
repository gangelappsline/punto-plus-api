<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            // Umbral de puntos para las tarjetas por consumo (varios niveles por tarjeta).
            $table->unsignedInteger('points_required')->nullable()->after('required_stamps');

            // Contador de canjes solicitados (lo usan el panel y las estadísticas).
            $table->unsignedInteger('redemptions_count')->default(0)->after('stock');
        });

        // En las tarjetas por consumo el umbral es `points_required`, no sellos.
        Schema::table('rewards', function (Blueprint $table) {
            $table->unsignedSmallInteger('required_stamps')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->dropColumn(['points_required', 'redemptions_count']);
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->unsignedSmallInteger('required_stamps')->nullable(false)->change();
        });
    }
};
