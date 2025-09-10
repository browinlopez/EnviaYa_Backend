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
        Schema::create('owner_busines', function (Blueprint $table) {
            $table->unsignedInteger('owner_id');
            $table->unsignedInteger('busines_id');
            $table->tinyInteger('state')->default(1);

            $table->primary(['owner_id', 'busines_id']);

            $table->index('owner_id', 'fk_owner_busines_owner');
            $table->index('busines_id', 'fk_owner_busines_business');

            $table->foreign('owner_id', 'fk_owner_busines_owner')
                ->references('owner_id')->on('owner')
                ->onDelete('cascade');

            $table->foreign('busines_id', 'fk_owner_busines_business')
                ->references('busines_id')->on('business')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owner_busines');
    }
};
