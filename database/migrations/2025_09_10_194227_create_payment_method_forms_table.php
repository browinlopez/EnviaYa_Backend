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
            $table->increments('id');
            $table->unsignedInteger('methods_id');
            $table->unsignedInteger('forms_id');

            $table->index('methods_id', 'fk_payment_method_forms_methods');
            $table->index('forms_id', 'fk_payment_method_forms_forms');

            $table->foreign('methods_id', 'fk_payment_method_forms_methods')
                ->references('methods_id')->on('payment_methods')
                ->onDelete('cascade');

            $table->foreign('forms_id', 'fk_payment_method_forms_forms')
                ->references('forms_id')->on('payment_forms')
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
