<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            return;
        }

        Schema::create('companies', function (Blueprint $table) {
            $table->id('company_id');
            $table->string('company_name', 200);
            $table->string('industry', 150)->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('status', 20)->default('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
