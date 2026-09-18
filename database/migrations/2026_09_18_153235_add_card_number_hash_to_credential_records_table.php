<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add card_number_hash (HMAC-SHA256 of normalized card identifier) to credential_records.
     * Preserves existing card_number column for backward compatibility.
     * card_number_hash enables deterministic lookup without exposing raw card value.
     * ponytail: populate existing rows via artisan command before relying on hash lookup.
     */
    public function up(): void
    {
        Schema::table('credential_records', function (Blueprint $table) {
            // HMAC-SHA256 of normalized card identifier — deterministic, no PII
            $table->string('card_number_hash', 64)->nullable()->after('card_number');
            $table->index('card_number_hash');
        });
    }

    public function down(): void
    {
        Schema::table('credential_records', function (Blueprint $table) {
            $table->dropIndex(['card_number_hash']);
            $table->dropColumn('card_number_hash');
        });
    }
};

