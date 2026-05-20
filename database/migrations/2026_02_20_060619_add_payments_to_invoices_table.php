<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('payments_id')->nullable()->after('order_sale_id');
            $table->string('payment_provider')->nullable()->after('payments_id');
            $table->string('payment_reference')->nullable()->after('payment_provider');

            $table->foreign('payments_id')
                  ->references('id')->on('payments')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['payments_id']);
            $table->dropColumn([
                'payments_id',
                'payment_provider',
                'payment_reference'
            ]);
        });
    }
};
