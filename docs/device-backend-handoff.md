# Device Backend Handoff Contract

Updated: 2026-10-15
Source checkpoint: `970546ce91edd9881a5eb0b81587a288402edaa1`

## Remote Unlock

- Route: `POST /api/v1/admin/doors/{door_id}/open`
- Payload: optional `{ "reason": string }`; max 255 characters.
- Permission: door `open` policy.
- Success: HTTP `200`, `status=success`, activity action `remote_door_opened`.
- Failure: `409` when device is not verified online; `500` for device/service failure; no success audit on failure.
- Mock HTTP accepts JSON `statusCode=1` only when both mock mode and mock HTTP are enabled. Non-mock path retains XML success parsing.
- Mock success is not physical-door confirmation.

## Employee Provisioning

- Route: `POST /api/v1/doors/{door_id}/sync-employee/{employee_id}`.
- Inputs: optional `door_ids`/`door_id`; `mode=sync|async`.
- Authorization: provisioning capability, employee scope, door building scope.
- `async`: assignment is `pending`, `SyncDoorAccessJob` is dispatched; queued is not device confirmation.
- `sync`: service performs provisioning and returns per-door success/failure.
- Missing assignment: HTTP `422`; no-door match: `404`; cross-building access: `403`.
- Repeated provisioning uses existing assignment uniqueness/idempotency behavior.

## Batch Assignment Sync

- Route: `POST /api/v1/door-assignments/sync`.
- Optional filters: `door_id`, `status=pending|failed`.
- Permission: device management plus physical-control scope for each target door.
- Response reports `dispatched_count`; activity action `batch_sync_triggered`.
- Worker failures update assignment state and audit; failure is not reported as confirmed success.

## Verification

- Clean verification image: `securegate-verification:handoff-95dac55-r2`.
- Clean production image: `securegate-device-batch:handoff-970546c`.
- Service source/runtime SHA-256: `2D9C93994764D2F57A040D4FBF73F2FDF33B64E706B42B1AB95F6E3A06C8396F`.
- Remote Unlock suite: `14 tests / 30 assertions`, exit `0`.
- Provisioning/queue/authorization suite: `33 tests / 146 assertions`, exit `0`.
- Combined targeted backend regression: `47 tests / 166 assertions`, exit `0`.
- Full Feature regression: `599 passed / 11 failed` out of `610 tests`, `2769 assertions`; failures are outside the device-control scope (one dashboard.js expectation owned by Yazied and ten existing hardware/attendance permission-fixture failures). No unrelated patch applied.
- Disposable guard and PostgreSQL verification DB only. No production, snapshot, or physical-device writes.

## Runtime Reproducibility

- Disposable DB identity verified: `securegate_device_batch_20261015` with test-only user `securegate_device_test`.
- Clean runtime image: `securegate-device-batch:handoff-970546c`, manifest `sha256:5e036ccb61b14408e68f325c5487abade2b5186bd19fb1308cd161e4768ab87e`.
- Verification image: `securegate-verification:handoff-95dac55-r2`, image ID `sha256:47bf0867cadd07134ff168f0796a8d620bcb3f40991988521ec595feb48905e6`.
- JUnit artifacts are stored outside the repository under the local device-artifacts directory.
## Full Regression Failure Analysis (2026-10-15)

- Ten webhook/attendance failures were fixture configuration failures: tests sent `secret_simulator_key_2026` from loopback, while the generic image run used the device-batch secret. Re-run with the declared disposable secret and `ALLOWED_DEVICE_IPS=127.0.0.1`: `11 tests / 66 assertions`, exit `0`.
- Remaining full-suite failure is `Tests\Feature\DashboardBuildingFilterTest::test_metric_pagination_uses_api_total_pages_and_cancels_stale_requests`, `/var/www/html/tests/Feature/DashboardBuildingFilterTest.php:69`. Expected pagination guard text is absent from `public/js/dashboard.js`; UI file is Yazied-owned and unchanged here. Reproduce with the full Feature runner; do not disable or rewrite the assertion in backend work.
- Backend device-control targeted suite remains `47 tests / 166 assertions`, exit `0`.