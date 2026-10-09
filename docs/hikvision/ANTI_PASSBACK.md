# SOP: ANTI-PASSBACK

**PURPOSE**: Document that anti-passback is NOT supported by this device and why.  
**SCOPE**: DS-K1T804AMF, DOOR-B.  

---

## Status: NOT SUPPORTED

Anti-passback (APB) is **not supported by the DS-K1T804AMF firmware**.

**Evidence**:
- `/System/capabilities` XML does not contain `isSupportAntiPassback` element
- Device has only 1 card reader (Reader 1 internal; Reader 2 offline)
- APB requires at minimum 2 readers (entry + exit) to track direction of passage

**This is not a configuration gap — it is a hardware/firmware limitation.**

---

## What Anti-Passback Means

Anti-passback prevents an access card from being used to enter a zone twice in a row without an intervening exit event. It blocks "tailgating" scenarios where one person enters and passes their card back to another.

APB requires:
1. Entry reader (card swipe to enter)
2. Exit reader (card swipe to exit)
3. Controller tracking user direction

With one reader, the device cannot distinguish entry from exit events.

---

## Implication for Building B

- Tailgating is possible (person A enters, holds door for person B)
- Card passback is possible (A enters, passes card back to B who then scans)
- The application **cannot enforce APB** at the ISAPI or application layer for this device

---

## Mitigation Measures

Since APB is unavailable:

| Measure | Owner |
|---|---|
| Physical turnstile or door closer | Facilities team |
| CCTV at entry point | Security team |
| Alert on rapid repeated card swipes from same employee | Application (can implement via access_logs analysis) |
| Alert on simultaneous login from same employee at different locations | Application |

The application can implement a **soft APB alert**:
- If the same card is swiped at DOOR-B more than 2× within 60 seconds → generate alert
- This does not physically block access but flags for security review

---

## Future Devices

If a replacement device is selected that supports APB (e.g., DS-K2600 series or Hikvision controllers with multi-reader support):
1. Update this document with new evidence from capabilities probe
2. Configure APB zones via `/ISAPI/AccessControl/AcsAntiPassback`
3. Update reconciliation service to sync APB zone assignments

---

## Audit

This document serves as the formal record that APB absence is known, acknowledged, and mitigated by physical and application-layer measures rather than an undetected gap.

Last verified: 2026-09-18 via `/System/capabilities` read-only probe.
