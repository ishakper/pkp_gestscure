# SOP: DEVICE OFFLINE RECOVERY

**PURPOSE**: Define detection, classification, and recovery procedures when a device goes offline.  
**SCOPE**: Network drop, device power loss, application connectivity failure for DOOR-B.  
**AUTHORIZATION REQUIRED**: Investigation — none. Remediation (reboot, reset) — facilities/infrastructure lead.  

---

## Detection

A device is considered OFFLINE when any of:
1. Ping to 192.168.90.15 fails (3 consecutive packets dropped)
2. ISAPI ping returns connection refused or timeout (> 10s)
3. AlertStream drops and fails to reconnect for > 2 minutes (`listener_count=0`)
4. Daily health check marks reachability as FAIL

---

## Classification

| Scenario | Symptoms | Probable Cause |
|---|---|---|
| A: Network drop | Ping fails, other devices on subnet work | Switch port down, cable damaged, VLAN misconfigured |
| B: Power loss | Ping fails, device display/LEDs completely off | AC power disconnected, PSU failure, circuit breaker tripped |
| C: App layer crash | Ping OK, ISAPI times out / refuses connection | Firmware hang, memory exhaustion on device |
| D: Credential lock | Ping OK, ISAPI returns 401 | Password changed externally or lockout triggered |
| E: Local subnet | Ping fails for all devices on 192.168.90.0/24 | Router/gateway down, NIC failure on server |

---

## Offline Recovery Procedure

```
CLASSIFY → ISOLATE → LOCAL CHECK → RESTORE POWER/NET → RECONNECT STREAM → RECONCILE
```

### Step 1: CLASSIFY (Remote, 0–5 min)

```bash
# Test 1: Ping device
ping 192.168.90.15 -n 4

# Test 2: Ping gateway
ping 192.168.90.1 -n 2

# Test 3: ISAPI port check
Test-NetConnection -ComputerName 192.168.90.15 -Port 80

# Test 4: Container status
docker inspect pkp_securegate_alertstream_door_b --format '{{.State.Status}}'
```

Record classification (Scenario A, B, C, D, or E).

### Step 2: LOCAL / PHYSICAL CHECK (Facilities, 5–15 min)

If remote checks cannot resolve:
1. Dispatch facilities or on-site IT to Door B
2. Verify:
   - Device display: is it lit? Showing normal screen or error?
   - Power LED: on, blinking, or off?
   - Ethernet link lights: green/amber blinking, or completely dark?
   - Physical damage, water ingress, cable pulled?

### Step 3: RESOLVE CAUSE

| Cause | Action |
|---|---|
| Power off | Check AC power strip, reconnect, verify power LED |
| Cable unplugged | Re-seat RJ-45 cable, check link light |
| Firmware hung | Power-cycle device: pull power, wait 15 seconds, reconnect |
| Network issue | Check switch port, verify VLAN 90 assignment |

### Step 4: RECONNECT ALERTSTREAM

After device returns online (ping OK and ISAPI 200):

```bash
# Restart stream container
docker compose -f docker-compose.prod.yml restart alertstream_door_b

# Verify stream active
curl http://10.10.8.124:8000/metrics/json
# Expect: listener_count=1
```

### Step 5: BACKFILL MISSED EVENTS

Run `EVENT_BACKFILL.md` SOP for the downtime window to recover access events logged on device while offline.

```bash
# Check how many events on device
# Compare with application access_logs count
# Backfill from downtime start to now
```

---

## Data Loss Assessment

Hikvision DS-K1T804AMF stores up to 100,000 events locally in flash memory.  
Even with no network for days:
- **Card authentication continues to work locally** (card database is on device)
- **Events are recorded to device flash**
- **No access events are lost** as long as event log capacity (100K) is not exceeded

Network downtime affects **real-time visibility**, not physical door security.

---

## STOP CONDITIONS

- Do NOT attempt factory reset during offline troubleshooting (wipes user database)
- If power-cycle does not restore device after 2 attempts: mark hardware fault, initiate `DEVICE_REPLACEMENT.md`

---

## AUDIT EVIDENCE

Log for every offline incident:
- Outage start time (detection time)
- Outage end time (recovery confirmed)
- Total duration
- Root cause (Power / Network / Firmware / Other)
- Actions taken
- Events backfilled (count)
- Any unresolved issues
