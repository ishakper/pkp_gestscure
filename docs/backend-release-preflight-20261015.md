# Backend Release Preflight

Updated: 2026-10-15
Candidate commit: `fb05d364dcf117782f907f0d0b821bc4ff793d4f`
Status: `BELUM SIAP DEPLOY`

## Evidence Boundary

- PHPUnit queue/worker contract: PASS in the targeted backend suite (`47 tests / 166 assertions`). This proves dispatch, job behavior, authorization, idempotency, failure handling, and audit assertions inside the test harness.
- Runtime worker database path: OPEN. Disposable runtime readback found zero `jobs`, zero `credential_device_syncs`, and zero assignment rows for a live enqueue-to-worker rehearsal. No claim of runtime worker confirmation is made.
- Runtime DB identity: `securegate_device_batch_20261015`; test-only user; outbound device target remains mock-only.
- Unlock clean-image smoke: PASS for mock response and audit; physical door state remains `UNSUPPORTED`.

## Candidate and Schema Inventory

- `970546c..fb05d36` contains handoff documentation only; no runtime service/controller/job source changed after the tested image checkpoint.
- Relevant schema dependency: migration `2026_10_13_000001_add_device_sync_lease_metadata.php` adds nullable `lease_token` (unique), indexed `lease_expires_at`, and nullable `network_started_at` to `credential_device_syncs`.
- Provisioning persistence also depends on `door_assignments`, `credential_device_syncs`, `jobs`, and activity/audit tables. Queue mode and worker image must use the same candidate source/config.
- Schema compatibility is read-only verified on disposable DB. No migration ran in this preflight.
- Server candidate diff is not available through this checkout; remote/server audit remains OPEN and must be performed by an authorized operator.

## Restore and Rollback Preparation

- Restore rehearsal runner must accept an operator-authorized backup, create a new disposable PostgreSQL container/database, verify checksum/schema/row-count invariants, then run app smoke tests with worker and outbound device calls blocked.
- No authorized current backup, checksum, or restore result is present in this checkout. The old snapshot is not treated as a current pre-deploy backup.
- Rollback plan: app-only switch to last-known-good immutable image, preserve DB/volume/APP_KEY/credentials, stop worker traffic, verify login/API/audit paths, and do not restore production data automatically.
- Production backup, restore rehearsal, candidate-vs-server diff, and rollback execution remain OPEN. No server mutation or application switch performed.

## Integration Gate

After Yazied UI branch/PR arrives: verify base and file ownership, integrate surgically, build one combined candidate image, run full regression, then test that same image against an isolated backup copy. Update this document using the exact combined commit/image; do not reuse readiness from an older candidate.
## Runtime Worker Rehearsal (2026-10-15)

- Worker path tested: `SyncDoorAccessJob` only; `ProcessCredentialDeviceSyncJob` is not included in this evidence.
- Disposable DB identity: container `securegate-device-batch-pg-20261015`, database `securegate_device_batch_20261015`; no production/schema-server inference.
- Fixture: employee `1`, door `6`, assignment `1`; queue `worker-device-20261015`; one job was stored, one isolated worker consumed it, and no competing supervisor used that queue.
- Final readback: `door_assignments.id=1` became `sync_status=synced`, `sync_attempts=1`, `last_sync_error=NULL`; queue row count became `0`; audit `sync_door_success` recorded for subject `1`.
- App/worker image: `securegate-device-batch:handoff-970546c`; mock target `mock-device`; real-device outbound remained blocked.
- Artifact: `runtime-worker-rehearsal-20261015.json` outside Git. Mock container did not retain request access logs, so request-log correlation remains an evidence gap; success is based on worker completion, target-mock config, state, and audit—not physical-device confirmation.
