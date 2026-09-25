# PHASE 5: Comprehensive Testing Plan

**Branch**: `integration/yazied-final-2026-09` (HEAD: 8a80745)  
**Status**: Ready for execution  
**Environment**: Production container (10.10.8.124:8000)

## Test Strategy

### 1. JavaScript Validation ✓ PASSED
```bash
node --check public/js/dashboard.js
# Result: PASS (clean syntax)
```

### 2. Focused Feature Tests (Must run in production container)

Target test files that exercise integrated features:

#### Building Filter & KPI Metrics
```bash
phpunit --filter='DashboardBuildingFilterTest' tests/Feature/DashboardBuildingFilterTest.php
phpunit --filter='DashboardMetricsTest|DashboardKpiMetricsTest'
```
- Tests: Building selector dropdown, per-building KPI calculations, scope refresh
- Yazied integration: INCLUDED (building-aware metric calculation)

#### Door Device Management
```bash
phpunit --filter='PerangkatPintuActionsTest'
```
- Tests: Edit, Maintenance, Remote Unlock button functionality
- Integration impact: renderDoorCards() refactored, hardcoded logic removed
- Success criteria: All 4 door card actions execute without undefined variable errors

#### Access Log Filtering
```bash
phpunit --filter='AccessLogFilterTest'
```
- Tests: Building-scoped access log queries
- Integration impact: Building filter now controls log display
- Success criteria: Logs filtered per building selection

#### Dashboard Core Integration
```bash
phpunit --filter='DashboardIntegrationTest'
```
- Tests: Overall dashboard load, metric aggregation, pagination
- Success criteria: No `isPrimaryDeploymentDoor` console errors, all KPIs render

#### Hikvision Integration
```bash
phpunit --filter='HikvisionIsapiServiceTest|HikvisionMockTest|RemoteUnlockDoorTest'
```
- Tests: Device connectivity, mock vs real mode, remote unlock workflow
- Config check: HIKVISION_ISAPI_USE_MOCK=false in production .env
- Success criteria: Door unlock calls reach API without auth errors

### 3. UI Contract Tests (Browser-based, requires docker-compose up)

Cannot execute locally (sandbox limitation). Must run in production:

```bash
# Inside container:
phpunit --filter='DashboardButtonContractTest|DashboardOverviewPanelTest|AttendanceTimeDisplayContractTest'
```

### 4. Full Test Suite

Execute when all focused tests pass:

```bash
# Run all feature tests
phpunit tests/Feature/ --exclude=tests/Feature/ExampleTest.php

# Expected: >85 tests, 0 failures
```

## Execution Instructions

### Local (Pre-Production Validation)
1. ✓ JavaScript syntax validated
2. Code review of key changes:
   - `renderDoorCards()` hardcoded logic removed
   - Building filter integration in dashboard.blade.php
   - KPI metric calculation per building

### Production Container (Required)
1. SSH into production host (10.10.8.124)
2. Inside container `pkp_securegate_prod`:
   ```bash
   # Navigate to app
   cd /app
   
   # Run focused tests
   php artisan test --filter='DashboardBuildingFilter|PerangkatPintuActions|RemoteUnlock'
   
   # Run full suite
   php artisan test
   ```

3. Expected output:
   - All tests PASS
   - 0 failures
   - 0 undefined variable errors in console
   - Door cards render for all configured doors (A, B, C, D)

## Gate Criteria (PHASE 5 Pass/Fail)

### PASS Criteria
- [ ] JavaScript syntax clean
- [ ] All focused feature tests pass (DashboardBuildingFilter, PerangkatPintu, RemoteUnlock)
- [ ] No undefined variable errors in browser console
- [ ] Door cards render correctly for all 4 doors
- [ ] Building filter dropdown functional
- [ ] KPI metrics per building accurate
- [ ] Access logs filter by building
- [ ] Remote unlock button calls API without errors

### FAIL Criteria (Block deployment)
- JavaScript syntax errors
- DashboardBuildingFilterTest fails
- PerangkatPintuActionsTest fails (door card actions broken)
- RemoteUnlockDoorTest fails (unlock API not working)
- Console errors: `isPrimaryDeploymentDoor is not defined`
- Any test suite error

## Files Changed in Integration

1. **public/js/dashboard.js** (910 ±533 lines)
   - Removed hardcoded DOOR-B logic
   - Integrated building filter selector
   - Integrated KPI metric calculations

2. **resources/views/dashboard.blade.php** (11 changes)
   - Added building filter dropdown
   - Updated metric display containers

3. **app/Http/Controllers/Api/V1/AdminDoorController.php** (10 changes)
   - Support for ?building_id parameter
   - Per-building metric calculations

4. **tests/Feature/DashboardBuildingFilterTest.php** (NEW, 99 lines)
   - Yazied test coverage for building filter

5. **Other tests** (4 changes)
   - Updated for building-aware assertions

## Test Execution Timeline

- Focused tests: ~5 minutes (if run in parallel)
- Full suite: ~15-20 minutes
- UI contract tests: ~10 minutes
- Total: ~30-40 minutes

## Rollback Procedure

If any test fails:
1. Revert to baseline (f88407a):
   ```bash
   git reset --hard f88407a
   ```
2. Re-evaluate specific test output
3. Document failure reason
4. Return to PHASE 3 (Fix) with new issue data

## Next Phase (PHASE 6)

After all tests pass, create PR and push to GitHub:
- PR target: `origin/integration/yazied-final-2026-09`
- Document: Yazied integration, hardcoded logic removal, test results
- Reviewers: Lead engineer + Yazied for feature validation
