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
    Schema::create('business_user_favorites', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedInteger('busines_id');
        $table->timestamps();

        // Define explícitamente sin dejar que Laravel infiera nada
        $table->foreign('user_id')
            ->references('user_id')
            ->on('user')
            ->onDelete('cascade');
            
        $table->foreign('busines_id')
            ->references('busines_id')
            ->on('business')
            ->onDelete('cascade');
            
        $table->unique(['user_id', 'busines_id']);
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_user_favorites');
    }
};
