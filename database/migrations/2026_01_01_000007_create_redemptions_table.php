<?php

use App\Enums\RedemptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_card_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reward_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Código que el negocio valida para entregar el premio.
            $table->string('code', 20)->unique();

            $table->string('status', 20)->default(RedemptionStatus::Pending->value);
            $table->unsignedSmallInteger('stamps_used')->default(0);
            $table->timestamp('redeemed_at')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};
