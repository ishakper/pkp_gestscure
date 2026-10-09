# Phase 17 Office Verification Checklist

Every unchecked item remains **OFFICE-ONLY VERIFICATION PENDING**. Stop on unexpected write, secret exposure, scope leak, backup failure, or unexplained health state.

## A. Environment

- [ ] Confirm workstation is on approved office network.
- [ ] Confirm production server is reachable through approved access path.
- [ ] Confirm physical Hikvision terminal is reachable.

## B. Production safety

- [ ] Capture production branch and exact commit.
- [ ] Capture running container names, image digests, health, volumes, and queue state.
- [ ] Create immutable pre-deploy tag from current production commit.
- [ ] Create database backup before deployment/migration.
- [ ] Verify backup readability without altering production.
- [ ] Record rollback commit, image digest, and DB decision owner.
- [ ] Record candidate commit and image digest.

Do not run `docker compose down`, `docker system prune`, `docker image prune`, `docker volume prune`, `php artisan migrate:fresh`, `php artisan db:wipe`, or force-push `main`.

## C. Hikvision read-only

- [ ] Authenticate safely without printing credentials.
- [ ] Read UserInfo inventory; verify bounded pagination and stable 32-character hexadecimal `searchID`.
- [ ] Read CardInfo inventory; verify card state remains authoritative and output redacted.
- [ ] Read recent events without issuing device writes.

## D. Physical webhook

- [ ] Trigger one approved physical event.
- [ ] Confirm webhook authentication succeeds.
- [ ] Confirm AccessLog source is `HIKVISION_WEBHOOK`.
- [ ] Confirm webhook health changes from `UNKNOWN`/`STALE` to `ACTIVE` using physical evidence only.

## E. Attendance

- [ ] Use one named controlled employee/test card.
- [ ] Confirm employee mapping and door mapping.
- [ ] Confirm one AttendanceEvidence and expected Attendance update.
- [ ] Replay/check duplicate event; confirm no duplicate attendance.
- [ ] Confirm denied/unknown event creates no attendance.

## F. SSE

- [ ] Confirm production reverse proxy streams SSE without buffering failure.
- [ ] Confirm reconnect after server-requested timeout.
- [ ] Confirm no duplicate stream and no console error storm.

## G. System Health

- [ ] Confirm each door status matches observed evidence.
- [ ] Confirm webhook status/freshness matches physical event.
- [ ] Confirm overall `HEALTHY`/`DEGRADED` result follows critical components.
- [ ] Confirm no secrets, runtime fingerprints, card identifiers, or raw payloads appear.

## H. Security

- [ ] Building Admin sees only assigned-building dashboard, users, doors, access, logs, attendance, setup, and health.
- [ ] Cross-building direct IDs, filters, exports, pagination, and nested resources fail safely.
- [ ] Super Admin receives intended global visibility.
- [ ] Audit Log remains super-admin-only and redacted.
- [ ] System Account output remains read-only and redacted.

## I. Merge readiness

- [ ] Run focused tests.
- [ ] Run full PHPUnit.
- [ ] Run PHP lint, JavaScript syntax, diff check, and conflict scan.
- [ ] Compare candidate branch against `main` and expected baseline.
- [ ] Review every migration; confirm none unexpected.
- [ ] Review config/environment variable changes without printing values.
- [ ] Confirm `HikvisionIsapiService.php` matches protected Phase 16 baseline.
- [ ] Record final GO / NO-GO with approver and evidence links.
