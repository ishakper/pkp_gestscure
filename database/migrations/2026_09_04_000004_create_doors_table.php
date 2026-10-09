<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doors', function (Blueprint $table) {
            $table->id();
            $table->string('door_id')->unique(); // e.g. DOOR-A
            $table->string('name')->nullable();
            $table->string('door_name')->nullable();
            $table->string('location');
            $table->string('ip_address')->nullable();
            $table->string('device_ip')->nullable();
            $table->string('gateway')->nullable()->default('192.168.90.1');
            $table->string('model')->default('DS-K1T804AMF');
            $table->string('device_model')->default('DS-K1T804AMF');
            $table->enum('status', ['online', 'offline', 'error'])->default('online');
            $table->enum('connection_status', ['online', 'offline', 'error'])->default('online');
            $table->boolean('is_manual_override')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doors');
    }
};
