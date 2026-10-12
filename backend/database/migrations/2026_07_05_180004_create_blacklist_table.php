<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('id_document_no')->unique()->index();
            $table->text('reason');
            $table->unsignedBigInteger('added_by'); // Reference to users.id (admin)
            $table->timestamps();

            $table->foreign('added_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist');
    }
};
