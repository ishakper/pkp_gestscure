<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_logs', function (Blueprint $table) {
            $table->string('correlation_id')->nullable()->after('log_id');
            $table->string('source_format')->nullable()->after('source');
            $table->string('device_serial')->nullable()->after('device_ip');
            $table->integer('major_event')->nullable()->after('event_type');
            $table->integer('minor_event')->nullable()->after('major_event');
        });
    }

    public function down(): void
    {
        Schema::table('access_logs', function (Blueprint $table) {
            $table->dropColumn([
                'correlation_id',
                'source_format',
                'device_serial',
                'major_event',
                'minor_event',
            ]);
        });
    }
};
