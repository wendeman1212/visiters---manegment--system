<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DB-driven, scoped lockdown records. A lockdown is either
        //   - company-wide:  branch_id = NULL, scope_type = 'company'
        //   - branch-level:  branch_id = <branch>, scope_type = 'branch'
        // This lets the system distinguish "whole company" from "one branch"
        // without affecting other companies or un-affected branches.
        Schema::create('security_lockdowns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->enum('scope_type', ['company', 'branch'])->default('company');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->foreign('started_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('ended_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['company_id', 'branch_id', 'status']);
        });

        // DB-driven, scoped announcement records (the CEO broadcast feature).
        // branch_id = NULL means "the whole company", branch_id set means the
        // announcement is only delivered to that branch's users.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->enum('scope_type', ['company', 'branch'])->default('company');
            $table->text('message');
            $table->enum('priority', ['info', 'warning', 'critical'])->default('info');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['company_id', 'branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('security_lockdowns');
    }
};
