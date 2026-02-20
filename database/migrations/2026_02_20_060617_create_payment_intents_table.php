<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('orderSales_id');
            $table->string('provider', 30)->default('bold');
            $table->string('bold_reference_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('COP');
            $table->string('status', 30);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->foreign('orderSales_id')
                  ->references('orderSales_id')->on('orderssales')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};