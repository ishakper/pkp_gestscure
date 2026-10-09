# SOP: DEVICE HEALTH MONITORING

**PURPOSE**: Define how device health is assessed, thresholds, and escalation paths.  
**SCOPE**: DS-K1T804AMF at DOOR-B, application health metrics.  
**AUTHORIZATION REQUIRED**: None (read-only checks). Remediation may require write authorization.  

---

## Health Check Components

### 1. Device Reachability

```bash
# Ping check
ping 192.168.90.15 -n 3

# ISAPI check (authoritative)
curl -u "admin:$PASS" --digest "http://192.168.90.15:80/ISAPI/System/time" -s -o /dev/null -w "%{http_code}"
# Expected: 200
```

| Result | Status | Action |
|---|---|---|
| Ping OK + ISAPI 200 | ✅ HEALTHY | Continue |
| Ping OK + ISAPI 401 | ⚠️ AUTH ISSUE | Check credentials; rotate if needed |
| Ping OK + ISAPI 5xx | ⚠️ DEVICE ERROR | Wait 60s; retry; if persistent: on-site inspection |
| Ping FAIL | ❌ OFFLINE | `DEVICE_OFFLINE.md` SOP |

### 2. AlertStream Container

```bash
docker inspect pkp_securegate_alertstream_door_b --format '{{.State.Status}}'
# Expected: running

docker logs pkp_securegate_alertstream_door_b --tail=10
# No ERROR lines, recent event timestamps
```

### 3. Prometheus Metrics (Application Layer)

```bash
curl http://10.10.8.124:8000/metrics/json
```

Key metrics:

| Metric | Healthy Value | Warning | Critical |
|---|---|---|---|
| `listener_count` | 1 | 0 for < 2 min | 0 for > 2 min |
| `securegate_event_ingestion_total{status="processed"}` | Incrementing | Flat > 30 min | Flat > 2 hours |
| `securegate_event_ingestion_total{status="error"}` | 0 or very low | > 5% of processed | > 20% of processed |
| `mapping_coverage_ratio` | ≥ 0.95 | 0.80–0.95 | < 0.80 |

### 4. Capacity Utilization

| Resource | Current | Warning | Critical |
|---|---|---|---|
| Users (3000 max) | 97 (3.2%) | > 70% | > 90% |
| Cards (3000 max) | 83 (2.8%) | > 70% | > 90% |
| Fingerprints (3000 max) | 24 (0.8%) | > 70% | > 90% |
| Event log (100K max) | 22,923 (22.9%) | > 70% | > 90% |

### 5. Clock Offset

| Offset | Status | Action |
|---|---|---|
| < 30s | OK | None |
| 30–120s | WARNING | Schedule NTP fix |
| > 120s | CRITICAL | Emergency NTP fix before next business day |

Current: +72s → WARNING

### 6. Power Supply

`powerSupplyStatus`:
- `"ACPowerSupply"` → ✅ Mains power
- `"battery"` → ⚠️ On battery — check if mains outage or PSU failure
- Not present → ❌ Cannot determine — device may be failing

---

## Daily Health Check Procedure

Run daily (or via scheduled artisan command):

```bash
php artisan hikvision:health-check DOOR-B
```

Expected output:
```
DOOR-B HEALTH REPORT
  REACHABLE:      YES
  ISAPI_AUTH:     OK
  STREAM:         RUNNING (listener_count=1)
  CLOCK_OFFSET:   +72s [WARNING]
  USERS:          97/3000 (3.2%) [OK]
  CARDS:          83/3000 (2.8%) [OK]
  FINGERPRINTS:   24/3000 (0.8%) [OK]
  EVENT_LOG:      22923/100000 (22.9%) [OK]
  POWER:          ACPowerSupply [OK]
  TAMPER:         close [OK]
  ALARMS:         none [OK]
  OVERALL:        WARNING (clock drift)
```

---

## Escalation Matrix

| Condition | Notify | Within |
|---|---|---|
| Device offline > 5 min | Infrastructure lead + security | 5 min |
| Clock offset > 120s | Infrastructure lead | 30 min |
| Tamper alarm | Security team + management | Immediate |
| Event log > 90% capacity | Infrastructure lead | 24 hours |
| User/card capacity > 90% | Infrastructure lead | 24 hours |
| Stream listener_count=0 > 2 min | On-call engineer | 5 min |

---

## AUDIT EVIDENCE

Store daily health check results in `securegate_metrics` table or external monitoring system.

Minimum retention: 90 days of health check records.
