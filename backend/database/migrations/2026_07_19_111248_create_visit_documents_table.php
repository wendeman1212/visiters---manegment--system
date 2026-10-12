<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_request_id');
            $table->string('doc_type'); // invitation_letter, visitor_list, government_letter, purchase_order, nda, other_docs
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->timestamps();

            $table->foreign('visit_request_id')->references('id')->on('visit_requests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_documents');
    }
};
