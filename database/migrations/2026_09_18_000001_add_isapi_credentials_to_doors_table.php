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

        $addDoor('isapi_username', fn (Blueprint $t) => $t->string('isapi_username')->nullable()->after('gateway'));
        $addDoor('isapi_password', fn (Blueprint $t) => $t->text('isapi_password')->nullable()->after('isapi_username'));
        $addDoor('serial_number', fn (Blueprint $t) => $t->string('serial_number')->nullable()->after('device_model'));
        $addDoor('firmware_version', fn (Blueprint $t) => $t->string('firmware_version')->nullable()->after('serial_number'));
    }

    public function down(): void
    {
        Schema::table('doors', function (Blueprint $table) {
            $table->dropColumn(['isapi_username', 'isapi_password', 'serial_number', 'firmware_version']);
        });
    }
};
