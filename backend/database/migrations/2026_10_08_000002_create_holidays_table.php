<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('holiday_date');
            // NULL = global holiday for every company.
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();

            $table->index('holiday_date');
            $table->index(['company_id', 'holiday_date']);
            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
