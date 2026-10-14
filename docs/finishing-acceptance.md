# Finishing Acceptance

Snapshot: 14 October 2026, 16:01:15 WIB. Preview: `http://127.0.0.1:18090/`.

| ID | Target | Aktual | Status | Commit | Evidence | Tindakan berikutnya |
|---|---|---|---|---|---|---|
| UI-POP-01 | Employee modal Escape/focus/scroll | Desktop 3 scroll positions and mobile pass on disposable | PASS (tested path) | `49bdeb1` | `artifacts/popup-runtime-dda728e-rerun` | Audit other modal families |
| UI-BRAND-01 | Navigation logo/watermark split | Integrated branding; navigation logo remains legacy asset | PASS (tested path) | `9b4dfd3` | Prior hash verification | No repeat without asset change |
| DATA-01 | Snapshot preview isolation | `securegate_snapshot_work`; worker/scheduler disabled; HTTP 200 | PASS (read-only setup) | `49bdeb1` | Container/env/hash checks | Operator login read-only comparison |
| DATA-02 | Reference snapshot preservation | Reference dump and DB retained | PASS | `bc4a57f` | Dump checksum/restore manifest | Keep immutable |
| B1-01 | First assign desktop/mobile | Valid target, assignment/audit assertions passed | PASS | `4e6455d` | Batch 1 artifacts | Do not rerun without relevant change |
| B1-02 | Refresh failure/recovery | Retry desktop/mobile previously passed | PASS (tested path) | `4e6455d` | Prior incremental artifacts | Do not rerun without relevant change |
| B1-03 | Partial failure, two doors | Existing runner contains `UNVERIFIED` placeholder | OPEN | — | `securegate-e2e-harness/batch1-ui-failures.cjs` | Implement controlled per-door fault and DB/job/audit assertions |
| B1-04 | Authorization scopes | Existing runner contains `UNVERIFIED` placeholder | OPEN | — | `securegate-e2e-harness/batch1-ui-failures.cjs` | Execute own/cross/no-permission with valid fixture accounts |
| B1-05 | Timeout-after-write | UI/request scaffold exists; write persistence not proven | OPEN | — | `securegate-e2e-harness/batch1-ui-failures.cjs` | Prove DB write before response fault and no duplicate mutation |
| B1-06 | UNKNOWN denial | Existing runner path is incomplete/contract-dependent | OPEN | — | `securegate-e2e-harness/batch1-ui-failures.cjs` | Assert reason, bounded Retry and authenticated 422 |
| FUNC-01 | Full regression | Target verification: 76 tests / 316 assertions PASS | PARTIAL | `49bdeb1` | Verification runner output | Run full 605-test suite on final checkpoint |
| SNAP-01 | Snapshot UI/data mapping | Login operator session unavailable | BLOCKED | — | Preview login requires operator session | Login with existing operator account, read-only |
| FILE-01 | Upload/storage preservation | PostgreSQL dump excludes file storage | OPEN | — | Snapshot note | Inspect storage separately, read-only |
| DEVICE-01 | Physical revoke/readback | Not proven by mock | UNSUPPORTED | — | Capability limitation | Keep blocked |
| PWA-01 | Installability/PWA | Not audited in this batch | OPEN | — | — | Audit manifest/service worker/offline behavior |
| MENU-01 | Full menu/master-plan coverage | Not fully audited | OPEN | — | — | Audit each menu and control end-to-end |

## Safety gates

- Mutation runners must target disposable DB only and reject `18090`/snapshot identities.
- No production/parity writes, deployment, server sync-back, device access, or snapshot migration.
- Do not claim operational readiness while any `OPEN`, `BLOCKED`, or `UNSUPPORTED` row remains on critical paths.

## Batch 1 final evidence (2026-10-14)
- Timeout desktop/mobile: PASS, fixtures `tw1441`/`tw1442`, artifact `artifacts/timeout-final`.
- Authorization API: PASS, fixture `az1445`: own `200`; cross/no-permission `403`; DB/audit readback confirms denial has no business write. Invalid UNKNOWN attempt `az1445` returned `500`; retained as invalid fixture run, not PASS.
- UNKNOWN API: PASS, fixture `az1446`: authenticated retry returns `422`; status remains `UNKNOWN`; no retry job created.
- UNKNOWN UI desktop/mobile: OPEN. Browser reached Device Sync view but rendered no `az1446` fixture row; observed only filter label `Unknown`. No retry control or mutation beyond login observed. Evidence: `artifacts/unknown-ui-az1446`.
- Snapshot preview `18090` untouched. Physical revoke/readback remains UNSUPPORTED.
- UNKNOWN UI: PASS, fixture `az1447`; API response includes sync `48`, employee `UI-az1447`, door `UI-az1447-A`, status `UNKNOWN`, reason `UI fixture unknown`; desktop/mobile render row with `UNKNOWN` and `NOT VERIFIED`, visible retry controls `0`, and no non-login mutation. Trace: `artifacts/unknown-ui-az1446/trace-desktop.json`, `trace-mobile.json`; screenshots/results: `artifacts/unknown-ui-az1447`.
- Root cause of earlier empty row: fixture actor lacked `employee_id`/employee building scope while Device Sync query requires building scope. Fixed disposable fixture helper only; application query unchanged.
- Full regression 2026-10-14: `605 tests`, `2614 assertions`, exit `2`; `9 errors`, `47 failures`. Log: `artifacts/full-regression-20261014.log`. This is not a Batch 1 gate failure diagnosis; failures are broader device/attendance/reconciliation suite gaps and remain open.
- Preview `18090` was not rebuilt or changed because full regression did not pass. Snapshot DB and worker/outbound isolation remain preserved.
