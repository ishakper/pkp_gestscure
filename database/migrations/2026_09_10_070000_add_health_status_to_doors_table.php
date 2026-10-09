<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doors', function (Blueprint $table) {
            $table->string('health_status')->nullable()->after('connection_status');
        });
    }

    public function down(): void
    {
        Schema::table('doors', function (Blueprint $table) {
            $table->dropColumn('health_status');
        });
    }
};