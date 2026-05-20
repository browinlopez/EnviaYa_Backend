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
            $table->id(); // PK id

            // foreign keys
            $table->unsignedBigInteger('busines_id'); // FK business
            $table->unsignedBigInteger('buyer_id')->nullable(); // FK buyer

            // demás columnas
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->text('comment')->nullable();
            $table->tinyInteger('state')->nullable();

             // timestamps
            $table->timestamps(); // <-- crea created_at y updated_at

            // índices y claves foráneas
            $table->foreign('busines_id', 'fk_business_reviews_business')
                ->references('id')
                ->on('business')
                ->onDelete('cascade');

            $table->foreign('buyer_id', 'fk_business_reviews_buyer')
                ->references('id')
                ->on('buyers')
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
