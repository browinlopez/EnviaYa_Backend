<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('domiciliary_reviews', function (Blueprint $table) {
            $table->increments('reviews_id'); // PK autoincremental
            $table->unsignedInteger('domiciliary_id'); // FK hacia domiciliary
            $table->unsignedInteger('buyer_id')->nullable();
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->text('comment')->nullable();
            $table->tinyInteger('state')->nullable();

            // Índices
            $table->index('domiciliary_id', 'fk_domiciliary_reviews_domiciliary');
            $table->index('buyer_id', 'fk_domiciliary_reviews_buyer');

            // Foreign Keys
            $table->foreign('domiciliary_id', 'fk_domiciliary_reviews_domiciliary')
                ->references('domiciliary_id')->on('domiciliary')
                ->onDelete('cascade');

            $table->foreign('buyer_id', 'fk_domiciliary_reviews_buyer')
                ->references('buyer_id')->on('buyer')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domiciliary_reviews');
    }
};
