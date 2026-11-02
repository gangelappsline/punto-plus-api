<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('loyalty_card_id')->constrained()->cascadeOnDelete();

            // Usuario del negocio que generó el token (null si se desactiva la cuenta).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Código legible que se muestra como QR y que el cliente puede escribir.
            $table->string('code', 16)->unique();

            // Monto opcional de la venta: obligatorio para tarjetas por consumo.
            $table->decimal('purchase_amount', 10, 2)->nullable();
            $table->string('notes', 255)->nullable();

            // Los tokens duran pocos minutos (SALE_TOKEN_TTL_MINUTES, 5 por defecto).
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['business_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_tokens');
    }
};
