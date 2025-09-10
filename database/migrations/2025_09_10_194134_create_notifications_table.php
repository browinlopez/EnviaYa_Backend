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
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id'); // PK autoincremental
            $table->unsignedBigInteger('user_id');    // FK hacia user
            $table->text('message')->nullable();
            $table->tinyInteger('read')->nullable();
            $table->timestamp('date')->nullable();
            $table->tinyInteger('state')->nullable();

            // Índice
            $table->index('user_id', 'fk_notifications_user');

            // Foreign Key
            $table->foreign('user_id', 'fk_notifications_user')
                  ->references('user_id')->on('user')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
