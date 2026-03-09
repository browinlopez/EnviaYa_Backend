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
        Schema::create('owner', function (Blueprint $table) {
            $table->increments('owner_id');
            $table->unsignedBigInteger('user_id'); // FK a user
            $table->string('profile_photo', 255);
            $table->foreignId('document_type_id')->constrained('document_types');
            $table->string('document_number', 50);
            $table->date('birthdate')->nullable();
            $table->string('contact_secondary', 45)->nullable();
            $table->string('notes', 45)->nullable();
            $table->tinyInteger('state')->nullable();

           $table->string('profile_photo', 255)->nullable();    

            $table->index('user_id', 'fk_owner_user');
            $table->foreign('user_id', 'fk_owner_user')
                ->references('user_id')->on('user')
                ->onDelete('cascade');
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
