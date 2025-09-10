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
        Schema::create('buyer_complex', function (Blueprint $table) {
            $table->increments('id'); // PK autoincrement

            // foreign keys
            $table->unsignedInteger('buyer_id');
            $table->unsignedBigInteger('complex_id');

            // timestamps
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrent()->nullable();

            // índices y claves foráneas
            $table->foreign('buyer_id', 'buyer_complex_ibfk_1')
                ->references('buyer_id')
                ->on('buyer')
                ->onDelete('cascade');

            $table->foreign('complex_id', 'buyer_complex_ibfk_2')
                ->references('complex_id')
                ->on('residential_complexes')
                ->onDelete('cascade');

            // índices individuales opcionales
            $table->index('buyer_id');
            $table->index('complex_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buyer_complex', function (Blueprint $table) {
            $table->dropForeign('buyer_complex_ibfk_1');
            $table->dropForeign('buyer_complex_ibfk_2');
        });

        Schema::dropIfExists('buyer_complex');
    }
};
