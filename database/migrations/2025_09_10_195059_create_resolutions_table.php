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
        Schema::create('resolutions', function (Blueprint $table) {
            $table->id('resolutions_id');
            $table->unsignedBigInteger('busines_id')->nullable();
            $table->dateTime('date_from')->nullable();
            $table->dateTime('date_to')->nullable();
            $table->integer('resolution_number')->nullable();
            $table->bigInteger('to')->nullable();
            $table->bigInteger('from')->nullable();
            $table->integer('certicate_id')->nullable();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resolutions');
    }
};
