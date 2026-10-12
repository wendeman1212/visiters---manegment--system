<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_request_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_name');
            $table->string('visitor_id_document')->nullable();
            $table->string('visitor_photo_url')->nullable();
            $table->string('host_name');
            $table->string('department');
            $table->string('purpose')->nullable();
            $table->string('scheduled_time');
            $table->string('duration');
            $table->string('priority')->default('normal');
            $table->boolean('is_vip')->default(false);
            $table->string('qr_token')->nullable();
            $table->string('badge_color')->default('#6366f1');
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_badges');
    }
};
