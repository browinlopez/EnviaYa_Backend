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
        Schema::table('messages', function (Blueprint $table) {
            $table->string('file_url', 255)->nullable()->after('content');
            $table->string('file_type', 50)->nullable()->after('file_url');
            $table->timestamp('read_at')->nullable()->after('file_type');
        });

        Schema::table('chat_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_read_message_id')->nullable()->after('joined_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['file_url', 'file_type', 'read_at']);
        });

        Schema::table('chat_participants', function (Blueprint $table) {
            $table->dropColumn('last_read_message_id');
        });
    }
};
