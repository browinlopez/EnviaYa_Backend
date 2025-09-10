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
        Schema::create('user', function (Blueprint $table) {
            $table->bigIncrements('user_id');
            $table->string('name', 255);
            $table->string('email', 255)->unique();
            $table->string('password', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 225)->nullable();
            $table->integer('rol');
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->tinyInteger('state')->nullable();

            $table->foreign('rol')->references('rol_id')->on('rol')->onDelete('cascade');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user');
    }
};
