<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_type', 50);
            $table->string('reference_id');
            $table->json('payload');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->index('reference_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
