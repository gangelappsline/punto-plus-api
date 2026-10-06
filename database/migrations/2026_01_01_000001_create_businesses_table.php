<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // El propietario del negocio. Si se borra el usuario se elimina el negocio
            // (los clientes conservan su historial vía los sellos/canjes borrados en cascada).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category', 80)->nullable();

            // Contacto y ubicación
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Identidad visual y configuración por defecto de las tarjetas
            $table->string('logo_path')->nullable();
            $table->string('background_path')->nullable();
            $table->string('stamp_icon_path')->nullable();
            $table->json('card_settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Listado público de negocios por ciudad/categoría
            $table->index(['is_active', 'city']);
            $table->index(['is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
