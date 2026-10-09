# SOP: INCIDENT RESPONSE — PHYSICAL ACCESS CONTROL

**PURPOSE**: Define severity levels, notification chains, and containment steps for access control incidents.  
**SCOPE**: DOOR-B, DS-K1T804AMF terminal, AlertStream security events.  
**AUTHORIZATION REQUIRED**: Incident commander role assigned based on severity.  

---

## Incident Severity Matrix

| Severity | Definition | Examples | Response Time | Incident Commander |
|---|---|---|---|---|
| SEV-1 | Critical security breach or complete failure | Device tampered, unauth physical breach, all doors locked open | Immediate (< 15 min) | Infrastructure Lead |
| SEV-2 | Degraded security or partial failure | Device offline > 1h, door forced open, clock drift > 2h | < 1 hour | On-call Engineer |
| SEV-3 | Minor anomaly or operational gap | Stream disconnected < 15m, single employee card fail, clock drift > 30s | < 4 hours | System Administrator |
| SEV-4 | Informational or low-risk drift | Auth mode drift detected, unrecognized card swiped once | Next business day | System Administrator |

---

## Notification Chain

```
DETECTION
    │
    ├── SEV-1: Call Infra Lead + Building Security + WhatsApp/SMS broadcast
    ├── SEV-2: Email Infra Lead + Slack/Teams alert + Security team notice
    ├── SEV-3: Ticket created + Slack/Teams notification
    └── SEV-4: Logged in daily digest / drift report
```

Emergency contacts:
- Infrastructure Lead: [infra-lead@company.local]
- Building Security Desk: [ext 100 / +62-xxx]
- On-call Engineer: [check PagerDuty / rota]

---

## Playbook: SEV-1 — Device Tamper Detected

1. **Trigger**: `hostAntiDismantleStatus="open"` or AlertStream `tamperAlarm`
2. **Immediate containment**:
   - Dispatch security guards to Door B (Building B entry)
   - Do NOT clear alarm via ISAPI (masks evidence)
   - Request CCTV footage export for Door B for past 30 minutes
3. **Investigation**:
   - Is terminal physically attached to wall?
   - Is front cover secured?
   - Any signs of forced entry, prying, water damage?
   - Check device access log: who was the last person to swipe before tamper?
4. **Resolution**:
   - If physical breach: escalate to executive management + facilities
   - If false alarm (cover loose, maintenance): secure cover, verify status returns to `"close"`
5. **Post-incident**:
   - Complete incident report within 24 hours
   - Review if anti-tamper threshold needs adjustment

---

## Playbook: SEV-1 — Door Locked Open Unexpectedly

1. **Trigger**: `doorLockStatus=1` (unlocked) outside approved schedule
2. **Immediate containment**:
   - Send guard to physically hold/guard the door
   - Emergency lock command via ISAPI:
     `PUT /ISAPI/AccessControl/RemoteControl/door/1` with `{"command": "close"}`
3. **If remote command fails**:
   - Disconnect power supply to door relay (fail-secure lock will lock on power cut)
   - ⚠️ Verify lock is fail-secure (locks on power loss) before cutting power
4. **Investigate root cause**:
   - Did someone issue remote unlock? Check `activity_logs`
   - Is door status plan active? Check `DoorStatusPlan`
   - Is fire alarm triggered? (Fire alarm relay can override access controller)

---

## Playbook: SEV-2 — Device Offline During Business Hours

1. **Trigger**: Device offline > 15 min between 07:00–19:00 Mon–Fri
2. Follow `DEVICE_OFFLINE.md` SOP
3. Assign physical guard to Door B if door is critical entry
4. Escalate to SEV-1 if offline duration exceeds 2 hours with no resolution

---

## Incident Report Template

```markdown
# INCIDENT REPORT — [INC-YYYYMMDD-XX]

**Date/Time**: YYYY-MM-DD HH:MM WIB  
**Severity**: SEV-[1/2/3/4]  
**Device**: DS-K1T804AMF @ DOOR-B  
**Incident Commander**: [Name]  

### Summary
[1-2 sentences describing what happened]

### Timeline
- HH:MM — Event detected by [alert/person]
- HH:MM — Incident commander notified
- HH:MM — Containment action taken: [details]
- HH:MM — Device inspected physically: [findings]
- HH:MM — Root cause identified: [details]
- HH:MM — Incident resolved: [resolution]

### Root Cause
[Technical explanation of why it happened]

### Impact
- Access denied to: [N] employees
- Security compromised: [YES/NO]
- Data lost: [YES/NO]

### Action Items
1. [Preventative action] — Owner: [Name] — Due: [Date]
```

---

## Retention

All incident reports must be stored in `docs/incidents/` and retained for minimum 2 years for audit compliance.
