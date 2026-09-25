<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addDoor = function (string $column, \Closure $definition): void {
            if (Schema::hasTable('doors') && !Schema::hasColumn('doors', $column)) {
                Schema::table('doors', $definition);
            }
        };

        $addDoor('connection_mode', fn (Blueprint $t) => $t->string('connection_mode', 20)->default('auto')->after('device_model'));
        // auto = use global config defaults, manual = per-door settings
        
        $addDoor('connection_scheme', fn (Blueprint $t) => $t->string('connection_scheme', 10)->default('http')->after('connection_mode'));
        // http or https
        
        $addDoor('device_port', fn (Blueprint $t) => $t->unsignedInteger('device_port')->default(8200)->after('connection_scheme'));
        // 1-65535
        
        $addDoor('connect_timeout', fn (Blueprint $t) => $t->unsignedInteger('connect_timeout')->default(10)->after('device_port'));
        // 1-30 seconds
        
        $addDoor('read_timeout', fn (Blueprint $t) => $t->unsignedInteger('read_timeout')->default(10)->after('connect_timeout'));
        // 1-30 seconds
        
        $addDoor('verify_tls', fn (Blueprint $t) => $t->boolean('verify_tls')->default(true)->after('read_timeout'));
        // TLS certificate verification toggle
        
        $addDoor('last_connection_test_at', fn (Blueprint $t) => $t->timestamp('last_connection_test_at')->nullable()->after('verify_tls'));
        
        $addDoor('last_connection_status', fn (Blueprint $t) => $t->string('last_connection_status', 50)->nullable()->after('last_connection_test_at'));
        // online|offline|auth_error|timeout|tls_error|invalid_response
    }

    public function down(): void
    {
        Schema::table('doors', function (Blueprint $table) {
            $table->dropColumn([
                'connection_mode',
                'connection_scheme',
                'device_port',
                'connect_timeout',
                'read_timeout',
                'verify_tls',
                'last_connection_test_at',
                'last_connection_status',
            ]);
        });
    }
};
