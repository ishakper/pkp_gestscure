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

## Mock Request Correlation (2026-10-15)

- Fresh fixture only: employee `3`, door `8`, assignment `3`, mock target `192.0.2.252`.
- Queue/job: `worker-correlation2-20261015`, database job ID `3`; one isolated worker consumed it.
- Mock adapter correlation: five requests for the same target: two `GET /System/deviceInfo`, then `PUT /AccessControl/UserInfo/SetUp?format=json`, `PUT /AccessControl/CardInfo/Record`, and `PUT /AccessControl/UserRightPlan/SetUp?format=json`. Adapter generated unique request IDs and retained redacted bodies.
- Final disposable DB readback: assignment `3` became `synced`, queue row `3` was gone, and audit `sync_door_success` referenced subject `3`. Failed-job readback was performed on the same disposable DB.
- Artifact: `runtime-worker-mock-correlation-20261015.json` outside Git. This proves mock request correlation for `SyncDoorAccessJob`; it does not prove a physical device result or `ProcessCredentialDeviceSyncJob` execution.
- Lease columns were verified only on container `securegate-device-batch-pg-20261015`, database `securegate_device_batch_20261015`; this is not production schema evidence.

## Server and Backup Audit Status

- Active server/source/image comparison is not available from this checkout; no server credential or production connection was used. Candidate/server diff remains OPEN.
- No authorized current pre-deploy backup, checksum, or archive was available for this run. No backup was fabricated and no restore was attempted against snapshot/reference resources.

## Read-only Server Audit (2026-10-15)

- SSH alias: `pkp-securegate-prod`; host: `infra-Standard-PC-i440FX-PIIX-1996`; audit time: `2026-10-15T06:57:55Z`.
- Active app: container `pkp_securegate_prod`, image `pkp-securegate:3d6e3ef-prod`, image digest `sha256:3d2a6d2098affec029dcf9612d080d4b16461c67a6949ae1d32ade3568839763`, entrypoint `/usr/local/bin/entrypoint.sh`, supervisor command.
- Effective production DB identity: database `securegate`, DB user `securegate`; lease columns `lease_token`, `lease_expires_at`, and `network_started_at` are absent. Disposable lease columns must not be treated as production schema evidence.
- Hash comparison: `SyncDoorAccessJob.php` and `BiometricProvisioningController.php` match candidate; `HikvisionIsapiService.php` differs; `ProcessCredentialDeviceSyncJob.php` and lease migration are absent from the active server image. These are runtime/schema compatibility blockers.
- Server backup inventory under application `storage/backups` returned no files. No backup was copied, restored, or modified.
- Server feature/config audit was limited to non-secret metadata. No production switch, migration, config change, or deployment performed.

## Candidate/Server Diff

- Candidate-only runtime changes requiring explicit compatibility review: mock HTTP parser boundary in `HikvisionIsapiService`, `ProcessCredentialDeviceSyncJob`, lease metadata migration, and related backend contract/tests.
- Preserved server behavior: existing `SyncDoorAccessJob` and `BiometricProvisioningController` file content matches candidate hashes.
- Rollback risk: candidate queue/lease path cannot be activated against current production schema without an approved schema plan and isolated rehearsal.

## Backup and Schema Rehearsal (2026-10-15)

- Host backup locations were inventoried; historical dumps exist under `/home/infra/backups` and `/home/infra/access-door-management/backups`.
- New authorized backup: `/home/infra/backups/securegate-predeploy-20261015T070607Z.dump`; size `448927` bytes; mode `600`; SHA-256 `4802fa917791e3612ad3faff742107e422ee05b56f0a73aa5007ef228106bba9`; archive check `PASS` via PostgreSQL 16 `pg_restore --list`.
- Restore target: brand-new container `securegate-release-rehearsal-pg-20261015`, database `securegate_rehearsal`, user `securegate_rehearsal`, volume `securegate-release-rehearsal-vol-20261015`. Existing DBs/volumes were preserved.
- Restored baseline counts: `door_assignments=98`, `employees=109`, `doors=4`, `credential_device_syncs=0`.
- Reviewed additive lease rehearsal ran only on the new restored DB: nullable `lease_token` plus unique index, indexed `lease_expires_at`, and nullable `network_started_at`. No production migration ran.
- Candidate app/worker smoke against this restored copy remains OPEN because candidate image is not installed on the server audit host. Do not treat schema-only rehearsal as candidate compatibility PASS.

## Release Options

- Option A (recommended): additive schema migration first on an approved maintenance path, then app image, then isolated worker; retain new nullable columns on rollback. Requires migration rehearsal with the exact candidate image and production approval.
- Option B: limited release only if lease-dependent routes/jobs are excluded at source and tested as absent, not merely hidden by `SKIP_MIGRATIONS` or a feature flag. Current source does not have evidence for this separation; do not select Option B.
