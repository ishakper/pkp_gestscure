# SOP: FINGERPRINT PRIVACY

**PURPOSE**: Define boundaries for fingerprint data handling — what the application stores, what it does not, and why.  
**SCOPE**: All fingerprint-related ISAPI interactions.  
**AUTHORIZATION REQUIRED**: Any fingerprint enrollment or deletion on device requires explicit employee consent and supervisor approval.  

---

## Privacy Principle

**The application stores NO raw fingerprint templates.**

Fingerprint biometric templates remain exclusively on the Hikvision device. The application records only metadata (enrollment status, count, auth mode) — never the template itself.

This is both a privacy requirement and a technical constraint:
- `FingerPrintDownload` API requires specific parameters and is partially supported on this firmware
- Even where the API works, downloading templates creates data protection liability
- Templates stored in a DB could be compromised; templates on device are harder to exfiltrate remotely

---

## What the Application Stores

| Data | Stored | Type |
|---|---|---|
| Employee fingerprint enrollment count | ✅ Yes | Integer (how many fingers enrolled) |
| Employee `userVerifyMode` | ✅ Yes | String (`fp`, `fpOrCard`, `card`, `""`) |
| Fingerprint enrollment timestamp | ✅ Yes (if available) | DateTime |
| Raw fingerprint template | ❌ Never | — |
| Fingerprint image | ❌ Never | — |
| Biometric feature vector | ❌ Never | — |

This is the **metadata-only model**.

---

## What the Device Stores

| Data | On Device | Notes |
|---|---|---|
| Fingerprint templates | ✅ Yes | In device flash memory |
| Per-user enrollment status | ✅ Yes | Accessible via `UserInfo/Search` → `fingerPrintNum` field |
| Total enrolled count | ✅ Yes | CardReaderCfg/1 `fingerPrintNum=24` |
| Per-user template download | ⚠️ Partial | FingerPrintDownload API returns 400 without correct params |

---

## Evidence Model (Read-Only Safe)

The application infers fingerprint status from:

| Source | Data Point | Confidence |
|---|---|---|
| CardReaderCfg/1.fingerPrintNum | Device-wide enrolled count (24) | HIGH — hardware count |
| UserInfo.userVerifyMode=`fp` | User uses fingerprint-only auth | MEDIUM — set by admin |
| UserInfo.userVerifyMode=`fpOrCard` | User uses FP or card | MEDIUM |
| UserInfo.fingerPrintNum | Per-user fingerprint count (if available) | HIGH when present |

**Current device snapshot (2026-09-18)**:
- FINGERPRINT_TOTAL_ENROLLED: 24
- MODE_FP_ONLY: 11 users
- MODE_FP_OR_CARD: 12 users
- MODE_CARD_ONLY: 37 users
- MODE_DEFAULT: 37 users

---

## Fingerprint Enrollment (Employee-Initiated)

Fingerprint enrollment MUST be done physically at the device terminal.

The application cannot push fingerprint templates remotely. Enrollment process:
1. Employee physically present at terminal
2. Admin initiates enrollment via device menu
3. Employee places finger on reader (multiple attempts per finger)
4. Device stores template locally
5. Admin updates `userVerifyMode` on device via ISAPI if needed
6. Application reads updated `fingerPrintNum` via next reconciliation

**No software-side enrollment command exists in the application.** This is intentional.

---

## Fingerprint Deletion (Employee-Initiated or HR-Directed)

Deletion must be done physically at the device:
1. Admin accesses device management menu or ISAPI `/AccessControl/ClearFPInfo`
2. Confirms deletion
3. Application reads updated `fingerPrintNum = 0` on next reconciliation
4. Application records `biometric_statuses` update

**The application does not issue fingerprint delete commands remotely.** Same principle: we do not control what we cannot audit.

---

## FingerPrintDownload API

Endpoint: `/ISAPI/AccessControl/FingerPrint/FingerPrintDownload`

Status: PARTIALLY SUPPORTED — returns 400 on basic call (requires specific params per firmware docs).

**Policy**: Do NOT implement FingerPrintDownload. Even if we can get it to return templates:
- No business requirement to read or store templates
- Legal risk under Indonesia UU PDP (Undang-Undang Perlindungan Data Pribadi)
- Templates stored outside device create new attack surface

---

## Regulatory Context

Fingerprint biometrics are **sensitive personal data** under Indonesian law (UU No. 27 Tahun 2022 tentang PDP).

Requirements:
- Explicit consent for collection
- Purpose limitation (access control only)
- Data minimization (metadata only — satisfied by this SOP)
- Retention limit (templates on device; application stores metadata only)
- No transfer without consent

---

## STOP CONDITIONS

Stop immediately and escalate if:
- Any code change attempts to call `FingerPrintDownload` and store the result
- Any database migration adds a column that would store template bytes or biometric feature data
- Any API endpoint exposes fingerprint data beyond enrollment count and auth mode

---

## AUDIT EVIDENCE

The application's fingerprint audit trail consists of:
- `biometric_statuses` table rows: one per employee, records `status`, `enrolled_fingers`, `last_sync_at`
- `activity_logs` entries for any mode change via ISAPI

No template data appears in any log.
