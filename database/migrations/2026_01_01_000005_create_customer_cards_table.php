<?php

use App\Enums\CardStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('loyalty_card_id')->constrained()->cascadeOnDelete();

            // Desnormalizado para listados por negocio sin join adicional.
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();

            // Identificador que el negocio escanea desde el QR del cliente.
            $table->string('code', 16)->unique();

            $table->unsignedSmallInteger('stamps_count')->default(0);
            $table->unsignedInteger('total_stamps_earned')->default(0);
            $table->unsignedInteger('rewards_earned')->default(0);
            $table->unsignedInteger('rewards_redeemed')->default(0);
            $table->string('status', 20)->default(CardStatus::Active->value);

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_stamp_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Un cliente sólo puede tener una tarjeta por programa.
            $table->unique(['user_id', 'loyalty_card_id']);
            $table->index(['business_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_cards');
    }
};
