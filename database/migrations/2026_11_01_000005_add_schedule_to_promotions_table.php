<?php

use App\Enums\PromotionScheduleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // Tipo de programación: rango de fechas (por defecto), indefinida o por hora.
            $table->string('schedule_type', 20)
                ->default(PromotionScheduleType::DateRange->value)
                ->after('code');

            // Días de la semana (ISO-8601: 1 = lunes … 7 = domingo).
            $table->json('days_of_week')->nullable()->after('schedule_type');

            // Rango de hora para las promociones tipo `time_range` (puede cruzar medianoche).
            $table->time('start_time')->nullable()->after('days_of_week');
            $table->time('end_time')->nullable()->after('start_time');

            $table->index(['business_id', 'schedule_type']);
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'schedule_type']);
            $table->dropColumn(['schedule_type', 'days_of_week', 'start_time', 'end_time']);
        });
    }
};
