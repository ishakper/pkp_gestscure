<?php

namespace App\Services;

use App\Models\CredentialRecord;
use App\Models\Employee;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * CardSecurityService handles card HMAC implementation and masking.
 *
 * Card security: normalize + HMAC-SHA256 + mask for display.
 */
class CardSecurityService
{
    /**
     * Generate HMAC-SHA256 hash for card number.
     *
     * Deterministic lookup without storing raw card values.
     */
    public function hashCardNumber(string $cardNumber): string
    {
        $normalized = strtoupper(trim($cardNumber));
        $secret = config('services.card_hash_secret', config('app.key'));
        
        return hash_hmac('sha256', $normalized, $secret);
    }

    /**
     * Mask card number for UI display.
     *
     * Example: 1234567890 → ****7890
     */
    public function maskCardNumber(string $cardNumber): string
    {
        $normalized = strtoupper(trim($cardNumber));
        
        if (strlen($normalized) <= 4) {
            return '****';
        }
        
        $lastFour = substr($normalized, -4);
        return '****' . $lastFour;
    }

    /**
     * Audit card hash state WITHOUT writing.
     *
     * READ-ONLY.
     */
    public function auditCardHashing(): array
    {
        $credentials = CredentialRecord::where('card_number', '!=', '')->get();

        $total_cards = $credentials->count();
        $with_hash = $credentials->whereNotNull('card_number_hash')->count();
        $without_hash = $total_cards - $with_hash;

        // Check for hash collisions
        $hashes = $credentials->whereNotNull('card_number_hash')->pluck('card_number_hash')->toArray();
        $unique_hashes = array_unique($hashes);
        $collisions = count($hashes) - count($unique_hashes);

        return [
            'total_cards' => $total_cards,
            'with_hash' => $with_hash,
            'without_hash' => $without_hash,
            'hash_collisions' => $collisions,
            'target_backfilled' => 82,
            'current_backfilled' => $with_hash,
            'backfill_complete' => ($with_hash === 82 && $collisions === 0),
        ];
    }

    /**
     * WRITE: Backfill card_number_hash for all credential records.
     *
     * AUTHORIZATION REQUIRED.
     */
    public function backfillCardHashes(bool $authorized = false): array
    {
        if (!$authorized) {
            return ['status' => 'NOT_AUTHORIZED'];
        }

        $credentials = CredentialRecord::where('card_number', '!=', '')->get();
        
        $backfilled = 0;
        $errors = [];

        try {
            DB::beginTransaction();

            foreach ($credentials as $cred) {
                $hash = $this->hashCardNumber($cred->card_number);
                
                $cred->update(['card_number_hash' => $hash]);
                $backfilled++;
            }

            DB::commit();

            Log::warning('Card hashes backfilled', ['count' => $backfilled]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $errors[] = $e->getMessage();
            Log::error('Card hash backfill failed', $errors);
        }

        return [
            'authorized' => true,
            'backfilled' => $backfilled,
            'errors' => $errors,
        ];
    }

    /**
     * Get card info with masked identifier.
     *
     * READ-ONLY display-safe.
     */
    public function getCardInfoMasked(CredentialRecord $record): array
    {
        return [
            'id' => $record->id,
            'employee_id' => $record->employee_id,
            'masked_card' => $this->maskCardNumber($record->card_number),
            'card_hash' => $record->card_number_hash,
            'status' => $record->status,
            'assigned_at' => $record->created_at,
        ];
    }
}
