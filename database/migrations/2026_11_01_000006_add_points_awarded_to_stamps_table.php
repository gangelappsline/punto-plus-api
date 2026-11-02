<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stamps', function (Blueprint $table) {
            // Puntos otorgados por la compra (0 en las tarjetas por visitas).
            $table->unsignedInteger('points_awarded')->default(0)->after('purchase_amount');
        });
    }

    public function down(): void
    {
        Schema::table('stamps', function (Blueprint $table) {
            $table->dropColumn('points_awarded');
        });
    }
};
