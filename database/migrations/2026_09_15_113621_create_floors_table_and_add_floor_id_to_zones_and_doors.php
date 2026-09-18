<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proposed Migration Schema — Setup Gedung Floor Hierarchy Extension (UNEXECUTED)
     * 
     * Impact Analysis:
     * - Adds 'floors' table to bridge Building and Zone/Device level in hierarchy (Building -> Floor -> Zone -> Device).
     * - Adds nullable 'floor_id' foreign key to 'zones' table.
     * - Adds nullable 'floor_id' foreign key to 'doors' table.
     * - Safe for execution: nullable foreign keys prevent breaking any existing Doors or Zones records.
     */
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('floor_number')->default('1');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->foreignId('floor_id')->nullable()->after('building_id')->constrained()->nullOnDelete();
        });

        Schema::table('doors', function (Blueprint $table) {
            $table->foreignId('floor_id')->nullable()->after('building_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doors', function (Blueprint $table) {
            $table->dropForeign(['floor_id']);
            $table->dropColumn('floor_id');
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->dropForeign(['floor_id']);
            $table->dropColumn('floor_id');
        });

        Schema::dropIfExists('floors');
    }
};
