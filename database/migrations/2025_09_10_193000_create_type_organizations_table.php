<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTypeOrganizationsTable extends Migration
{
    public function up(): void
    {
        Schema::create('type_organizations', function (Blueprint $table) {

            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('bold_name')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_organizations');
    }
}
