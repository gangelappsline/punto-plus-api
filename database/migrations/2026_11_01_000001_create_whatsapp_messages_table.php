<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // outbound = enviado por la API; inbound = recibido del cliente.
            $table->string('direction', 10);

            // queued | sent | delivered | read | received | failed
            $table->string('status', 20)->default('queued');

            // Id del mensaje en la Cloud API (`wamid...`). Es la clave para
            // casar los acuses de entrega con el envío original.
            $table->string('wa_message_id', 191)->nullable()->unique();

            $table->foreignUuid('business_id')->nullable()->constrained()->nullOnDelete();

            $table->string('from_phone', 20)->nullable();
            $table->string('to_phone', 20)->nullable();

            $table->string('type', 30)->default('text');
            $table->string('template_name', 191)->nullable();
            $table->string('template_language', 15)->nullable();
            $table->text('body')->nullable();

            // Payload enviado/recibido y respuesta de Meta (para depurar).
            $table->json('payload')->nullable();

            $table->string('error_code', 30)->nullable();
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['direction', 'created_at']);
            $table->index(['to_phone', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
