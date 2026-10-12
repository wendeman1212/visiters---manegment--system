<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('host_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('previous_host_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('new_host_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('transferred_by')->constrained('users')->onDelete('cascade');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index('visit_request_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('host_transfers');
    }
};
