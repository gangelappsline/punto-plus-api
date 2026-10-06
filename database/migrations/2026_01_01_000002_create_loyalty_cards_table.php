<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('required_stamps')->default(10);
            $table->string('reward_description')->nullable();
            $table->text('terms')->nullable();

            // Personalización de la tarjeta digital
            $table->string('logo_path')->nullable();
            $table->string('background_path')->nullable();
            $table->string('stamp_icon_path')->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();
            $table->string('text_color', 9)->nullable();

            // Código que viaja en el QR de alta del programa
            $table->string('join_code', 16)->unique();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // El índice compuesto cubre las consultas por business_id (prefijo).
            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'is_active']);
            $table->index(['is_active', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
    }
};
