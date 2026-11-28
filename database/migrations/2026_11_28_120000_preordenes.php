<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// La preorden con su fila propia: la tarjeta del chat pasa a ser solo su copia para pintar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('chat_id');
            $table->unsignedBigInteger('message_id')->nullable()->unique();
            $table->unsignedBigInteger('busines_id')->nullable();
            $table->unsignedBigInteger('buyer_user_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('de', 10);
            $table->string('status', 12)->default('pending');
            $table->json('items');
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('ajustada')->default(false);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['busines_id', 'status'], 'preorders_negocio_estado_idx');
            $table->index(['buyer_user_id', 'status'], 'preorders_comprador_estado_idx');
            $table->index('chat_id');
        });

        Schema::create('preorder_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preorder_id')->constrained('preorders')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('accion', 20);
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['preorder_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preorder_events');
        Schema::dropIfExists('preorders');
    }
};
