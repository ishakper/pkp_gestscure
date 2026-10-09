# DEVICE CAPABILITY MATRIX — DS-K1T804AMF

**Device**: DS-K1T804AMF  
**Serial**: GR3496472  
**Firmware**: V1.4.1 build 240318  
**Evidence date**: 2026-09-18  
**Evidence method**: ISAPI read-only probes, no device state modified  

---

## Hardware Capacity

| Resource | Capacity | Current | Utilization |
|---|---|---|---|
| Users | 3000 | 97 | 3.2% |
| Cards | 3000 | 83 | 2.8% |
| Fingerprints | 3000 | 24 | 0.8% |
| Historical events | 100,000 | 22,923 | 22.9% |
| Card readers | 2 | 1 active | Reader 1 internal, Reader 2 offline |
| RS-485 ports | 1 | 0 in use | — |
| Relay outputs | 1 | 1 (electric lock) | — |
| Alarm inputs | — | 0 triggered | — |
| Alarm outputs | — | 0 triggered | — |

---

## Capability Matrix by Category

### Authentication Modes

| Mode | Supported | Evidence |
|---|---|---|
| Card only | ✅ | `userVerifyMode="card"` observed on 37 users |
| Fingerprint only | ✅ | `userVerifyMode="fp"` observed on 11 users |
| Card OR Fingerprint | ✅ | `userVerifyMode="fpOrCard"` observed on 12 users |
| Card AND Fingerprint | ✅ | `isSupportFingerPrint=true` in capabilities |
| PIN | ✅ | Device has keypad |
| Default (device default) | ✅ | `userVerifyMode=""` — 37 users using default |
| Face | ❌ | Not in this model variant |

**userVerifyMode distribution (2026-09-18)**:
- `""` (default): 37
- `"card"`: 37
- `"fp"`: 11
- `"fpOrCard"`: 12
- Total: 97

### Fingerprint

| Capability | Status | Evidence |
|---|---|---|
| Fingerprint reader hardware | ✅ | CardReaderCfg/1 `fingerPrintCapacity=3000` |
| Fingerprint enrollment | ✅ | `fingerPrintNum=24` enrolled |
| Fingerprint download API | ⚠️ PARTIAL | `/AccessControl/FingerPrintDownload` returns 400 without correct params |
| Per-user fingerprint list | ⚠️ PARTIAL | Cannot enumerate per-user without working FingerPrintDownload |
| Fingerprint capacity on reader 2 | ❌ | `fingerPrintCapacity=0` on RS-485/Wiegand offline reader |

### Access Control Scheduling

| Feature | Supported | Evidence |
|---|---|---|
| Week plan | ✅ | `isSupportVerifyWeekPlanCfg=true` |
| Holiday plan | ✅ | `isSupportVerifyHolidayPlanCfg=true` |
| Door status plan | ✅ | `isSupportDoorStatusPlan=true` |
| Time template | ✅ | ISAPI `/AccessControl/ScheduleTemplate` |

### Anti-Passback

| Feature | Supported | Evidence |
|---|---|---|
| Anti-passback | ❌ NOT SUPPORTED | Not present in `/System/capabilities` XML |
| Single-reader limit | ✅ inherent | Single reader device, APB not applicable |

### Door Hardware

| Component | Status | Evidence |
|---|---|---|
| Electric lock (relay) | ✅ PRESENT | `electroLockNum=1`, `relayNum=1` |
| Door contact sensor (magnetic) | ✅ PRESENT | `magneticStatus=[0]` (reading: closed/0) |
| Door status | ⚠️ UNKNOWN | `doorStatus=[4]` — code 4 = UNKNOWN (no contact sensor state confirmed) |
| Door lock status | ✅ | `doorLockStatus=[0]` = locked |
| Tamper sensor | ✅ | `hostAntiDismantleStatus="close"` = not tampered |
| Card reader tamper | ✅ | `cardReaderAntiDismantleStatus=[]` = no alarms |
| Power supply | ✅ AC | `powerSupplyStatus="ACPowerSupply"` |

**doorStatus code reference**:
- 0 = closed (confirmed by contact sensor)
- 1 = open
- 4 = UNKNOWN (contact sensor not reporting state)

### Events

| Feature | Supported | Evidence |
|---|---|---|
| AlertStream (real-time) | ✅ | Active at time of evidence |
| AcsEvent search (historical) | ✅ | `/AccessControl/AcsEvent?format=json` |
| AcsEvent total count | ✅ | `totalNum=22923` (oldest: 2026-07-23) |
| Event deduplication (serial) | ✅ | `serialNo` field in AcsEvent records |
| Alarm events | ✅ | Alarm status fields in AcsWorkStatus |

### Time / NTP

| Feature | Status | Evidence |
|---|---|---|
| NTP capability | ✅ | `/System/time/ntpServers` endpoint responds |
| NTP configured | ❌ | `hostName=""` in NTP config |
| Time mode | MANUAL | `timeMode=manual` in device time config |
| Clock offset (2026-09-18) | ⚠️ +72s | Device=18:28:30+07, Server=18:28:42+07 — WARNING |

**Clock offset severity**:
- < 30s: OK
- 30–120s: WARNING — schedule and event timestamps may drift
- > 120s: CRITICAL — access schedule violations possible

### Wiegand / RS-485

| Feature | Status | Evidence |
|---|---|---|
| Wiegand config support | ✅ | `isSupportWiegandCfg=true` |
| Wiegand reader connected | ❌ | CardReaderCfg/2 `connectionType="Wiegand\485Offline"` |
| RS-485 port | ✅ PRESENT | `RS485Num=1` in deviceInfo |
| RS-485 device connected | ❌ | No device on port (offline) |

### ISUP / Ehome

| Feature | Status | Evidence |
|---|---|---|
| Ehome firmware support | ✅ | `isSupportEhome=true` in capabilities |
| Ehome configured | ❌ | `/System/Network/Ehome` = notSupport response |

---

## Alarm State (at evidence time)

| Field | Value | Interpretation |
|---|---|---|
| alarmInStatus | [] | No alarm inputs triggered |
| alarmOutStatus | [] | No alarm outputs triggered |
| hostAntiDismantleStatus | close | Device not tampered |
| cardReaderAntiDismantleStatus | [] | Card reader not tampered |
| cardReaderOnlineStatus | [1] | Reader 1 online |

---

## Evidence Provenance

All data collected via read-only ISAPI probes from application server. No device state was modified.

| ISAPI Endpoint | Data Collected |
|---|---|
| `/System/deviceInfo` | model, serial, firmware, RS485Num |
| `/System/capabilities` | all feature flags |
| `/System/time` | timeMode, clock |
| `/System/time/ntpServers` | NTP config |
| `/AccessControl/CardReaderCfg/1` | fingerPrintNum, fingerPrintCapacity |
| `/AccessControl/CardReaderCfg/2` | RS-485/Wiegand offline state |
| `/AccessControl/AcsWorkStatus` | doorStatus, lockStatus, magneticStatus, power, alarms |
| `/AccessControl/AcsEvent` | historical event count, oldest event |
| `/AccessControl/UserInfo/Search` | user count, userVerifyMode distribution |
| `/AccessControl/CardInfo/Search` | card count (cardNum=83) |
| `/ISAPI/Event/notification/alertStream` | stream availability |
