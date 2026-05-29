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
        Schema::table('orders_sales', function (Blueprint $table) {
            $table->integer('delivery_distance_meters')->nullable()->after('delivery_date');
            $table->decimal('delivery_fee_applied', 10, 2)->nullable()->after('delivery_distance_meters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_sales', function (Blueprint $table) {
            $table->dropColumn(['delivery_distance_meters', 'delivery_fee_applied']);
        });
    }
};
