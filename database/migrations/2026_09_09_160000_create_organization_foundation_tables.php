<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) { $table->id(); $table->string('code')->unique(); $table->string('name')->unique(); $table->string('description')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); });
        Schema::create('divisions', function (Blueprint $table) { $table->id(); $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete(); $table->string('code')->unique(); $table->string('name'); $table->boolean('is_active')->default(true); $table->timestamps(); });
        Schema::create('positions', function (Blueprint $table) { $table->id(); $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete(); $table->string('code')->unique(); $table->string('name'); $table->boolean('is_active')->default(true); $table->timestamps(); });
        Schema::create('zones', function (Blueprint $table) { $table->id(); $table->foreignId('building_id')->constrained()->cascadeOnUpdate()->restrictOnDelete(); $table->string('code')->unique(); $table->string('name'); $table->boolean('is_active')->default(true); $table->timestamps(); });
    }
    public function down(): void { Schema::dropIfExists('zones'); Schema::dropIfExists('positions'); Schema::dropIfExists('divisions'); Schema::dropIfExists('buildings'); }
};
