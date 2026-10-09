# SOP: FACTORY RESET RECOVERY

**PURPOSE**: Define emergency procedures if a device is accidentally or intentionally factory reset.  
**SCOPE**: DS-K1T804AMF @ DOOR-B.  
**AUTHORIZATION REQUIRED**: Factory reset requires written infrastructure lead authorization. Recovery requires lead oversight.  

---

## The Rule on Factory Reset

> **NEVER factory reset a device without a verified pre-reset backup.**  
> Factory reset completely wipes: all 97 users, all 83 cards, all 24 fingerprints, all 22,923 events, network configuration, and admin password.  
> It is an IRREVERSIBLE ACTION for fingerprint templates.

---

## What Is Lost in Factory Reset

| Data | Recoverable from Backup? | Recovery Effort |
|---|---|---|
| Admin credentials | Reset to factory defaults | Low — set new password |
| Network (IP/subnet) | Reset to factory (192.168.1.64 typically) | Low — reassign static IP |
| User records (names/IDs) | ✅ Yes (from backup JSON) | Medium — automated restore via ISAPI |
| Card assignments | ✅ Yes (from backup JSON) | Medium — automated restore via ISAPI |
| Fingerprint templates | ❌ NO — PERMANENTLY LOST | High — all 24 employees must re-enroll in person |
| Historical event log | ❌ NO — device log wiped | Low impact if application access_logs has copy |
| Access schedules | ✅ Yes (recreate templates) | Low |

---

## Recovery Lifecycle

```
FACTORY STATE → NETWORK CONFIG → SET ADMIN PASS → RESTORE USERS/CARDS → SCHEDULE FP RE-ENROLLMENT
```

### Phase 1: Network and Admin Recovery (On-site / Console)

1. Device boots at factory IP (default for DS-K1T804AMF is `192.168.1.64`)
2. Connect laptop directly to device LAN port with static IP `192.168.1.100/24`
3. Access device web UI at `http://192.168.1.64`
4. Set new strong admin password (follow `PASSWORD_ROTATION.md` policy)
5. Reconfigure static IP to production value:
   - IP: `192.168.90.15`
   - Subnet: `255.255.255.0`
   - Gateway: `192.168.90.1`
   - DNS: company DNS
6. Reconnect device to production switch (VLAN 90 port)
7. Verify reachable from application server: `ping 192.168.90.15`

### Phase 2: ISAPI Verification

From application server:
1. Update `doors` table if password changed: `doors.isapi_password`
2. Test ISAPI ping: `php artisan tinker --execute="...pingDevice()"`
3. Confirm 200 response
4. Verify empty state: `UserInfo/Search` should show 0 or 1 user (admin)

### Phase 3: Restore Users and Cards (per BACKUP_RESTORE.md)

1. Find latest pre-reset backup file in `storage/backups/hikvision/`
2. Verify checksum against backup audit log
3. Run user restore:
   ```bash
   php artisan hikvision:restore-users DOOR-B --file=door_b_users_LATEST.json
   ```
4. Verify user count matches expected (97)
5. Run card restore:
   ```bash
   php artisan hikvision:restore-cards DOOR-B --file=door_b_cards_LATEST.json
   ```
6. Verify card count matches expected (83)

### Phase 4: Reconnect AlertStream

1. Restart container: `docker compose -f docker-compose.prod.yml restart alertstream_door_b`
2. Verify: `/metrics/json` shows `listener_count=1`
3. Perform test card swipe to confirm event ingestion

### Phase 5: Fingerprint Re-enrollment Campaign

1. Identify all employees who had fingerprints enrolled:
   ```sql
   SELECT e.employee_id, e.name, b.status, b.expected_verify_mode
   FROM employees e
   JOIN biometric_statuses b ON b.employee_id = e.id
   WHERE b.expected_verify_mode IN ('fp', 'fpOrCard')
   ```
2. Export list of 24 affected employees to HR
3. Schedule 15-minute enrollment slots at terminal for each employee
4. As each employee enrolls: update `userVerifyMode` on device to match their previous setting
5. Track progress: compare `CardReaderCfg/1.fingerPrintNum` against 24 target

---

## VALIDATION

Recovery is complete only when:
- [ ] Device IP = 192.168.90.15 and responding
- [ ] User count on device = 97
- [ ] Card count on device = 83
- [ ] Fingerprint count = 24 (after re-enrollment campaign)
- [ ] AlertStream active (`listener_count=1`)
- [ ] 489 application tests pass

---

## AUDIT EVIDENCE

Document incident in `docs/incidents/`:
- Reason for factory reset (accidental / hardware migration / firmware failure)
- Time reset occurred
- Time recovery completed
- Backup file used (path + SHA256)
- Employees contacted for fingerprint re-enrollment
- Final validation sign-off by infrastructure lead
