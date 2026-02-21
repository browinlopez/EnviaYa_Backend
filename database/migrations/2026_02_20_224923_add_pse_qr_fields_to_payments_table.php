<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('redirect_url')->nullable()->after('provider_snapshot');
            $table->text('qr_payload')->nullable()->after('redirect_url');
            $table->timestamp('qr_expires_at')->nullable()->after('qr_payload');
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['redirect_url', 'qr_payload', 'qr_expires_at']);
        });
    }
};