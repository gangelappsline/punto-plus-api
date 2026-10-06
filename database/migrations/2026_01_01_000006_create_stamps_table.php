<?php

use App\Enums\StampSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_card_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('loyalty_card_id')->constrained()->cascadeOnDelete();

            // Usuario del negocio que registró el sello (null si se desactiva la cuenta).
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 20)->default(StampSource::Scan->value);
            $table->decimal('purchase_amount', 10, 2)->nullable();
            $table->string('notes', 255)->nullable();

            // Fecha efectiva del sello (permite registros retroactivos/importaciones).
            $table->timestamp('stamped_at');
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['customer_card_id', 'stamped_at']);
            $table->index(['business_id', 'stamped_at']);
            $table->index(['loyalty_card_id', 'stamped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamps');
    }
};
