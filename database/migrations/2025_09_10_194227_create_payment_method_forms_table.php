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
        Schema::create('payment_method_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('payment_method_id');
            $table->unsignedInteger('payment_form_id');

            $table->foreign('payment_method_id')
                ->references('id')->on('payment_methods')
                ->onDelete('cascade');

            $table->foreign('payment_form_id')
                ->references('id')->on('payment_forms')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_method_forms');
    }
};
