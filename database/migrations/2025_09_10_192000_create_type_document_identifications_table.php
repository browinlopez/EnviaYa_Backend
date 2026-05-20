<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('type_document_identifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name_sp', 100);
            $table->string('name_eng', 100);
            $table->string('bold_name', 100)->nullable();
            $table->string('code', 10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('type_document_identifications');
    }
};
