<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Asset Categories
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedInteger('useful_life_months')->default(36);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // 2. Asset Master Records
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 32)->unique();
            $table->string('asset_name', 150);
            $table->foreignId('category_id')->nullable()->constrained('asset_categories')->nullOnDelete();
            $table->string('brand', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable()->unique();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->string('vendor', 100)->nullable();
            $table->date('warranty_start')->nullable();
            $table->date('warranty_end')->nullable();
            $table->string('building_name', 100)->nullable();
            $table->string('location', 150)->nullable();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->string('status', 32)->default('AVAILABLE'); // AVAILABLE, ASSIGNED, IN_USE, MAINTENANCE, REPAIR, LOST, DAMAGED, RETIRED, DISPOSED
            $table->string('condition', 32)->default('GOOD'); // NEW, GOOD, FAIR, POOR, DAMAGED
            $table->text('notes')->nullable();
            $table->dateTime('disposed_at')->nullable();
            $table->text('disposal_reason')->nullable();
            $table->string('disposal_method', 50)->nullable();
            $table->timestamps();

            $table->index(['status', 'building_name']);
            $table->index(['category_id', 'status']);
            $table->index('serial_number');
        });

        // 3. Asset Assignments & Handover Records
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('assignment_number', 32)->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('internship_id')->nullable()->constrained('internships')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->dateTime('assigned_at');
            $table->date('expected_return_date')->nullable();
            $table->dateTime('actual_return_date')->nullable();
            $table->string('condition_out', 32)->default('GOOD');
            $table->string('condition_in', 32)->nullable();
            $table->json('accessories')->nullable();
            $table->text('handover_notes')->nullable();
            $table->text('return_notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 32)->default('ACTIVE'); // ACTIVE, RETURN_PENDING, RETURNED, LOST, DAMAGED
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index(['internship_id', 'status']);
        });

        // 4. Asset Maintenance & Repairs
        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->id();
            $table->string('maintenance_number', 32)->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('maintenance_type', 32)->default('PREVENTIVE'); // PREVENTIVE, REPAIR, INSPECTION, WARRANTY
            $table->text('issue_description');
            $table->string('vendor', 100)->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->dateTime('opened_at');
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 32)->default('OPEN'); // OPEN, IN_PROGRESS, WAITING_PARTS, COMPLETED, CANCELLED
            $table->text('result')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['status', 'opened_at']);
        });

        // 5. Asset Incidents (Loss, Theft, Damage)
        Schema::create('asset_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number', 32)->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('incident_type', 32)->default('DAMAGED'); // LOST, DAMAGED, STOLEN, MISSING_ACCESSORY, OTHER
            $table->dateTime('incident_date');
            $table->string('location', 150)->nullable();
            $table->text('description');
            $table->text('resolution')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('status', 32)->default('REPORTED'); // REPORTED, INVESTIGATING, RESOLVED, CLOSED
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index(['status', 'incident_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_incidents');
        Schema::dropIfExists('asset_maintenances');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_categories');
    }
};
