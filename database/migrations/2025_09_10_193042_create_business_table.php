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
        Schema::create('business', function (Blueprint $table) {
            $table->id(); // PK id
            $table->string('name', 255);
            $table->string('phone', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('description')->nullable();
            $table->double('latitude');
            $table->double('longitude');
            $table->decimal('qualification', 3, 2)->default(0.00);
            $table->string('legal_name', 255)->nullable();
            $table->foreignId('type_organization_id')->nullable()->constrained('type_organizations')->onDelete('set null');
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('identification_number', 20);
            $table->integer('verification_digit')->nullable();
            $table->string('logo', 255)->nullable();
            $table->foreignId('category_business_id')->constrained('categories_business')->onDelete('cascade');
            $table->tinyInteger('state')->nullable();

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
        Schema::dropIfExists('business');
    }
};
