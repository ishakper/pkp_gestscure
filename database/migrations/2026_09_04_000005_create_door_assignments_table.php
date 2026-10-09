<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('door_id')->constrained('doors')->onDelete('cascade');
            $table->enum('sync_status', ['synced', 'pending', 'failed'])->default('pending');
            $table->integer('sync_attempts')->default(0);
            $table->text('last_sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // Composite unique key constraint to prevent duplicate access assignment per door
            $table->unique(['employee_id', 'door_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('door_assignments');
    }
};
