# SOP: USER RECONCILIATION — DEVICE VS APPLICATION

**PURPOSE**: Detect and resolve divergence between users enrolled on device and employees in the application database.  
**SCOPE**: `PhysicalUserReconciliationService::reconcile()`, DOOR-B.  
**AUTHORIZATION REQUIRED**: Read (dry-run) — none. Write (apply) — infrastructure lead approval.  

---

## PREREQUISITES

- Device reachable (ISAPI read access)
- Application database accessible
- For write operations: `BACKUP_RESTORE.md` backup completed first

---

## Reconciliation States

Each device user is classified into one of these states:

| State | Definition |
|---|---|
| MATCHED_EXISTING | Device user's `employeeNo` maps to an active application employee |
| MISSING_IN_APP | Device has user with `employeeNo` that does not exist in application |
| MISSING_ON_DEVICE | Application employee has door assignment for DOOR-B but no corresponding device user |
| DRIFTED | Device user exists and maps to app employee, but data differs (name, card, auth mode) |
| DUMMY_ONLY | Device user whose `employeeNo` maps to a seed/dummy employee (USR-10xx pattern) |
| PENDING_CREATION | Application employee with door assignment but not yet provisioned to device |

---

## Current State (2026-09-18)

| State | Count |
|---|---|
| MATCHED_EXISTING | 96 |
| PENDING_CREATION | 1 (empNo=101 "Azis") |
| DUMMY_ONLY | 101 (12 real USR-10xx dummies + 89 in-app-only) |
| MISSING_IN_APP | 0 |
| MISSING_ON_DEVICE | 0 (Building B assignments not yet provisioned) |
| DRIFTED | unknown — not yet audited |

---

## Running Dry-Run Reconciliation

```bash
php artisan hikvision:reconcile DOOR-B --dry-run
```

Or via Tinker:
```php
$svc = app(App\Services\PhysicalUserReconciliationService::class);
$door = App\Models\Door::where('door_id', 'DOOR-B')->first();
$result = $svc->reconcile($door, false);  // false = dry-run
dump($result['stats']);
```

Dry-run is safe. It makes zero device or database writes.

---

## Reconciliation Invariant

```
device_users = MATCHED_EXISTING + DUMMY_ONLY + MISSING_IN_APP
```

Verify: 97 = 96 + 0 + 1 (pending, not yet on device) — consistent.

---

## Desired State

After all provisioning is complete:
- All 96 real employees → on device (MATCHED_EXISTING=96)
- 0 DUMMY_ONLY users on device (dummies deprovisioned)
- 0 MISSING_IN_APP
- 0 PENDING_CREATION
- 1 PENDING_CREATION resolves to MATCHED_EXISTING when "Azis" is provisioned

---

## PROCEDURE — Pre-Write Reconciliation Audit

**Run before any write operation** to establish baseline.

1. Run dry-run: `php artisan hikvision:reconcile DOOR-B --dry-run`
2. Record counts in audit log: MATCHED, MISSING_IN_APP, MISSING_ON_DEVICE, DUMMY_ONLY, DRIFTED
3. Confirm invariant holds
4. Review any DRIFTED users — determine if drift is intentional or error
5. Document expected post-write state

---

## PROCEDURE — Apply Reconciliation (WRITE — Gate 12 controlled)

> ⚠️ **GATE 12 STOP ACTIVE** — Do not apply until write gates cleared. See `BACKUP_RESTORE.md` and production migration plan.

1. Complete pre-write audit above
2. Complete `BACKUP_RESTORE.md` backup
3. Run with apply flag: `php artisan hikvision:reconcile DOOR-B --apply`
4. Monitor device response for each write (expect 200 per user)
5. Immediately run dry-run reconciliation again → MATCHED should increase, MISSING should decrease
6. Record new counts in audit log

---

## DRIFT DETECTION

Drift occurs when device user data does not match application employee data:
- Name differs (typo, name change)
- Card number differs (card replaced, number changed)
- `userVerifyMode` differs from expected policy

```php
// Detect drift in reconcile result
$drifted = collect($result['users'])->filter(fn($u) => $u['state'] === 'DRIFTED');
```

For each drifted user:
1. Determine authoritative source (application DB = source of truth)
2. Correct device via `UserInfo/Record` PUT (write operation — Gate 12 controlled)
3. Log old value and new value in audit trail

---

## DUMMY USER POLICY

Dummy employees (USR-1001..USR-1012) are seeder artifacts used in development.

**Policy**:
- Dummies MUST NOT have real door access in production
- If found on device with active card: flag for manual review
- Removal from device requires explicit authorization
- Do not auto-delete dummies without reconciliation audit showing they have no real access logs

---

## VALIDATION

After any write reconciliation:
- [ ] Dry-run immediately after apply shows MATCHED_EXISTING increased
- [ ] No MISSING_IN_APP introduced (unrecognized users)
- [ ] Device event log shows no unexpected access events during reconciliation window
- [ ] Application `access_logs` not polluted with reconciliation-related events

---

## STOP CONDITIONS

Stop apply and rollback if:
- Any device write returns non-200 response
- MATCHED_EXISTING count decreases after apply
- New MISSING_IN_APP users appear (device has users application doesn't recognize)
- Any device connectivity interruption during apply

---

## AUDIT EVIDENCE

For each reconciliation run:
- Timestamp
- Door ID
- Mode (dry-run / apply)
- Pre-state counts (all 6 states)
- Post-state counts (apply only)
- Initiating admin (apply only)
- Any errors encountered
