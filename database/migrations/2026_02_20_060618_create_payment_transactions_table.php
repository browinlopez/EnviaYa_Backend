<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_intent_id')
                  ->constrained('payment_intents')
                  ->cascadeOnDelete();
            $table->string('provider_transaction_id')->nullable();
            $table->string('method', 50);
            $table->string('status', 30);
            $table->decimal('amount', 10, 2);
            $table->json('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
