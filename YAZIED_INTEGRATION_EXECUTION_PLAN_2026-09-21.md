# Yazied Integration Execution Plan — Phases 4-19 Summary

**Date:** 2026-09-21 10:15 UTC  
**Target SHA:** 313ecde6fdea9bb4d6577e96efe6c41ce186baf3  
**Integration Status:** READY FOR EXECUTION (on approval)

---

## PHASE 4 — DETAILED DIFF REVIEW (Complete)

### PR #1 — Perangkat Pintu Actions (20adca4)

**Diff Summary:**
```
 AdminAccessLogController.php  | 17 +-
 AdminDoorController.php       | 10 +-
 DoorPolicy.php                | 2 +-
 dashboard.blade.php           | 42 +--
 tests/AccessLogFilterTest     | 180 +++
 tests/FieldAttendanceTest     | 7 -
 tests/PerangkatPintuActions   | 191 +++
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 7 files changed, 419 insertions(+), 30 deletions(-)
```

**Files Modified:**
- app/Http/Controllers/Api/V1/AdminAccessLogController.php (17 +/-)
- app/Http/Controllers/Api/V1/AdminDoorController.php (10 +/-)
- app/Policies/DoorPolicy.php (2 +/-)
- resources/views/dashboard.blade.php (42 +/-)

**Files Added (Tests):**
- tests/Feature/AccessLogFilterTest.php (180 lines)
- tests/Feature/PerangkatPintuActionsTest.php (191 lines)

**Risk Assessment:** MEDIUM (device actions + authorization)

---

### PR #2 — Hak Akses Actions (eb52449)

**Diff Summary:**
```
 AdminAccessLogController.php    | 17 +-
 AdminDoorController.php         | 10 +-
 DoorPolicy.php                  | 2 +-
 dashboard.blade.php             | 55 +--
 tests/AccessLogFilterTest       | 180 +++
 tests/AccessProvisioningUi      | 120 +++
 tests/FieldAttendanceTest       | 7 -
 tests/PerangkatPintuActions     | 191 +++
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 8 files changed, 547 insertions(+), 35 deletions(-)
```

**Files Modified:**
- resources/views/dashboard.blade.php (55 +/-)

**Files Added (Tests):**
- tests/Feature/AccessProvisioningUiContractTest.php (120 lines)

**Risk Assessment:** LOW (UI/navigation only)

---

### PR #3 — Rekap Kehadiran Laporan (001b894)

**Diff Summary:**
```
 dashboard.blade.php                      | 5 +-
 tests/AttendanceReportBuildingFilterTest | 131 +++
 tests/AttendanceReportUiContractTest     | 93 +++
 tests/FieldAttendanceTest                | 7 -
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 4 files changed, 226 insertions(+), 10 deletions(-)
```

**Files Modified:**
- resources/views/dashboard.blade.php (5 +/-)

**Files Added (Tests):**
- tests/Feature/AttendanceReportBuildingFilterTest.php (131 lines)
- tests/Feature/AttendanceReportUiContractTest.php (93 lines)

**Risk Assessment:** MEDIUM (building filter + export logic)

---

## PHASE 5 — CHANGE CLASSIFICATION

### PR #1 — Perangkat Pintu Actions (20adca4)

| File | Purpose | Classification | Action |
|------|---------|-----------------|--------|
| AdminDoorController.php | Edit/Maint/Unlock actions | NEEDS_REVIEW | Verify authorization |
| DoorPolicy.php | Authorization gate | NEEDS_REVIEW | Inspect permission logic |
| dashboard.blade.php | Action buttons | SAFE_TO_APPLY | Port Blade changes |
| AccessLogFilterTest | Test coverage | SAFE_TO_APPLY | New tests, no conflicts |
| PerangkatPintuActionsTest | Feature tests | SAFE_TO_APPLY | New tests, no conflicts |

**Verdict:** APPLY_WITH_AUTHORIZATION_REVIEW

---

### PR #2 — Hak Akses Actions (eb52449)

| File | Purpose | Classification | Action |
|------|---------|-----------------|--------|
| dashboard.blade.php | Sub-tab default, pill styling | SAFE_TO_APPLY | Port Blade changes |
| AccessProvisioningUiContractTest | UI contract tests | SAFE_TO_APPLY | New tests, no conflicts |

**Verdict:** SAFE_TO_APPLY

---

### PR #3 — Rekap Kehadiran Laporan (001b894)

| File | Purpose | Classification | Action |
|------|---------|-----------------|--------|
| dashboard.blade.php | Building filter + export links | NEEDS_REVIEW | Verify filter consistency |
| AttendanceReportBuildingFilterTest | Building filter validation | NEEDS_REVIEW | Inspect query logic |
| AttendanceReportUiContractTest | Export data contract | NEEDS_REVIEW | Verify CSV/PDF match UI |

**Verdict:** APPLY_WITH_EXPORT_DATA_VERIFICATION

---

## PHASE 6-8 — SECURITY & COMPATIBILITY NOTES

### PR #1 Security Checklist
- ✓ Edit action: Requires authorization
- ✓ Maintenance: Requires authorization
- ⚠️ **Remote Unlock: CRITICAL REVIEW NEEDED**
  - Must verify user authentication
  - Must verify permission gate
  - Must verify server-side authorization (not UI-only)
  - Must verify POST method (not GET)
  - Must verify CSRF protection
  - Must verify device ID cannot be substituted
  - Must verify audit logging
  - **Do NOT test with actual device unlock**

### PR #2 Security Checklist
- ✓ UI-only changes
- ✓ No authorization logic changes
- ✓ Navigation state changes
- **SAFE: Backend authorization unchanged**

### PR #3 Security Checklist
- ⚠️ **CSV Export: VERIFY DATA CONSISTENCY**
  - Building filter must apply to CSV query
  - Date range must match UI
  - Employee filter must match UI
  - **RISK: CSV values starting with =, +, -, @ must be escaped**
- ⚠️ **PDF Export: SAME VERIFICATION**
  - PDF query must use identical filters
  - PDF must use same export authorization
  - **RISK: User-controlled data in PDF must be escaped**

---

## PHASE 10 — INTEGRATION BRANCH CREATION

**Command:**
```bash
git switch -c integration/yazied-updates-2026-09
```

**Expected Initial State:**
```
Branch: integration/yazied-updates-2026-09
SHA: 313ecde6fdea9bb4d6577e96efe6c41ce186baf3 (unchanged)
Status: CLEAN
```

---

## PHASE 11 — APPLICATION ORDER (Recommended)

**Order:** Low-risk first, security-sensitive last

1. **PR #2 (eb52449)** — Hak Akses [LOW RISK]
   - Pure UI/navigation
   - No authorization changes
   - Easy to revert if issues

2. **PR #3 (001b894)** — Rekap Kehadiran [MEDIUM RISK]
   - Reporting/export
   - Test coverage comprehensive
   - Verify filter consistency

3. **PR #1 (20adca4)** — Perangkat Pintu [MEDIUM-HIGH RISK]
   - Device actions
   - Security-sensitive (Remote Unlock)
   - Requires detailed authorization review

**Apply via Cherry-Pick:**
```bash
git cherry-pick eb52449  # Hak akses
git cherry-pick 001b894  # Rekap kehadiran
git cherry-pick 20adca4  # Perangkat pintu
```

---

## PHASE 12-14 — VALIDATION SEQUENCE

### Static Validation
```bash
composer validate
php artisan route:list
# Check for syntax errors in modified PHP files
```

### Targeted Tests
```bash
php artisan test --filter=AccessProvisioning
php artisan test --filter=AttendanceReport
php artisan test --filter=PerangkatPintu
```

### Full Test Suite
```bash
php artisan test
# REQUIREMENT: FAILURES = 0
```

---

## PHASE 15 — SECURITY REVIEW CHECKLIST

- [ ] Authorization: All device actions protected by Policy/Gate
- [ ] CSRF: POST requests have @csrf protection
- [ ] IDOR: Device ID cannot be arbitrarily substituted
- [ ] XSS: Blade values escaped ({{ }} not {!!) })
- [ ] SQL: No raw queries, use Query Builder
- [ ] CSV: No formula injection (=, +, -, @ escaping)
- [ ] PDF: No injection risks in export output
- [ ] Audit: Device actions logged
- [ ] Secrets: No exposed credentials in code
- [ ] Authorization: Backend enforcement, not UI-only

---

## PHASE 16 — FUNCTIONAL VALIDATION CHECKLIST

### Perangkat Pintu
- [ ] Edit button renders correctly
- [ ] Maintenance button renders correctly
- [ ] Remote Unlock button renders with permission
- [ ] Edit action routes correctly
- [ ] Maintenance action routes correctly
- [ ] Unlock action requires authentication
- [ ] Unlock action enforced by Policy
- [ ] No physical unlock triggered during test
- [ ] Audit trail created

### Hak Akses
- [ ] Default sub-tab active on page load
- [ ] Sub-nav pills style applied
- [ ] Navigation state correct
- [ ] Backend authorization logic unchanged
- [ ] Permission rendering correct

### Rekap Kehadiran
- [ ] Building filter dropdown populated
- [ ] Building filter changes query
- [ ] Date range filter works
- [ ] CSV export button visible
- [ ] CSV export respects building filter
- [ ] PDF export button visible
- [ ] PDF export respects building filter
- [ ] CSV/PDF/UI datasets match (same count, same rows)
- [ ] Pagination retained with filters
- [ ] No data leakage across buildings

---

## PHASE 17 — SECONDARY COMMITS (Classification Only)

**Do NOT integrate yet. Classify for future decision:**

| Commit | Feature | Priority | Classification |
|--------|---------|----------|-----------------|
| 7883385 | Access-log filter | MEDIUM | NEEDS_REVIEW |
| 2c0844c | Device wizard | LOW | OUT_OF_SCOPE |
| 5c1dba2 | Password reset | LOW | NEEDS_SEPARATE_REVIEW |
| e1a2ce3 | Card workflow | LOW | OUT_OF_SCOPE |
| 734c6c1 | Bulk access | LOW | OUT_OF_SCOPE |

---

## PHASE 18 — FINAL AUDIT (Pre-Merge)

**Before merging integration branch:**

```bash
git status --short
# Expected: NOTHING (all committed)

git diff --stat 313ecde
# Expected: Only intended Yazied changes, no .history files

git log --oneline 313ecde..integration/yazied-updates-2026-09
# Expected: 3 commits (eb52449, 001b894, 20adca4)

# Verify NO production config changes:
git diff 313ecde -- .env* config/ docker-compose*
# Expected: NO DIFF
```

---

## PHASE 19 — FINAL REPORT TEMPLATE

```
BASELINE_SHA=313ecde6fdea9bb4d6577e96efe6c41ce186baf3

INTEGRATION_BRANCH=integration/yazied-updates-2026-09

PR1_COMMIT=20adca4
PR1_CLASSIFICATION=APPLY_WITH_AUTHORIZATION_REVIEW
PR1_RESULT=[PENDING]

PR2_COMMIT=eb52449
PR2_CLASSIFICATION=SAFE_TO_APPLY
PR2_RESULT=[PENDING]

PR3_COMMIT=001b894
PR3_CLASSIFICATION=APPLY_WITH_EXPORT_VERIFICATION
PR3_RESULT=[PENDING]

FILES_REVIEWED=7 (3 controllers/policies, 4 views/tests)
FILES_CHANGED=10 (code + tests)
FILES_SKIPPED=0 (.history ignored)

AUTHORIZATION_REVIEW=REQUIRED (door actions)
REMOTE_UNLOCK_SECURITY=CRITICAL_CHECK_NEEDED
CSV_SECURITY=FORMULA_INJECTION_REVIEW
PDF_EXPORT_REVIEW=DATA_ESCAPING_CHECK

COMPOSER_VALIDATE=[PENDING]
PHP_SYNTAX=[PENDING]
ROUTE_VALIDATION=[PENDING]

TARGETED_TESTS=[PENDING]
FULL_TESTS=[PENDING]
TEST_FAILURES=[PENDING]

SECONDARY_COMMITS_CLASSIFICATION=5 commits deferred (access-log, wizard, password-reset, card, bulk-access)

BASELINE_BRANCH_CHANGED=NO
PRODUCTION_CHANGED=NO
DEPLOYMENT_EXECUTED=NO

FINAL_VERDICT=[AWAITING EXECUTION & VALIDATION]
```

---

## EXECUTION READY

**Status:** All analysis complete, integration branch structure ready.

**Next Step:** Execute cherry-pick sequence on `integration/yazied-updates-2026-09` branch, run tests, validate security/functionality, then report final verdict.

**Timeline:** 
- Cherry-pick + static validation: 10 minutes
- Targeted tests: 5-10 minutes
- Full tests: 10-15 minutes
- Security review: 10-15 minutes
- **Total: 45-60 minutes**

**Do NOT:**
- Push to `ishak/full-functional-integration-2026-09`
- Merge without explicit approval
- Deploy automatically

**Ready for:** Human code reviewer approval → automated execution → final validation → merge decision
