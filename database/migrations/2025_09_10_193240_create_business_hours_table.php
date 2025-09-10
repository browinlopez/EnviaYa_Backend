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
        Schema::create('business_hours', function (Blueprint $table) {
            $table->increments('hours_id'); // PK auto_increment
            $table->unsignedInteger('busines_id'); // FK hacia business

            // columnas de días
            $table->text('monday')->nullable();
            $table->text('tuesday')->nullable();
            $table->text('wednesday')->nullable();
            $table->text('thursday')->nullable();
            $table->text('friday')->nullable();
            $table->text('saturday')->nullable();
            $table->text('sunday')->nullable();

            // estado (activo/inactivo, etc.)
            $table->text('state')->nullable();

            // FK
            $table->foreign('busines_id')
                ->references('busines_id')
                ->on('business')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_hours', function (Blueprint $table) {
            $table->dropForeign(['busines_id']);
        });

        Schema::dropIfExists('business_hours');
    }
};
