# PHASE 6: Pull Request

**Status**: Ready for creation  
**GitHub URL**: https://github.com/ishakper/pkp_gestscure/pull/new/integration/yazied-final-2026-09

## PR Details

**Title**: integrate: Yazied dashboard building filter + remove hardcoded deployment door logic

**Base Branch**: main  
**Head Branch**: integration/yazied-final-2026-09  
**Commits**: 3 new (f88407a → 8a80745)

## Description

```markdown
## Summary

Integrated Yazied's dashboard building filter feature (`github/yazied/dashboard-building-filter`) 
with critical hardcoded logic removed.

## Changes

### Features (from Yazied)
- ✓ Building filter selector (Semua Gedung / A / B / C / D) in dashboard header
- ✓ KPI metrics calculated per-building (employee count, door status, event logs)
- ✓ Access log filtering by building
- ✓ Comprehensive test coverage (DashboardBuildingFilterTest, 99 lines)

### Fixes (Integration)
- ✓ Removed hardcoded `isPrimaryDeploymentDoor = door.door_id === 'DOOR-B'` from renderDoorCards()
  - Violation of spec: "Jangan membuat nilai hard-coded hanya untuk menyembunyikan error"
  - Impact: Prevented non-DOOR-B devices from displaying as "online"
- ✓ Simplified door status logic: `isOnline` now based on `healthStatus === 'online'` for all doors
- ✓ Removed "Gedung B deployment target hanya" annotation from UI

### Files Modified
- `public/js/dashboard.js` (-366, +99 net): Hardcoded logic removed, building filter integrated
- `resources/views/dashboard.blade.php` (+11): Building filter dropdown added
- `app/Http/Controllers/Api/V1/AdminDoorController.php` (+10): Building-aware metric calculation
- `tests/Feature/DashboardBuildingFilterTest.php` (+99): New Yazied test coverage
- Test updates: AccessLogFilterTest, PerangkatPintuActionsTest

## Validation

- ✓ JavaScript syntax: `node --check public/js/dashboard.js` PASS
- ✓ Git history: Clean commits, no force resets
- ⏳ PHPUnit tests: Ready for execution in production container
  - Target tests: DashboardBuildingFilterTest, PerangkatPintuActionsTest, RemoteUnlockDoorTest
  - Execution: `docker compose exec -T app php artisan test --filter='DashboardBuilding|PerangkatPintu|RemoteUnlock'`

## Deployment Notes

1. **Disk requirement**: Production host needs ≥3GB free (currently 815MB - NEEDS EXPANSION)
2. **Hikvision config**: Verify production .env has `HIKVISION_ISAPI_USE_MOCK=false`
3. **Rollback**: If needed, revert to f88407a (baseline before integration)

## Testing Checklist

- [ ] Run focused PHPUnit tests in production container
- [ ] Verify building filter dropdown renders correctly
- [ ] Test door card rendering for all 4 devices (A/B/C/D)
- [ ] Confirm KPI metrics update when building filter changes
- [ ] Validate remote unlock button works
- [ ] Check browser console for `undefined` errors

## Reviewers
- Lead engineer: Code review, architecture validation
- Yazied: Feature validation (building filter, KPI calculations)
```

## Manual PR Creation Steps

If automated tool unavailable:

1. Go to: https://github.com/ishakper/pkp_gestscure/pull/new/integration/yazied-final-2026-09
2. Set base: `main`
3. Set head: `integration/yazied-final-2026-09`
4. Copy description from above
5. Click "Create Pull Request"

## Expected CI Results

- GitHub Actions should run:
  - Syntax validation (eslint, prettier)
  - PHP linting
  - Test suite (if configured)
  
## Approval Gate

- ✓ Code review: Lead engineer
- ✓ Feature validation: Yazied (building filter, metrics)
- ✓ All tests passing
- ⚠️ Disk space check (phase 8 pre-deployment)

## Next Phase (PHASE 7)

After PR approval and merge:
1. Build Docker image from merged code
2. Tag as: `pkp-securegate:<SHORT_SHA>-doorfix`
3. Verify image for security & config
4. Push to registry
