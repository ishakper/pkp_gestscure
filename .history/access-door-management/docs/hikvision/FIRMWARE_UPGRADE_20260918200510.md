# SOP: FIRMWARE UPGRADE

**PURPOSE**: Define the safe procedure for upgrading Hikvision device firmware.  
**SCOPE**: DS-K1T804AMF, DOOR-B.  
**AUTHORIZATION REQUIRED**: Mandatory — infrastructure lead + security team sign-off required before any firmware change.  

---

## PREREQUISITES

- Maintenance window approved (off-hours, minimum 60-minute window)
- All stakeholders notified (building access will be interrupted)
- `BACKUP_RESTORE.md` backup completed and verified
- Target firmware file obtained from official Hikvision source (not third-party)
- Test device available (preferred — validate firmware on test unit first)
- Rollback plan ready (previous firmware version identified)

---

## Current Firmware

| Field | Value |
|---|---|
| Current version | V1.4.1 build 240318 |
| Model | DS-K1T804AMF |
| Serial | GR3496472 |

---

## Firmware Upgrade Lifecycle

```
DISCOVER → REVIEW → APPROVAL → BACKUP → UPGRADE → VERIFY → CLOSE
```

### DISCOVER — Identify Available Firmware

1. Check Hikvision official portal: https://www.hikvision.com/en/support/download/firmware/
2. Filter by model: DS-K1T804AMF
3. Note: version number, release date, changelog (especially security fixes and bug fixes)
4. Compare against current: V1.4.1 build 240318

### REVIEW — Assess Impact

Before proceeding, answer:

| Question | Find Answer In |
|---|---|
| Does upgrade change ISAPI endpoints? | Firmware changelog |
| Does upgrade reset configuration? | Changelog / known issues |
| Does upgrade change authentication behavior? | Changelog |
| Any known issues with new version on this model? | Hikvision support portal |
| Has anyone else tested this version on DS-K1T804AMF? | Community/vendor feedback |

**If upgrade resets configuration**: configuration re-application must be planned step-by-step using backed-up values.

### APPROVAL — Gate

Written approval required from:
- Infrastructure lead
- Security team lead
- Building management (for access interruption)

Record approval in change management system before proceeding.

### BACKUP — Pre-Upgrade

Complete `BACKUP_RESTORE.md` full backup:
- Device configuration export
- User list (UserInfo dump)
- Card list (CardInfo dump)
- Fingerprint count (from CardReaderCfg/1)
- Event snapshot (most recent 1000 events)

Verify backup completeness before continuing.

### UPGRADE — Procedure

1. Set device to maintenance mode if supported
2. Navigate to device web UI or ISAPI upgrade endpoint
3. Upload firmware file via ISAPI:
```http
PUT /ISAPI/System/updateFirmware
Content-Type: multipart/form-data
<firmware binary>
```
4. Wait for device to acknowledge upload (200 response)
5. Device reboots automatically — wait up to 5 minutes
6. Do NOT power off device during reboot

### VERIFY — Post-Upgrade

1. Ping device — expect recovery within 5 minutes
2. ISAPI check: `GET /ISAPI/System/deviceInfo` — confirm new firmware version
3. Auth check: ISAPI credentials still work
4. User count: UserInfo/Search totalMatches = same as pre-upgrade
5. Card count: AcsWorkStatus.cardNum = same as pre-upgrade
6. Fingerprint count: CardReaderCfg/1.fingerPrintNum = same as pre-upgrade
7. AlertStream: reconnect `pkp_securegate_alertstream_door_b` container
8. Event delivery: verify events flow through AlertStream within 5 min of test swipe
9. Application test suite: `php artisan test --compact` → must pass with same count

If any check fails → initiate rollback.

### Rollback

If firmware cannot be rolled back automatically:
1. Re-upload previous firmware using same ISAPI endpoint
2. If device is unresponsive: on-site physical recovery required
3. Contact Hikvision support if device is bricked

---

## STOP CONDITIONS

Stop and escalate immediately if:
- Device does not respond within 10 minutes of firmware upload
- Post-upgrade ISAPI returns 401 (credentials may have reset)
- Post-upgrade user count differs from pre-upgrade (data loss)
- AlertStream fails to reconnect after 3 attempts

---

## AUDIT EVIDENCE

For each firmware upgrade:
- Pre-upgrade version
- Post-upgrade version
- Maintenance window start and end
- All verification check results
- Approval records
- Any issues encountered and how resolved
- Change control ticket reference
