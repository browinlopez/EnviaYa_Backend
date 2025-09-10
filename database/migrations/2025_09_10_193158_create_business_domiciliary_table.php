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
        Schema::create('business_domiciliary', function (Blueprint $table) {
            $table->unsignedInteger('busines_id');
            $table->unsignedInteger('domiciliary_id');
            $table->tinyInteger('state')->default(1);

            // PK compuesta
            $table->primary(['busines_id', 'domiciliary_id']);

            // índices y claves foráneas
            $table->foreign('busines_id', 'fk_bd_business')
                ->references('busines_id')
                ->on('business')
                ->onDelete('cascade');

            $table->foreign('domiciliary_id', 'fk_bd_domiciliary')
                ->references('domiciliary_id')
                ->on('domiciliary')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_domiciliary', function (Blueprint $table) {
            $table->dropForeign('fk_bd_business');
            $table->dropForeign('fk_bd_domiciliary');
        });

        Schema::dropIfExists('business_domiciliary');
    }
};
