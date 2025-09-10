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
        Schema::create('business', function (Blueprint $table) {
            $table->increments('busines_id'); // AUTO_INCREMENT int PK
            $table->string('name'); // NOT NULL
            $table->string('phone', 20)->nullable();
            $table->string('address', 225)->nullable();
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->string('razonSocial_DCD')->nullable();
            $table->string('NIT')->nullable();
            $table->string('logo')->nullable();

            // FK hacia municipalities.id
           $table->unsignedBigInteger('municipality_id')->nullable();

            // otros campos
            $table->integer('type')->nullable();
            $table->tinyInteger('state')->nullable();

            // Clave foránea
            $table->foreign('municipality_id')
                ->references('id')
                ->on('municipalities')
                ->onDelete('set null'); // si se elimina municipio, queda null
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->dropForeign(['municipality_id']);
        });
        Schema::dropIfExists('business');
    }
};
