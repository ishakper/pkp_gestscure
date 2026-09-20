# SOP: CARD MANAGEMENT

**PURPOSE**: Define how physical access cards are assigned, tracked, and hashed in the system.  
**SCOPE**: `credential_records` table, `CardInfo` ISAPI endpoints, card hash transition.  
**AUTHORIZATION REQUIRED**: Read — none. Write (assign/revoke card) — supervisor approval.  

---

## PREREQUISITES

- Employee record exists in `employees` table
- For new card assignment: card number obtained from physical card (Mifare UID or printed number)
- `BACKUP_RESTORE.md` backup completed before batch card operations

---

## Card Data Model

```
credential_records
├── employee_id         FK → employees.id
├── card_number         Raw card number (hidden from API responses)
├── card_number_hash    HMAC-SHA256 of normalized card number (pending migration)
├── issued_at
└── revoked_at
```

`card_number` and `card_number_hash` are in `$hidden` on `CredentialRecord` — never serialized in API responses.

---

## Card Number Normalization

Before hashing, normalize card number:
1. Strip all whitespace
2. Uppercase
3. Remove leading zeros if format is decimal
4. Canonical form: plain decimal string (e.g. `"12345678"`)

```php
// Normalization example
$normalized = strtoupper(trim(ltrim($rawCardNumber, '0'))) ?: '0';
```

**Consistency is critical**: The same physical card must always normalize to the same string, or hash lookup will fail.

---

## Card Hash (HMAC-SHA256)

```php
$hash = hash_hmac('sha256', $normalizedCardNumber, config('app.key'));
```

**Purpose**: Allow card-number lookup without storing raw card number in application query results.

**Transition status** (as of 2026-09-18):
- `CARD_HASH_RUNTIME_MIGRATION_COMPLETE=NO`
- Column `card_number_hash` exists locally (batch 2 migration ran)
- Column NOT YET on production (`add_card_number_hash_to_credential_records_table` = Pending)
- Existing rows do NOT have hash populated — requires artisan backfill command after migration

**Do not query by hash until migration and backfill are complete.**

---

## Card Hash Transition Plan

1. Run pending migration on production: adds `card_number_hash` column (nullable)
2. Run backfill: `php artisan credentials:backfill-hashes` — populates hash for all existing rows
3. Validate: every `credential_record` where `card_number IS NOT NULL` must have `card_number_hash NOT NULL`
4. Set `CARD_HASH_RUNTIME_MIGRATION_COMPLETE=YES` in environment/config
5. Application switches card lookup to use hash instead of raw number

> ⚠️ **Gate 12 Stop**: Steps 1–5 blocked until production write gates cleared.

---

## Current State (2026-09-18)

| Metric | Value |
|---|---|
| Cards on device | 83 |
| Employees with card | 82 (out of 96 matched) |
| Employees without card | 14 |
| `card_number_hash` populated on production | NO (migration pending) |

---

## PROCEDURE — Assign Card to Employee (WRITE)

1. Verify employee exists: `Employee::where('employee_id', $empId)->firstOrFail()`
2. Verify no existing active card for employee: `CredentialRecord::where('employee_id', $id)->whereNull('revoked_at')->count() === 0`
3. Read card number from physical card (card enrollment station or manual entry)
4. Normalize card number
5. Compute hash: `hash_hmac('sha256', $normalized, config('app.key'))`
6. Create `CredentialRecord`: `card_number = $normalized, card_number_hash = $hash, issued_at = now()`
7. Push card to device via ISAPI: `CardInfo/Record` PUT with employee's device user ID
8. Verify device accepted: response 200 and card appears in `CardInfo/Search`
9. Log assignment in `activity_logs`

---

## PROCEDURE — Revoke Card (WRITE)

1. Find active `CredentialRecord` for employee
2. Set `revoked_at = now()`
3. Remove card from device: `CardInfo/Delete` DELETE with card number
4. Verify card no longer in device `CardInfo/Search`
5. Log revocation in `activity_logs`

---

## Card on Device vs Application Divergence

If `CardInfo/Search` shows a card not in `credential_records`:
- Do NOT auto-delete from device
- Flag as UNRECOGNIZED_CARD
- Investigate: may be admin-added directly on device
- Escalate to `INCIDENT_RESPONSE.md` if unauthorized card found

---

## VALIDATION

After any card operation:
- [ ] Device card count matches expected (check `AcsWorkStatus.cardNum`)
- [ ] `CredentialRecord` state reflects operation
- [ ] Employee can authenticate (if assigning) or cannot (if revoking) — verify via test swipe if possible
- [ ] `activity_logs` entry created

---

## STOP CONDITIONS

- Stop if device `CardInfo/Record` PUT returns non-200
- Stop if device card count does not change after operation
- Stop batch operations if more than 2 consecutive failures

---

## AUDIT EVIDENCE

Every card assignment or revocation must log:
- Employee ID and name
- Card number (last 4 digits only in logs — never full number)
- Card hash
- Operation (assign/revoke)
- Initiating admin
- Device response status
- Timestamp
