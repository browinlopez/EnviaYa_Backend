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
        Schema::create('domiciliary', function (Blueprint $table) {
            $table->increments('domiciliary_id'); // PK autoincremental
            $table->unsignedBigInteger('user_id')->nullable(); // FK hacia user
            $table->tinyInteger('available')->nullable();
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->tinyInteger('state')->nullable();

            // Índice para user_id
            $table->index('user_id', 'fk_domiciliary_user');

            // Foreign Key
            $table->foreign('user_id', 'fk_domiciliary_user')
                  ->references('user_id')->on('user')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domiciliary');
    }
};
