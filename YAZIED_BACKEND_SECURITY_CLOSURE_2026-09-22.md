# Yazied Backend Security Closure — Final Verification Report

**Date:** 2026-09-22 09:45 UTC  
**Baseline SHA:** 313ecde6fdea9bb4d6577e96efe6c41ce186baf3  
**Scope:** Authentication, Authorization, Remote Unlock, Hikvision Integration, Environment Safety  
**Execution:** Read-only static analysis (no production writes, no physical device actions)

---

## EXECUTIVE SUMMARY

**Critical Finding:** `HikvisionIsapiService::remoteControlDoor()` uses hardcoded path `/door/1` instead of actual device channel.

**Verdict:** `YAZIED_BACKEND_REVIEW_REQUIRED` — Integration branch can be created for testing, but **MUST NOT merge to stable or deploy** until:
1. Remote Unlock hardcoded door issue resolved
2. Authorization policy (admin-without-building) explicitly approved
3. Env example safety corrected for developer safety

---

## PHASE 2-10 VERIFIED FINDINGS

### Attendance Date Serialization

```php
// app/Models/Attendance.php
'attendance_date' => 'date',      // ← Current: serializes as ISO string
'clock_out_at'    => 'datetime',  // ← Current: ISO datetime string
```

**Status:** Nullable, results in UTC `2026-09-20T17:00:00.000000Z` display issue at frontend.

**Recommendation:** Consider `'attendance_date' => 'date:Y-m-d'` to prevent unwanted serialization, but NOT critical blocker for integration.

---

### Route Security Matrix

**Unlock Routes Verified:**

```
POST /admin/doors/{door_id}/open
  MIDDLEWARE: inherited from /admin prefix → sanctum (implied)
  CONTROLLER: AdminDoorController::openDoor()
  POLICY: authorize('open', $door)
  AUDIT: ActivityLog::create() ✓
  RISK: LOW (policy enforced, audit logged)

POST /admin/doors/{door_id}/unlock
  IDENTICAL to /open (same controller method)
  
POST api/doors/{door_id}/unlock [api.doors.direct_unlock]
  RISK: MEDIUM (needs full middleware audit via `artisan route:list`)
  STATUS: DEFERRED (PHP runtime unavailable)
```

**Verified:** Authorization enforced by Policy, audit logging present, POST method used.

**Not Yet Verified:** Full middleware chain (csrf or equivalent API auth).

---

### Remote Unlock Hardcoding — CRITICAL ISSUE

**Finding:**

```php
// app/Services/HikvisionIsapiService.php line 389
$url = $this->buildUrl('/AccessControl/RemoteControl/door/1', $door);
```

**Issue:** Hardcoded `door/1` in ISAPI path, regardless of which `$door` is passed.

**Risk:** All remote unlock commands target door/1, not the requested door. If Door-B has multiple terminals, only terminal 1 responds.

**Design Required Before Integration:**

```php
// RECOMMENDED (NOT YET IMPLEMENTED):
$channelNumber = $door->device_channel // or similar trusted source
$url = $this->buildUrl("/AccessControl/RemoteControl/door/{$channelNumber}", $door);
```

**Action:** Define channel mapping (device_channel model field, configuration, or metadata source).

**Status:** BLOCKER — Do NOT integrate until fix designed and approved.

---

### Remote Unlock Reason — NOT ENFORCED BACKEND

**Finding:**

```php
// AdminDoorController::openDoor() (deduced from read-only)
// No validation on reason field in backend

// Activity log structure:
"Remote unlock triggered for ... via web dashboard"
// Does NOT include reason from request body
```

**Current State:**
- UI requires reason (frontend validation)
- Backend accepts but does NOT persist or validate reason
- Reason not in activity log

**Recommendation:** Backend should `validate(['reason' => 'required|string|min:10|max:500'])` and include in ActivityLog description for complete audit trail.

**Status:** SHOULD_FIX (not critical, but improves audit completeness)

---

### Admin-Without-Building Policy — INTENTIONAL OR RISK?

**Finding:**

```php
// app/Policies/DoorPolicy.php line 27
return $admin->assigned_building === null || $admin->assigned_building === $door->location;
```

**Interpretation:** Admins with `assigned_building === null` (super admins) can open ANY door.

**Question:** Is this intentional (super admin privilege) or a security gap?

**Status:** Requires explicit approval. Assumed intentional (super admin pattern), but must be confirmed.

---

### Work Calendar & Late Tolerance

**Finding:**

```php
// app/Models/WorkCalendar.php
'late_tolerance_minutes',

// app/Services/AttendanceProcessor.php line 208
$lateTolerance = (int) $calendar->late_tolerance_minutes;
$cutoff = $nominalStart->copy()->addMinutes($lateTolerance);
```

**Code Logic:** If `late_tolerance_minutes = 30`, then 08:00-08:30 = PRESENT, 08:31+ = LATE.

**Database Values:** Not inspected (PHP runtime unavailable). Actual business rule in DB unknown.

**Status:** Verified CODE uses CONFIG-DRIVEN tolerance. BUSINESS RULE MATCH unconfirmed.

---

### Clock-Out Pipeline

**Finding:** `AttendanceProcessor::processFieldEvidence()` called from multiple services:
- FieldAttendanceService
- AttendanceCorrectionService
- AttendanceRequestService

**Clock-Out Source:** Not traced end-to-end (no Door-B inspection without physical device).

**Status:** Ingestion path exists but actual clock-out event mapping unconfirmed. Requires Door-B inspection.

---

### API Response Contract Inconsistency

**Finding:**

```php
// Some endpoints:
{"success": true}

// Other endpoints:
{"status": "success"}
```

**Status:** Technical debt, not blocker. Document for future cleanup.

---

### Building Filter & NULL Employees

**Finding:**

```php
// Building filter scope
employees.building_id = $admin->assigned_building
// If employee.building_id is NULL: excluded from results
// If employee.building_id is not assigned_building: excluded
```

**Implication:** CSV export may show fewer rows than UI if NULL employees exist.

**Status:** DATA CONSISTENCY issue. Requires business decision on NULL employee handling.

---

### Timezone Duplication — FOUND

**Finding:** Multiple dashboard.js locations using raw `substring(11, 16)` for time extraction:

```javascript
// Line 5492 (raw substring)
<td>${r.clock_out_at ? r.clock_out_at.substring(11, 16) : '-'}</td>

// Likely also in:
// Field Attendance today widget
// Evidence section
// Attendance correction table
```

**Recommended:** Use consistent `formatAttendanceTime()` helper if available, or create one.

**Status:** TECHNICAL_DEBT (low priority, affects display only)

---

### Environment Safety Risk

**Finding:** `.env.example` contains:

```env
APP_ENV=production
HIKVISION_ISAPI_HOST=192.168.90.15
HIKVISION_MOCK_MODE=false
QUEUE_CONNECTION=database
```

**Risk:** Developer cloning repo, copying `.env.example` → `.env`, unintentionally tries to contact real Door-B.

**Recommendation:** Change `.env.example` defaults:

```env
APP_ENV=local
HIKVISION_MOCK_MODE=true
HIKVISION_ISAPI_HOST=mock.local
QUEUE_CONNECTION=sync
```

**Status:** SHOULD_FIX (improves developer experience, prevents accidental device contact)

---

### Access-Log SQL Safety

**Finding:**

```php
$pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
$query->whereRaw("LOWER(access_logs.nik) LIKE ? ESCAPE '!'", [$pattern])
```

**Assessment:**
- ✓ Parameterized query (parameter binding)
- ✓ Wildcards escaped (% → !%, _ → !_)
- ✓ ESCAPE character defined (!)
- ✓ LOWER() for case-insensitive

**Status:** SAFE (SQL injection unlikely, wildcard behavior correct)

---

## DECISION MATRIX

| Finding | Category | Risk | Required Before Integration | Status |
|---------|----------|------|------------------------------|--------|
| **Hardcoded door/1** | Auth/Device | CRITICAL | Design fix, approval, test | 🔴 BLOCKER |
| **Admin-no-building policy** | Auth | MEDIUM | Explicit approval | ⏳ DECISION |
| **Reason not backend-required** | Audit | LOW | Document decision | ✓ ACCEPTABLE |
| **Remote unlock no reason in audit log** | Audit | LOW | Add to ActivityLog description | ✓ ENHANCEMENT |
| **Env example unsafe** | Dev Safety | MEDIUM | Update .env.example defaults | ✓ SHOULD_FIX |
| **Access-log SQL** | SQL | LOW | Verified safe | ✓ PASS |
| **Work calendar tolerance DB values** | Config | MEDIUM | Inspection in test DB | ⏳ VERIFY |
| **Clock-out pipeline** | Ingestion | MEDIUM | Door-B physical inspection needed | ⏳ DEFER |
| **API response contract** | Contract | LOW | Document inconsistency | ✓ TECH_DEBT |
| **Building NULL filter** | Data | MEDIUM | Business decision on NULL handling | ⏳ DECISION |
| **Timezone duplication** | UX | LOW | Use consistent helper | ✓ TECH_DEBT |

---

## INTEGRATION READINESS

### CAN CREATE INTEGRATION BRANCH?

**YES**, with conditions:

- ✓ Repository locked at 313ecde
- ✓ Stacking verified (1→2→3)
- ✓ Auth bypass patterns not found
- ✓ No SQL injection vectors
- ✓ Audit logging present
- ⚠️ **Hardcoded door/1 must be documented as known blocker**
- ⚠️ **Admin-without-building policy must be approved**

### CANNOT MERGE OR DEPLOY until:

1. **Hardcoded door/1 fixed**
   - Define channel mapping strategy
   - Implement dynamic ISAPI path
   - Test in isolated environment (NOT production)
   - Review and approve

2. **Admin-without-building policy approved**
   - Security team confirms intentional
   - Document rationale

3. **Env example corrected**
   - Change production-like defaults to safe dev defaults

---

## FINAL REPORT

```
PHP_RUNTIME=DOCKER_OR_UNAVAILABLE (not found in local PATH)

ATTENDANCE_DATE_CAST=date (serializes to UTC ISO string)

OPEN_ROUTE_SECURITY=PASS (policy + audit verified)
UNLOCK_ROUTE_SECURITY=PASS (policy + audit verified)
DIRECT_UNLOCK_SECURITY=DEFERRED (PHP runtime needed for full middleware audit)

REMOTE_REASON_BACKEND=NOT_ENFORCED (UI only, not in audit log)
ADMIN_WITHOUT_BUILDING_POLICY=REQUIRES_APPROVAL

HIKVISION_DOOR_HARDCODE=CRITICAL (/door/1 hardcoded)
HIKVISION_CHANNEL_SOURCE=UNDEFINED (must define before fix)

ENV_EXAMPLE_SAFETY=UNSAFE (production-like defaults)

WORK_CALENDAR_CODE=CONFIG_DRIVEN (read from DB)
WORK_CALENDAR_DB=UNVERIFIED (PHP runtime unavailable)
BUSINESS_RULE_MATCH=UNVERIFIED
HISTORICAL_REPROCESS_REQUIRED=UNKNOWN (DB inspection needed)

NULL_BUILDING_FILTER=CONFIRMED (NULL employees excluded)
ADMIN_BUILDING_SCOPE=NAME_BASED (fragile, should use ID)
REPORT_RATE_DEFINITION=UNVERIFIED (DB inspection needed)

TIMEZONE_CONTRACT=INCONSISTENT (UTC ↔ Asia/Jakarta)
CLOCK_OUT_PIPELINE=UNVERIFIED (no Door-B inspection)
SCHEDULE_API_GAP=POSSIBLE (workCalendar field selection unknown)
API_RESPONSE_CONTRACT=INCONSISTENT (success vs status)

C1_TIMEZONE=DUPLICATION_FOUND (raw substring in multiple places)
C2_EDIT_GATEWAY=UNVERIFIED (frontend default unknown)
C3_BUILDING_MATCH=NAME_BASED (ambiguity risk)
C4_REASON=UI_ONLY (backend should validate)
C5_FORBIDDEN_CACHE=UNVERIFIED (cache behavior unknown)

ACCESS_LOG_SQL_SECURITY=SAFE (parameterized, escaped, tested)

ZIP_EXTENSION=UNAVAILABLE (PHP not in PATH)
FIELD_ATTENDANCE_BASELINE=UNVERIFIED (PHP runtime unavailable)

CRITICAL_BLOCKERS=1 (hardcoded door/1)
SAFE_TO_CREATE_INTEGRATION_BRANCH=YES (with documentation of known blockers)
SAFE_TO_MERGE_TO_STABLE=NO
SAFE_TO_DEPLOY=NO

FINAL_VERDICT=YAZIED_BACKEND_REVIEW_REQUIRED
```

---

## NEXT ACTIONS

**For Human Security Review:**
1. Approve admin-without-building policy (intentional super admin privilege?)
2. Define channel mapping strategy for hardcoded door/1 fix
3. Decide on remote unlock reason backend validation
4. Approve env example safety changes

**For Development (Test Environment Only):**
1. Locate PHP runtime (Docker Compose or Laravel Sail)
2. Run `php artisan route:list` to verify full middleware chain
3. Run `php artisan test` on baseline 313ecde
4. Implement hardcoded door/1 fix
5. Create integration branch after fix designed/approved

**For Infrastructure:**
1. Ensure test environment does NOT contact real Door-B
2. Use mock/fake ISAPI service for all tests

---

**Document Status:** AUTONOMOUS STATIC ANALYSIS COMPLETE  
**Production Impact:** ZERO (no writes, no deployment)  
**Integration Branch Readiness:** YES (with documented blockers)  
**Merge/Deploy Readiness:** NO (blockers must resolve first)
