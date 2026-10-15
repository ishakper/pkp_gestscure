# Release Readiness

Status: BELUM SIAP DEPLOY
Updated: 2026-10-15

## Scope
Dashboard/Pengguna selesai pada cakupan teruji dan aktif di preview lokal. Batch Perangkat Pintu masih diagnosis. Diagnose All belum terbukti memakai target mock yang seluruhnya terisolasi.

## Current Evidence
- Application HEAD: `a5b6d9b`
- Dashboard/Pengguna regression: `607 tests / 2811 assertions`, exit `0`.
- Device-targeted verification: `46 tests / 176 assertions`, exit `0`.
- Disposable runtime: `18091`; verification DB: `securegate_verification`.
- Snapshot preview `18090` read-only; server dan perangkat nyata tidak disentuh.

## Release Gates
- [ ] Candidate diff reviewed against server application.
- [ ] Latest production backup checksum recorded.
- [ ] Restore rehearsal PASS on isolated copy.
- [ ] Candidate tested against isolated backup copy.
- [ ] Schema/dependency decisions recorded.
- [ ] App-only deploy and rollback procedure ready.
- [ ] DB, volume, APP_KEY, credentials, permissions, assignments, and logs preserved.
- [ ] Scope-relevant functional and security gates PASS.
- [ ] Device Diagnose All target isolation proven.

## Blockers
- Diagnose All iterates all visible `Door` rows; staging contains old and harness fixtures.
- Mock currently defaults to online; timeout/auth-error/invalid-response behavior needs controlled disposable fixture.
- No production switch, migration, production/parity write, server deployment, or sync-back authorized.

## Notification Rule
Report only meaningful status changes, regressions, target mismatch, data/secret/scope/device risk, external blockers, or completion of all release gates. Readiness is not deployment authorization. Post-deploy status remains separate.
## Device Batch Update (2026-10-15)
- Diagnose single mock: PASS for disposable harness target `D-985762D` (`192.0.2.65`); UI request reached `/admin/doors/D-985762D/check-connection`, mock returned online, toast confirmed responsive terminal.
- Diagnose All: OPEN. Endpoint iterates all visible `Door` rows; complete target isolation for mixed legacy/harness staging rows is not yet proven.
- Device controls Edit, Maintenance, View Logs, Sync Users, and Remote Unlock remain OPEN for end-to-end mock evidence.
- Follow-up: mock adapter container emitted no retained log for the prior single Diagnose window; online result remains UI/API evidence only until adapter/request correlation is captured.
- Diagnose All was not run against mixed staging data; no new DB/runtime was claimed as complete.
