<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Separate table on purpose: the core `admins` table is left untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_two_factor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->unique()->constrained('admins')->cascadeOnDelete();
            $table->text('secret');                         // encrypted (APP_KEY)
            $table->text('recovery_codes')->nullable();     // encrypted JSON of SHA-256 hashes
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable(); // blocks TOTP replay
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_two_factor');
    }
};
