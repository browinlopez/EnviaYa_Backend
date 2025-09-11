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
        Schema::create('business_reviews', function (Blueprint $table) {
            $table->increments('reviews_id'); // PK auto_increment

            // foreign keys
            $table->unsignedInteger('busines_id'); // FK business
            $table->unsignedInteger('buyer_id')->nullable(); // FK buyer (INT para que coincida)
            // Elimina user_id si ya no se usa
            // $table->unsignedBigInteger('user_id')->nullable();

            // demás columnas
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->text('comment')->nullable();
            $table->tinyInteger('state')->nullable();

            // índices y claves foráneas
            $table->foreign('busines_id', 'fk_business_reviews_business')
                ->references('busines_id')
                ->on('business')
                ->onDelete('cascade');

            $table->foreign('buyer_id', 'fk_business_reviews_buyer')
                ->references('buyer_id')
                ->on('buyer')
                ->onDelete('set null');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_reviews', function (Blueprint $table) {
            $table->dropForeign('fk_business_reviews_business');
            $table->dropForeign('fk_business_reviews_buyer');
        });

        Schema::dropIfExists('business_reviews');
    }
};
