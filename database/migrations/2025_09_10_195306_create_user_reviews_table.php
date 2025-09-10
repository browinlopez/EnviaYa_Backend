<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('user_reviews', function (Blueprint $table) {
            $table->id('reviews_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('domiciliary_id')->nullable();
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->text('comment')->nullable();
            $table->tinyInteger('state')->nullable();

            $table->foreign('user_id')->references('user_id')->on('user')->onDelete('cascade');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_reviews');
    }
};
