<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->boolean('has_review')->default(false)->after('state')->comment('Indica si la orden ya tiene review');
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn('has_review');
        });
    }
};