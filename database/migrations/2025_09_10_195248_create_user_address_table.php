<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_address', function (Blueprint $table) {
            $table->id(); // PK id
            $table->unsignedBigInteger('user_id');
            $table->string('address', 225);
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->unsignedInteger('alias_id')->nullable();
            $table->string('complement', 50)->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->tinyInteger('state')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('municipality_id')->references('id')->on('municipalities')->onDelete('set null');

            $table->spatialIndex('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_address');
    }
};
