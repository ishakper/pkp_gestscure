<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->onDelete('set null');
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description');
            $table->timestamp('timestamp');
            $table->timestamps();

            $table->index('admin_id');
            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
