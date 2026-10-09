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
        Schema::create('securegate_metrics', function (Blueprint $table) {
            $table->string('metric_key', 128)->primary();
            $table->string('name', 64)->index();
            $table->text('labels_json')->nullable();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('securegate_metrics');
    }
};
