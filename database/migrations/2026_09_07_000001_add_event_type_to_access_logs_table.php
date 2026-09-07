<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('access_logs')) {
            Schema::table('access_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('access_logs', 'event_type')) {
                    $table->string('event_type')->nullable()->default('STANDARD_TAP')->after('nik');
                    $table->index('event_type');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_logs')) {
            Schema::table('access_logs', function (Blueprint $table) {
                if (Schema::hasColumn('access_logs', 'event_type')) {
                    $table->dropColumn('event_type');
                }
            });
        }
    }
};
