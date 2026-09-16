# Phase 17 Office Verification & Blocker Remediation Report

Date: 2026-09-16  
Branch: `ishak/phase17-office-verification-2026-09`  
Base Commit: `1ae797ce2da5d3a7757bfae36360b1c5003d96b1`  
Production Target: `10.10.8.124` (`pkp_securegate_app`)  
Physical Target: `DOOR-B` (Hikvision DS-K1T804AMF @ `192.168.90.15`)  
Local Verification Verdict: **READY_WITH_CONDITIONS**  
Merge/Deployment Status: **HOLD (Requires External Physical & Reverse-Proxy Verification)**

---

## 1. Executive Summary

All locally actionable blockers identified during Phase 17 pre-office review and production live monitoring have been resolved and verified across comprehensive automated test suites (387 tests, 1801 assertions) and live browser runtime inspections.

Physical device safety has been strictly maintained:
- Zero physical writes executed on Hikvision hardware.
- Production environment untouched (read-only inventory inspection only).
- Untracked production directory `access-door-inventory/` preserved intact.

---

## 2. Remediated Local Blockers

### A. Verification Method Normalization & Elimination of False Fingerprint Inference
- **Root Cause**: The webhook handler previously assumed events with missing card numbers were fingerprints (`$cardNo ? 'Card' : 'Fingerprint'`). Vendor `verifyMethod` values were ignored.
- **Remediation**: Implemented `HikvisionPayloadParser::normalizeVerificationMethod()` mapping raw vendor tokens to canonical types (`FINGERPRINT`, `CARD`, `FACE`, `PIN`, `PASSWORD`, `MULTI_FACTOR`, `UNKNOWN`).
- **Behavior**: Missing, null, or undocumented verification methods now strictly map to `UNKNOWN`. No inference is made from missing card numbers.

### B. Raw Device Payload Propagation Removed
- **Remediation**: Stripped internal `raw_payload` from `HikvisionPayloadParser`.
- **Sanitization**: Removed successful raw vendor response payloads from `HikvisionIsapiService::fetchEvents()`. Event transport uses structured, typed DTOs only.

### C. Card & Biometric Privacy Hardening
- **Employee & Access Log Resources**: Removed raw `card_no` and masked variations from `EmployeeResource` and `AccessLogResource`. Replaced with `card_registered` boolean status (`YES` / `UNKNOWN`).
- **Biometric Model Security**: Removed `biometric_template` from `$fillable` in `BiometricStatus` and added to `$hidden` to prevent mass-assignment and serialization.
- **Frontend Dashboard**: Removed table display of employee card numbers. Edit employee form keeps card input blank with placeholder indicating registration status; empty submissions do not overwrite existing card credentials.

### D. Queue Health Runtime Evidence
- **Root Cause**: Previously, configuring `database` queue driver caused `SystemHealth` to report `HEALTHY` even without an active worker.
- **Remediation**: Queue status reports `HEALTHY` only for `sync` driver. For `database` driver, it reports `UNKNOWN` unless degraded by failed jobs (> 0) or stale pending jobs (> 300s).

### E. Realtime Stream & Polling Stabilization
- **SSE Buffer Flush**: Centralized flush mechanism in `LiveAccessStreamController` checking `ob_get_level()` before flushing to prevent PHP buffer warnings.
- **Client Transport Singleton**: Consolidated dashboard realtime handlers into a singleton manager. Bounded exponential backoff (2s up to 30s) prevents request storms. Switches to 60s fallback polling after 4 failures.
- **Visibility Lifecycle**: Realtime stream and polling automatically pause when document is hidden and resume on visibility.

---

## 3. SQLite Concurrency & Operational Review

- **Database Engine**: Production runs SQLite with WAL mode (`journal_mode = WAL`, `busy_timeout = 5000`).
- **Concurrency Constraints**: SQLite supports multiple readers but single-writer serialization. High-frequency webhook ingestion combined with concurrent web sessions can trigger `database is locked` under burst load.
- **Mitigation Strategy (No Destructive Migration)**:
  1. Transaction lifetimes kept minimal (< 50ms) across webhook controllers.
  2. Queued jobs process async attendance derivation without holding web request locks.
  3. Recommendation for production scale: maintain SQLite WAL mode with periodic checkpointing; do not alter schema without planned maintenance window.

---

## 4. Verification Evidence & Quality Gates

| Gate | Target | Result | Evidence |
|---|---|---|---|
| **PHP Syntax** | All modified PHP files | PASS | `php -l` clean on all 10 modified PHP files |
| **JS Syntax** | `public/js/dashboard.js` | PASS | `node --check` 0 errors |
| **Git Diff Check** | Working tree | PASS | No conflict markers or whitespace errors |
| **Focused Tests** | Webhook, Service, Attendance, UI, Health | PASS | 82 passed, 416 assertions |
| **Full Test Suite** | Entire repository | PASS | **387 passed, 1801 assertions** (53s) |
| **Browser DOM (Super Admin)** | Port 18090 | PASS | 0 card number leaks, 0 sensitive tokens, all tables loaded |
| **Browser DOM (Building Admin)** | Scope & RBAC | PASS | Strict canonical building scoping verified |

---

## 5. Conditions for Final Merge Review

To transition from `READY_WITH_CONDITIONS` to `READY_FOR_MERGE_REVIEW`:
1. **Physical Fingerprint Event**: Perform controlled real-fingerprint tap on physical Hikvision DS-K1T804AMF (`192.168.90.15`) and verify `verify_method = Fingerprint` persists in `access_logs`.
2. **Reverse-Proxy SSE Buffering**: Verify Nginx reverse proxy on production server (`10.10.8.124`) has `proxy_buffering off;` and `proxy_read_timeout 600s;` enabled for `/live-stream`.
3. **Queue Worker Verification**: Confirm `php artisan queue:work` daemon is active on production host before expecting queue health activation.
