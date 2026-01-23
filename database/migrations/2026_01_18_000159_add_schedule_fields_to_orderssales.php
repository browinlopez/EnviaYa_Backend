<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->boolean('is_scheduled')
                ->default(false)
                ->after('sale_date');

            $table->dateTime('delivery_date')
                ->nullable()
                ->after('is_scheduled');
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn(['is_scheduled', 'delivery_date']);
        });
    }
};
