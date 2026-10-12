<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('representative_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('visit_request_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('visit_request_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('representative_links');
    }
};
