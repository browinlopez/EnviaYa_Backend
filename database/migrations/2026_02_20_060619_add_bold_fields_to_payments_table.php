<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider', 30)->nullable()->after('forms_id');
            $table->string('provider_payment_id')->nullable()->after('provider');
            $table->string('status', 30)->after('payment_status');
            $table->json('provider_snapshot')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'provider_payment_id',
                'status',
                'provider_snapshot'
            ]);
        });
    }
};