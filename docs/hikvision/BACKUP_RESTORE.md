# SOP: BACKUP AND RESTORE

**PURPOSE**: Define backup and restore procedures for device configuration, users, and card data.  
**SCOPE**: DS-K1T804AMF @ DOOR-B.  
**AUTHORIZATION REQUIRED**: Backup — none (read-only export). Restore (write) — infrastructure lead approval.  

---

## What to Back Up

A complete device state backup consists of 4 parts:

| Part | Source Endpoint | Format | Frequency |
|---|---|---|---|
| 1. Device config | `/System/configurationData` | XML binary / export file | Weekly + pre-change |
| 2. User list | `/AccessControl/UserInfo/Search` (paginated) | JSON | Daily + pre-change |
| 3. Card list | `/AccessControl/CardInfo/Search` (paginated) | JSON | Daily + pre-change |
| 4. Device status snapshot | `/AccessControl/AcsWorkStatus` + `/AccessControl/CardReaderCfg/1` | JSON | Pre-change |

**Fingerprint templates**: CANNOT be backed up via ISAPI (see `FINGERPRINT_PRIVACY.md`). Enrollment count is recorded, but physical templates live only on device.

---

## PROCEDURE — Pre-Change Backup (Mandatory before ANY write)

Run artisan backup command:

```bash
php artisan hikvision:backup DOOR-B
```

Or via Tinker:
```php
$svc = app(App\Services\HikvisionIsapiService::class);
$door = App\Models\Door::where('door_id', 'DOOR-B')->first();

// Dump users
$users = $svc->fetchUsers($door);
file_put_contents(
    storage_path("backups/door_b_users_" . date('Ymd_His') . ".json"),
    json_encode($users, JSON_PRETTY_PRINT)
);

// Dump cards
$cards = $svc->fetchCards($door);
file_put_contents(
    storage_path("backups/door_b_cards_" . date('Ymd_His') . ".json"),
    json_encode($cards, JSON_PRETTY_PRINT)
);

// Dump status
$status = $svc->getDeviceStatus($door);
file_put_contents(
    storage_path("backups/door_b_status_" . date('Ymd_His') . ".json"),
    json_encode($status, JSON_PRETTY_PRINT)
);
```

### Verification of Backup

Before proceeding to any write operation, verify:
- [ ] User backup file exists and is > 0 bytes
- [ ] User count in JSON matches `UserInfo/Search totalMatches` (currently 97)
- [ ] Card backup file exists and is > 0 bytes
- [ ] Card count matches `AcsWorkStatus.cardNum` (currently 83)
- [ ] Status file contains valid JSON with model and serial

**If any check fails: DO NOT PROCEED WITH THE WRITE OPERATION.**

---

## PROCEDURE — Restore Users to Device (WRITE)

> Requires: infrastructure lead approval + verified backup file

If device loses user data (e.g. after accidental factory reset or hardware replacement):

1. Confirm target device is clean (0 users or only admin)
2. Load backup file:
```php
$backupFile = storage_path('backups/door_b_users_YYYYMMDD_HHMMSS.json');
$users = json_decode(file_get_contents($backupFile), true);
```
3. Iterate and push users in batches of 10:
```php
foreach ($users as $user) {
    // PUT to /ISAPI/AccessControl/UserInfo/Record
    // Log success/failure per user
}
```
4. Verify user count: `UserInfo/Search totalMatches` must match backup count
5. Load card backup file and push cards:
```php
foreach ($cards as $card) {
    // PUT to /ISAPI/AccessControl/CardInfo/Record
}
```
6. Verify card count: `AcsWorkStatus.cardNum` must match backup count

### What Cannot Be Restored via Software

- **Fingerprints**: All 24 employees with fingerprints must physically re-enroll at the terminal.  
  The backup records WHICH employees had fingerprints enrolled (from `userVerifyMode` and app `biometric_statuses`), allowing HR to contact them for re-enrollment.
- **PIN codes**: If configured on device, must be re-entered manually.

---

## Backup Storage and Retention

- Backups stored on application server at: `storage/backups/hikvision/`
- Also replicated to daily off-site server backup
- Retention: keep daily backups for 30 days; keep pre-change backups indefinitely
- Backup files contain employee names and card numbers — must have restricted permissions (`chmod 600`)

---

## STOP CONDITIONS

- Do NOT overwrite an existing backup file — always use timestamped filenames
- If backup export returns empty array: abort, do not use as baseline
- If restore error rate > 5%: pause restore, investigate device error responses

---

## AUDIT EVIDENCE

Log for every backup:
- Timestamp, Door ID
- User count exported
- Card count exported
- Backup file paths and SHA256 checksums

Log for every restore:
- Backup file used (path + checksum)
- Users restored count / failed count
- Cards restored count / failed count
- Initiating admin
- Authorizer reference
