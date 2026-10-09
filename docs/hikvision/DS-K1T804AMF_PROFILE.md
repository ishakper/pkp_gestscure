# DEVICE PROFILE — DS-K1T804AMF

**PURPOSE**: Canonical reference for the physical access control terminal deployed at Door B.  
**SCOPE**: Single device at 192.168.90.15, serving Building B entry point.  

---

## Identity

| Field | Value |
|---|---|
| Manufacturer | Hikvision |
| Model | DS-K1T804AMF |
| Serial number | GR3496472 |
| Firmware version | V1.4.1 build 240318 |
| Hardware model class | Face + Fingerprint + Card terminal (AMF = Access control, Multi-modal, Face) |
| IP address | 192.168.90.15 |
| Port | 80 (HTTP) |
| Auth method | HTTP Digest |
| Door ID in application | DOOR-B |
| Physical location | Building B, main entry |

---

## Hardware Specifications

| Component | Specification |
|---|---|
| Card reader | Built-in (Reader 1), Mifare/EM/HID compatible |
| Fingerprint reader | Built-in optical, capacity 3000 templates |
| Keypad | Yes (PIN entry) |
| Display | Yes (LCD/LED status) |
| RS-485 port | 1 port (Reader 2 slot, currently offline) |
| Wiegand | Output-capable (offline) |
| Relay/electric lock | 1 relay (electroLockNum=1) |
| Door contact sensor | Magnetic sensor (magneticStatus field) |
| Power | AC mains (ACPowerSupply confirmed) |
| Tamper protection | Anti-dismantle sensor on device and reader |

---

## Capacity

| Resource | Total | Used (2026-09-18) | Utilization |
|---|---|---|---|
| User slots | 3000 | 97 | 3.2% |
| Card slots | 3000 | 83 | 2.8% |
| Fingerprint slots | 3000 | 24 | 0.8% |
| Event log | 100,000 | 22,923 | 22.9% |

**Event log note**: When event log reaches capacity, oldest events are overwritten. At current ingestion rate (~100 events/day estimated), capacity overflow is low risk. Monitor via `securegate_metrics` table and periodic AcsEvent count probe.

---

## Current Utilization Detail

| Metric | Count | Source |
|---|---|---|
| Users on device | 97 | UserInfo/Search totalMatches |
| Matched to application employees | 96 | Reconciliation (matched_existing) |
| Pending creation (new employee) | 1 | "Azis" empNo=101 |
| Cards registered | 83 | AcsWorkStatus.cardNum |
| Users with card | 82 | Reconciliation card_registered |
| Users without card | 14 | Reconciliation no_card (out of matched 96) |
| Fingerprints enrolled | 24 | CardReaderCfg/1.fingerPrintNum |
| Historical events on device | 22,923 | AcsEvent totalNum |
| Oldest event on device | 2026-07-23 | AcsEvent search |

---

## Authentication Mode Snapshot (2026-09-18)

| Mode | Count | Percent |
|---|---|---|
| `""` (device default) | 37 | 38.1% |
| `"card"` | 37 | 38.1% |
| `"fp"` (fingerprint only) | 11 | 11.3% |
| `"fpOrCard"` | 12 | 12.4% |
| Total | 97 | 100% |

---

## Device State (2026-09-18)

| Field | Value | Interpretation |
|---|---|---|
| doorStatus | 4 | UNKNOWN (no magnetic sensor confirmation) |
| doorLockStatus | 0 | Locked |
| magneticStatus | 0 | Closed (physical contact) |
| powerSupplyStatus | ACPowerSupply | Mains powered |
| hostAntiDismantleStatus | close | Not tampered |
| cardReaderOnlineStatus | [1] | Reader 1 online |
| alarmInStatus | [] | No alarms active |
| alarmOutStatus | [] | No alarm outputs triggered |
| Clock offset | +72 seconds | WARNING — manual time mode, no NTP |

---

## Integration Points

| System | Method | Status |
|---|---|---|
| Application server (10.10.8.124:8000) | ISAPI polling via `HikvisionIsapiService` | Active |
| AlertStream container | `pkp_securegate_alertstream_door_b` | Running |
| Prometheus | `/metrics/json` on app server | Active |
| GitLab CI | Not connected to device | N/A |

---

## Model Variant Notes

`DS-K1T804AMF` suffix breakdown:
- `DS-K1T804` — base model (4-button terminal, access control grade)
- `A` — additional I/O options
- `M` — Mifare card reader
- `F` — Fingerprint module

This variant supports face recognition capability in hardware, but face recognition is **not confirmed configured or in use** — no face-specific ISAPI endpoints were probed. Do not assume face recognition is active.

---

## Known Limitations

| Limitation | Impact | Mitigation |
|---|---|---|
| No NTP configured | Clock drifts; event timestamps may be inaccurate by minutes | Apply NTP per `NTP_TIME_SYNC.md` SOP |
| doorStatus=4 (UNKNOWN) | Cannot confirm door open/closed via status code | Use magneticStatus (0=closed) as fallback |
| FingerPrintDownload API partial | Cannot enumerate per-user fingerprint enrollment | Use CardReaderCfg/1.fingerPrintNum as device total |
| Anti-passback not supported | Single reader; no APB enforcement | Document in `ANTI_PASSBACK.md`; not a gap for this deployment |
| RS-485 port empty | No external reader | If added, configure per `ISAPI_INTEGRATION.md` |

---

## Change Log

| Date | Change | Author |
|---|---|---|
| 2026-09-18 | Initial profile created from read-only ISAPI probes | Agent |
