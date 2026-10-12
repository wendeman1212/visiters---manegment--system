<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('username')->nullable()->after('last_name');
            $table->string('nationality')->nullable()->after('username');
            $table->unsignedSmallInteger('age')->nullable()->after('nationality');
            $table->enum('sex', ['male', 'female', 'other'])->nullable()->after('age');
            $table->string('profile_photo')->nullable()->after('sex');
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('username')->nullable()->after('last_name');
            $table->string('nationality')->nullable()->after('username');
            $table->unsignedSmallInteger('age')->nullable()->after('nationality');
            $table->enum('sex', ['male', 'female', 'other'])->nullable()->after('age');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'username', 'nationality', 'age', 'sex', 'profile_photo']);
        });

        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'username', 'nationality', 'age', 'sex']);
        });
    }
};
