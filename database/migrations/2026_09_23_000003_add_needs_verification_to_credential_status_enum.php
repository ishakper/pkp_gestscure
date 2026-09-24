<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds 'needs_verification' to the credential_status enum.
     * This status is used for credentials where the source identity (display_name)
     * is missing, empty, or incomplete, requiring manual verification before
     * confident classification.
     */
    public function up(): void
    {
        // For MySQL, modify the enum
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE employees 
                MODIFY credential_status ENUM(
                    'confirmed_from_backup',
                    'expected_from_backup',
                    'verified',
                    'conflict',
                    'needs_verification',
                    'unknown'
                ) DEFAULT 'unknown' NULLABLE
            ");
        }
        
        // For SQLite (testing), we can't modify enums, so just track it logically
        // SQLite tests will pass because enum validation happens at the app level
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // For MySQL, revert the enum
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE employees 
                MODIFY credential_status ENUM(
                    'confirmed_from_backup',
                    'expected_from_backup',
                    'verified',
                    'conflict',
                    'unknown'
                ) DEFAULT 'unknown' NULLABLE
            ");
        }
    }
};
