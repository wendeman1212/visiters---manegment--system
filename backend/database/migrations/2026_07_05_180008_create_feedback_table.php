<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_request_id');
            $table->integer('rating'); // 1-5 stars
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->foreign('visit_request_id')->references('id')->on('visit_requests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
