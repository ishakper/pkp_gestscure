# SOP: DEVICE REPLACEMENT / HARDWARE DECOMMISSION

**PURPOSE**: Define the procedure for decommissioning a failed or retired device and provisioning its replacement.  
**SCOPE**: DOOR-B hardware replacement (DS-K1T804AMF).  
**AUTHORIZATION REQUIRED**: Mandatory — infrastructure lead + facilities manager written approval.  

---

## PREREQUISITES

- Replacement unit on hand (same or compatible model)
- Pre-decommission backup available (if old device is still partially readable)
- Maintenance window approved
- Physical keys and tools for mounting available
- Static IP allocation confirmed (re-use 192.168.90.15 or assign new)

---

## Replacement Workflow

```
DECOMMISSION OLD → PHYSICAL SWAP → CONFIGURE NEW → PROVISION USERS → RESTORE CARDS → RE-ENROLL FP → VERIFY
```

---

## Phase 1: Decommission Old Unit

If old device is still reachable via ISAPI:
1. Run full backup: `php artisan hikvision:backup DOOR-B`
2. Record final device state: serial number, event total, user count
3. Wipe old device (prevent credential leakage if unit is disposed or RMA'd):
   - Perform factory reset via ISAPI or hardware reset button
   - Verify device resets and contains no user data

If old device is dead/unreachable:
- Use latest available backup from `storage/backups/hikvision/`
- Check latest backup timestamp — note any gap since then

Physical removal:
1. Disconnect AC power supply
2. Disconnect Ethernet cable (label cable)
3. Disconnect door relay / lock wiring (label wires: COM, NO/NC, GND, 12V)
4. Disconnect door contact sensor wiring (label: SENSOR, GND)
5. Unmount device from wall

---

## Phase 2: Physical Installation of Replacement

1. Mount new unit to wall bracket
2. Connect door contact sensor wires (verify polarity / contact type)
3. Connect door relay wires (verify fail-secure lock uses correct NO or NC terminal)
4. Connect Ethernet cable
5. Connect AC power supply
6. Verify unit powers on: display lights up, boot sequence completes

---

## Phase 3: Initial Configuration of Replacement

1. Connect laptop to device directly (or access via factory default IP `192.168.1.64`)
2. Set admin password (follow `PASSWORD_ROTATION.md`)
3. Configure network settings:
   - IP: `192.168.90.15` (same as old unit to minimize app reconfiguration)
   - Subnet: `255.255.255.0`
   - Gateway: `192.168.90.1`
4. Configure NTP per `NTP_TIME_SYNC.md`
5. Verify reachability from application server: `ping 192.168.90.15`
6. Verify ISAPI responding: `GET /ISAPI/System/deviceInfo`
7. Record new serial number and firmware version

---

## Phase 4: Application Configuration Update

In application database:
1. Update `doors` record for DOOR-B if IP, port, or credentials changed
2. Update door hardware serial number in notes/metadata if tracked
3. Verify `doors` record:
   ```bash
   php artisan tinker --execute="
       \$d = App\Models\Door::where('door_id', 'DOOR-B')->first();
       dump(['ip' => \$d->isapi_host, 'port' => \$d->isapi_port, 'user' => \$d->isapi_username]);
   "
   ```

---

## Phase 5: User and Card Provisioning

Follow `BACKUP_RESTORE.md`:
1. Push all 97 users via ISAPI
2. Push all 83 cards via ISAPI
3. Run dry-run reconciliation: `php artisan hikvision:reconcile DOOR-B --dry-run`
4. Verify: MATCHED_EXISTING = 96, PENDING_CREATION = 1, MISSING_ON_DEVICE = 0

---

## Phase 6: Fingerprint Re-enrollment

Follow `FACTORY_RESET_RECOVERY.md` Phase 5:
- 24 employees must physically re-enroll fingerprints at new unit
- Set `userVerifyMode` for each as enrolled

---

## Phase 7: Reconnect AlertStream

1. Restart container: `docker compose -f docker-compose.prod.yml restart alertstream_door_b`
2. Verify listener count: `/metrics/json` → `listener_count=1`
3. Physical test swipe with known card → verify door unlocks and event appears in `access_logs`

---

## Physical Hardware Verification Checklist

Before releasing door to normal operation:
- [ ] Card reader beeps and LEDs illuminate on card swipe
- [ ] Electric lock clicks and door unlatches on authorized card
- [ ] Door stays unlocked for configured duration (typically 5 seconds)
- [ ] Door re-locks when closed
- [ ] Door contact sensor correctly reports state (`magneticStatus=0` when closed, `1` when open)
- [ ] Tamper switch engaged (not triggering alarm when cover closed)
- [ ] Unauthorized card produces denial beep and red LED
- [ ] Push-to-exit button (if wired to device) unlocks door

---

## Disposed Device Security

If old unit is being returned (RMA) or scrapped:
- MUST be factory reset before leaving premises
- If device cannot power on to reset: destroy flash memory chip physically
- Record disposal in asset register

---

## AUDIT EVIDENCE

Document in asset tracking and change management:
- Old unit serial, model, retirement date
- New unit serial, model, install date, firmware version
- User restore log (counts + timestamps)
- Hardware verification checklist (signed by installer)
- Authorization reference
