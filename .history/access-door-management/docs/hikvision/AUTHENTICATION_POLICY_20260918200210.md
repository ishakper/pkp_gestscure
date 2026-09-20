# SOP: AUTHENTICATION POLICY

**PURPOSE**: Define valid authentication modes, how they are set, and how drift is detected.  
**SCOPE**: `userVerifyMode` field on device users, `biometric_statuses` table.  
**AUTHORIZATION REQUIRED**: Mode changes require supervisor approval and employee consent (for fingerprint modes).  

---

## Authentication Modes (Hikvision ISAPI)

| Mode String | Meaning | Suitable For |
|---|---|---|
| `""` (empty) | Device default (typically card) | Default enrollment |
| `"card"` | Card number only | Employees without fingerprint enrolled |
| `"fp"` | Fingerprint only | Employees with fingerprint, no card |
| `"fpOrCard"` | Fingerprint OR Card (either sufficient) | Employees with both enrolled |
| `"fpAndCard"` | Fingerprint AND Card (both required) | High-security zones |
| `"pin"` | PIN only | Not recommended for this deployment |
| `"cardAndPin"` | Card + PIN | Not in use |

---

## Current Policy (Building B)

| Mode | Policy |
|---|---|
| Default | Allowed — device applies card auth |
| Card | Allowed |
| Fingerprint only | Allowed if employee has enrolled fingerprint |
| Card OR FP | Recommended for dual-enrolled employees |
| Card AND FP | Not deployed (would require all employees to enroll FP) |

---

## Current Mode Distribution (2026-09-18)

| Mode | Count |
|---|---|
| `""` (default) | 37 |
| `"card"` | 37 |
| `"fp"` | 11 |
| `"fpOrCard"` | 12 |
| Total | 97 |

---

## Mode Assignment Rules

1. Employee has card only → mode: `"card"` (or `""` default)
2. Employee has fingerprint only → mode: `"fp"`
3. Employee has both card and fingerprint → mode: `"fpOrCard"` (preferred) or `"fpAndCard"` if policy requires
4. Employee has neither → no access (no device user until provisioned)

---

## AUTH_MODE_DRIFT Detection

Drift = device `userVerifyMode` does not match application expected mode for that employee.

**Sources of drift**:
- Admin changed mode directly on device without updating application
- Employee enrolled/removed fingerprint without application reconciliation
- Card assigned/revoked without mode update

**Drift detection during reconciliation**:
```php
// In PhysicalUserReconciliationService
$expectedMode = $employee->biometric_status->expected_verify_mode;  // from app DB
$deviceMode = $deviceUser['userVerifyMode'] ?? '';

if ($deviceMode !== $expectedMode) {
    $user['state'] = 'DRIFTED';
    $user['drift_fields'][] = ['field' => 'userVerifyMode', 'expected' => $expectedMode, 'actual' => $deviceMode];
}
```

---

## PROCEDURE — Change Authentication Mode (WRITE)

> Requires: supervisor approval + employee consent (if adding fingerprint requirement)

1. Verify employee exists on device (MATCHED_EXISTING in reconciliation)
2. Verify fingerprint enrolled if mode requires FP (`fp`, `fpOrCard`, `fpAndCard`)
3. Prepare ISAPI payload:
```json
{
    "UserInfo": {
        "employeeNo": "<empNo>",
        "userVerifyMode": "<new_mode>"
    }
}
```
4. PUT to `/ISAPI/AccessControl/UserInfo/Record`
5. Verify: re-read user from device and confirm `userVerifyMode` updated
6. Update `biometric_statuses.expected_verify_mode` in application DB
7. Log in `activity_logs`

---

## PROCEDURE — Detect Drift (READ — dry-run safe)

```bash
php artisan hikvision:reconcile DOOR-B --dry-run --report-drift
```

Review DRIFTED users in output. For each:
- Determine if drift is intentional (admin changed for valid reason) or unexpected
- If unexpected: schedule mode correction
- If intentional: update application `expected_verify_mode` to match device

---

## VALIDATION

After mode change:
- [ ] Device `userVerifyMode` matches intended value (re-read via UserInfo/Search)
- [ ] `biometric_statuses.expected_verify_mode` updated in application
- [ ] Employee can authenticate with new mode (test swipe)
- [ ] `activity_logs` entry created

---

## STOP CONDITIONS

Stop mode change and investigate if:
- Device returns non-200 on UserInfo/Record PUT
- Post-change re-read shows mode unchanged
- Employee reports inability to authenticate after mode change (revert immediately)

**Revert procedure**: PUT `UserInfo/Record` with previous mode value.

---

## AUDIT EVIDENCE

Log for each mode change:
- Employee ID and name
- Previous mode
- New mode
- Authorization reference (supervisor name, date)
- Initiating admin
- Device response status
- Verification result (re-read match)
