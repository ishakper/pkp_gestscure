<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credential_device_syncs', function (Blueprint $table) {
            $table->uuid('lease_token')->nullable()->unique();
            $table->dateTime('lease_expires_at')->nullable()->index();
            $table->dateTime('network_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credential_device_syncs', function (Blueprint $table) {
            $table->dropColumn(['lease_token', 'lease_expires_at', 'network_started_at']);
        });
    }
};
