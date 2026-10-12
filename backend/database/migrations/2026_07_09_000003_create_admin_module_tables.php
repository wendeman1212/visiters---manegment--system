<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company / branch settings
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('VMS Enterprise');
            $table->string('branch_name')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('timezone')->default('Africa/Addis_Ababa');
            $table->string('date_format')->default('Y-m-d');
            $table->timestamps();
        });

        // Working hours per day
        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('day_of_week'); // 0=Sun, 1=Mon...6=Sat
            $table->boolean('is_working_day')->default(true);
            $table->time('open_time')->default('08:00:00');
            $table->time('close_time')->default('19:00:00');
            $table->timestamps();
        });

        // Visitor categories (VIP, Contractor, Supplier, etc.)
        Schema::create('visitor_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->default('#6366f1');
            $table->boolean('requires_approval')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->integer('max_duration')->default(480); // minutes
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Notification templates
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event');        // visit_approved, visit_rejected, etc.
            $table->string('channel');      // email, sms, in_app
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Watchlist (different from blacklist — flagged for monitoring)
        Schema::create('watchlist', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('id_document_no')->index();
            $table->string('reason');
            $table->string('level')->default('caution'); // caution, high, critical
            $table->unsignedBigInteger('added_by')->nullable();
            $table->foreign('added_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamps();
        });

        // Badge / QR templates config
        Schema::create('badge_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('visitor'); // visitor, vip, contractor
            $table->string('color')->default('#6366f1');
            $table->boolean('show_photo')->default(false);
            $table->boolean('show_qr')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // System integrations status log
        Schema::create('system_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // email, sms, webhook, ldap
            $table->string('status')->default('inactive'); // active, inactive, error
            $table->text('config')->nullable(); // JSON
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_integrations');
        Schema::dropIfExists('badge_templates');
        Schema::dropIfExists('watchlist');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('visitor_categories');
        Schema::dropIfExists('working_hours');
        Schema::dropIfExists('company_settings');
    }
};
