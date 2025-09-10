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
        Schema::create('buyer', function (Blueprint $table) {
            $table->increments('buyer_id'); // PK auto_increment
            $table->unsignedBigInteger('user_id')->nullable(); // FK opcional a user
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->tinyInteger('belongs_to_complex')->default(0);
            $table->tinyInteger('state')->nullable();

            // Si quieres FK explícita a la tabla user:
            $table->foreign('user_id')
                ->references('user_id')
                ->on('user')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buyer', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::dropIfExists('buyer');
    }
};
