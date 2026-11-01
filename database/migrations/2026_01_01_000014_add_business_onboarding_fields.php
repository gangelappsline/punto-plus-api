<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('phone_numbers')->nullable();
            $table->json('menu_design')->nullable();
        });

        Schema::create('business_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'category_id']);
            $table->index(['business_id', 'sort_order']);
        });

        Schema::table('menu_categories', function (Blueprint $table) {
            $table->foreignId('source_category_id')->nullable()->after('business_id')
                ->constrained('categories')->nullOnDelete();
            $table->unique(['business_id', 'source_category_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('menu_category_id')->nullable()->after('category_id')
                ->constrained('menu_categories')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'source_category_id']);
            $table->dropForeign(['source_category_id']);
            $table->dropColumn('source_category_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['menu_category_id']);
            $table->dropColumn('menu_category_id');
        });

        Schema::dropIfExists('business_categories');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['phone_numbers', 'menu_design']);
        });
    }
};
