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
        Schema::create('messages', function (Blueprint $table) {
            $table->increments('message_id'); // PK autoincremental
            $table->unsignedInteger('chat_id'); // FK hacia chats
            $table->unsignedBigInteger('user_id'); // FK hacia user
            $table->integer('role_id'); // FK hacia rol
            $table->text('content'); // NOT NULL
            $table->timestamp('created_at')->nullable()->useCurrent();

            // Índices
            $table->index('chat_id', 'fk_message_chat');
            $table->index('user_id', 'fk_message_user');
            $table->index('role_id', 'fk_message_role');

            // Foreign Keys
            $table->foreign('chat_id', 'fk_message_chat')
                  ->references('chat_id')->on('chats')
                  ->onDelete('cascade');

            $table->foreign('user_id', 'fk_message_user')
                  ->references('user_id')->on('user')
                  ->onDelete('cascade');

            $table->foreign('role_id', 'fk_message_role')
                  ->references('rol_id')->on('rol')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
