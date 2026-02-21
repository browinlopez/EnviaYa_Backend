<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {

            if (!Schema::hasColumn('payments', 'redirect_url')) {
                $table->string('redirect_url')
                    ->nullable()
                    ->before('state');
            }

            if (!Schema::hasColumn('payments', 'qr_payload')) {
                $table->text('qr_payload')
                    ->nullable()
                    ->after('redirect_url');
            }

            if (!Schema::hasColumn('payments', 'qr_expires_at')) {
                $table->timestamp('qr_expires_at')
                    ->nullable()
                    ->after('qr_payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'redirect_url',
                'qr_payload',
                'qr_expires_at',
            ]);
        });
    }
};