<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('doors') && !Schema::hasColumn('doors', 'gateway')) {
            Schema::table('doors', function (Blueprint $table) {
                $table->string('gateway')->nullable()->default('192.168.90.1')->after('device_ip');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('doors') && Schema::hasColumn('doors', 'gateway')) {
            Schema::table('doors', function (Blueprint $table) {
                $table->dropColumn('gateway');
            });
        }
    }
};
