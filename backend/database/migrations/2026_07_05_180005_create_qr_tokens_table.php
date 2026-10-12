<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_request_id');
            $table->string('token_value')->unique()->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('status')->default('active'); // active, used, expired
            $table->timestamps();

            $table->foreign('visit_request_id')->references('id')->on('visit_requests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_tokens');
    }
};
