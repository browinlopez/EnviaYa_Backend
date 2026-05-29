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
        Schema::create('domiciliaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->tinyInteger('available')->nullable();
            $table->string('document', 225)->nullable();
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->geography('last_location', subtype: 'point', srid: 4326)->nullable();
            $table->tinyInteger('state')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('municipality_id')->references('id')->on('municipalities')->onDelete('set null');
            
            $table->spatialIndex('last_location');
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
