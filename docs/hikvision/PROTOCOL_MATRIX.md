# PROTOCOL MATRIX — DS-K1T804AMF @ DOOR-B

**Device**: DS-K1T804AMF  
**Serial**: GR3496472  
**Firmware**: V1.4.1 build 240318  
**IP**: 192.168.90.15:80  
**Evidence date**: 2026-09-18  

---

## Classification Legend

| Status | Meaning |
|---|---|
| SUPPORTED | Firmware capability confirmed via `/System/capabilities` or ISAPI probe |
| INSTALLED | Physical hardware present (sensor, port, reader) |
| CONFIGURED | Admin has set up the protocol (credentials, server address, etc.) |
| CURRENTLY_USED | Actively in use at time of evidence collection |
| NOT_CONFIGURED | Capability exists but no setup done |
| OFFLINE | Hardware port exists, no device connected |
| NOT_SUPPORTED | Probe returned `notSupport` or absent from capability XML |

---

## Protocol Classification

### ISAPI (HTTP/JSON over LAN)

| Dimension | Status | Evidence |
|---|---|---|
| SUPPORTED | ✅ YES | `/System/capabilities` returned full capability XML |
| INSTALLED | ✅ YES | Device reachable at 192.168.90.15:80 |
| CONFIGURED | ✅ YES | HTTP Digest auth credentials set in `doors.isapi_username/password` |
| CURRENTLY_USED | ✅ YES | Application queries ISAPI for user/card/event management |
| RECOMMENDED | ✅ YES | Primary integration protocol |

**Notes**:
- All user, card, event, and configuration operations use ISAPI
- Authentication: HTTP Digest (RFC 7616)
- Content negotiation: device responds JSON when `?format=json` param included, otherwise XML
- Response body includes `<?xml version="1.0" encoding="UTF-8"?>` header even for JSON responses — strip before `json_decode()`

---

### AlertStream (ISAPI outbound push)

| Dimension | Status | Evidence |
|---|---|---|
| SUPPORTED | ✅ YES | `/ISAPI/Event/notification/alertStream` responds |
| INSTALLED | ✅ YES | Container `pkp_securegate_alertstream_door_b` running |
| CONFIGURED | ✅ YES | Application subscribes via `door:stream-events DOOR-B` command |
| CURRENTLY_USED | ✅ YES | LISTENER_COUNT=1, event ingestion active (events_processed=1082 at evidence time) |
| RECOMMENDED | ✅ YES | Real-time event delivery; use with freshness gate |

**Notes**:
- Transport: HTTP chunked, `multipart/mixed` body, device pushes events
- Reconnect: application must reconnect on stream drop; no device-side retry
- Freshness gate: events older than `ALERTSTREAM_FRESHNESS_THRESHOLD` (default 30s) must be discarded — device pushes buffered events on reconnect
- Historical events NOT delivered via AlertStream; use AcsEvent search for backfill

---

### ISUP / Ehome (outbound to server)

| Dimension | Status | Evidence |
|---|---|---|
| SUPPORTED | ✅ YES | `isSupportEhome=true` in `/System/capabilities` |
| INSTALLED | ✅ YES | Firmware has Ehome module |
| CONFIGURED | ❌ NO | `/System/Network/Ehome` returned `notSupport` response |
| CURRENTLY_USED | ❌ NO | Not configured |
| RECOMMENDED | ❌ NO | Use ISAPI + AlertStream instead |

**Notes**:
- ISUP requires device to initiate connection to external server
- Not needed given LAN-accessible ISAPI setup
- Do NOT configure ISUP — it would create redundant outbound connection and complicate firewall rules

---

### RS-485 (serial reader bus)

| Dimension | Status | Evidence |
|---|---|---|
| SUPPORTED | ✅ YES | `RS485Num=1` in `/System/deviceInfo` |
| INSTALLED | ✅ YES | Physical RS-485 port on device |
| CONFIGURED | ❌ NO | CardReaderCfg/2 shows `connectionType="Wiegand\485Offline"` |
| CURRENTLY_USED | ❌ NO | No device connected |
| RECOMMENDED | N/A | Hardware available if external reader needed |

**Evidence from CardReaderCfg/2**:
```
connectionType: "Wiegand\485Offline"
fingerPrintCapacity: 0
fingerPrintNum: 0
```

**Notes**:
- RS-485 port exists on the device chassis but nothing is plugged in
- If a downstream reader (e.g. exit button, second reader) is added, configure via `/ISAPI/AccessControl/CardReaderCfg/2`
- `fingerPrintCapacity=0` on reader 2 confirms no fingerprint capability on external bus

---

### Wiegand (26/34-bit card reader protocol)

| Dimension | Status | Evidence |
|---|---|---|
| SUPPORTED | ✅ YES | `isSupportWiegandCfg=true` in capabilities |
| INSTALLED | ✅ YES | Physical Wiegand header on device |
| CONFIGURED | ❌ NO | CardReaderCfg/2 shows offline state |
| CURRENTLY_USED | ❌ NO | No Wiegand reader connected |
| RECOMMENDED | ❌ NO | Built-in ISAPI reader preferred |

**Notes**:
- Wiegand output could connect to external panel if needed
- Not required in current deployment

---

### TCP/IP Networking

| Dimension | Status | Evidence |
|---|---|---|
| SUPPORTED | ✅ YES | Ethernet port on device |
| INSTALLED | ✅ YES | Connected to LAN at 192.168.90.15 |
| CONFIGURED | ✅ YES | Static IP assigned |
| CURRENTLY_USED | ✅ YES | All ISAPI and AlertStream traffic over TCP |
| RECOMMENDED | ✅ YES | Primary transport for all protocols |

---

## Summary Matrix

| Protocol | Supported | Installed | Configured | Used | Recommended |
|---|---|---|---|---|---|
| ISAPI | ✅ | ✅ | ✅ | ✅ | ✅ |
| AlertStream | ✅ | ✅ | ✅ | ✅ | ✅ |
| ISUP/Ehome | ✅ | ✅ | ❌ | ❌ | ❌ |
| RS-485 | ✅ | ✅ | ❌ | ❌ | N/A |
| Wiegand | ✅ | ✅ | ❌ | ❌ | ❌ |
| TCP/IP | ✅ | ✅ | ✅ | ✅ | ✅ |

---

## Change Control

Any change to protocol configuration (enabling ISUP, connecting RS-485 device, configuring Wiegand output) requires:
1. AUTHORIZATION from infrastructure lead
2. Pre-change backup of device configuration via `BACKUP_RESTORE.md`
3. Post-change validation via `DEVICE_HEALTH.md` health check
4. Entry in this document under a dated evidence update
