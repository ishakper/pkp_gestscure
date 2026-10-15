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
- Disposable guard and PostgreSQL verification DB only. No production, snapshot, or physical-device writes.