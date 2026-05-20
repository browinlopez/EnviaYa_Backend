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
        Schema::create('buyers', function (Blueprint $table) {
            $table->id(); // PK id
            $table->unsignedBigInteger('user_id')->nullable(); // FK opcional a user
            $table->foreignId('type_document_identification_id')->nullable()->constrained('type_document_identifications');
            $table->unsignedBigInteger('type_organization_id')->nullable();
            $table->string('identification_number', 50)->nullable();
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->tinyInteger('belongs_to_complex')->default(0);
            $table->integer('verification_digit')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->tinyInteger('state')->nullable();

            $table->foreign('type_organization_id')
                ->references('id')
                ->on('type_organizations')
                ->restrictOnDelete();

            // Si quieres FK explícita a la tabla user:
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('municipality_id')
                ->references('id')
                ->on('municipalities')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buyers');
    }
};
