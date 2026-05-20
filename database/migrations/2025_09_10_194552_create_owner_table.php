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
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('type_document_identification_id')->nullable()->constrained('type_document_identifications');
            $table->string('document_number', 50)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('contact_secondary', 45)->nullable();
            $table->string('notes', 45)->nullable();
            $table->integer('verification_digit')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->tinyInteger('state')->nullable();

            $table->string('profile_photo', 255)->nullable();

            $table->index('user_id', 'fk_owner_user');
            $table->foreign('user_id', 'fk_owner_user')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('municipality_id')
                ->references('id')->on('municipalities')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owner');
    }
};
