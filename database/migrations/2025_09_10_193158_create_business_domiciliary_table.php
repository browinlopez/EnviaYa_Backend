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
        Schema::create('business_domiciliaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('busines_id');
            $table->unsignedBigInteger('domiciliary_id');
            $table->tinyInteger('state')->default(1);

            $table->foreign('busines_id')
                ->references('id')
                ->on('business')
                ->onDelete('cascade');

            $table->foreign('domiciliary_id')
                ->references('id')
                ->on('domiciliaries')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_domiciliaries');
    }
};
