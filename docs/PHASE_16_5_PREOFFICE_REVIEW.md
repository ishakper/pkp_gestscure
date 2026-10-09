# Phase 16.5 Pre-Office Review

## 1. Verified baseline

- Branch: `ishak/phase16-5-preoffice-review-2026-09`
- Base commit: `28d37175db0940ea06610e19607e7cb1958d564a`
- Review mode: HOME / LOCAL-ONLY
- Production server, office network, and physical Hikvision hardware were not contacted.

## 2. Architecture summary

PKP SecureGate uses Laravel APIs, Sanctum authentication, Eloquent policies/services, Blade, and vanilla JavaScript. Core access path is Building → Zone → Door. Access events are immutable security records; attendance consumes normalized evidence from eligible events.

## 3. Role model

| Role | Effective scope |
|---|---|
| Super Admin | Global administrative visibility and explicitly authorized operations |
| Building Admin | Assigned-building records only |
| HRD/management roles | Permission-bound HR functions; no implicit device scope |
| Employee/Intern | Self-service or explicitly related records only |
| Technical roles | No biometric provisioning by default |

Audit Log remains global and super-admin-only because current generic activity records cannot prove building ownership for every subject type.

## 4. Building scope model

Canonical `building_id` relationships take priority. Legacy `assigned_building` and door `location` are fallback evidence only where canonical ownership is absent. Direct-ID, list, nested-resource, device-sync, and provisioning paths must apply same scope. Phase 16.5 closed a direct-ID gap in biometric provisioning and sync-status endpoints by authorizing employee ownership before device work or response serialization.

## 5. Hikvision safety model

- `HikvisionIsapiService.php` remains byte-identical to Phase 16 baseline.
- Inventory reads retain bounded UserInfo pagination and CardInfo-safe behavior.
- No physical request was made during this review.
- Physical write routes exist behind authentication, authorization, door state checks, and explicit user actions; they are not invoked by page load or health checks.
- Mock ISAPI write routes load only in `local` and `testing` environments.
- Queue retries must not be interpreted as confirmed hardware success.

## 6. Webhook health semantics

Only authenticated physical route records use source `HIKVISION_WEBHOOK`. Browser simulator records use `SIMULATOR`; pull-sync records do not activate webhook freshness. Health states are evidence-based: `ACTIVE`, `STALE`, or `UNKNOWN`. Missing or stale critical evidence degrades overall health.

## 7. Attendance pipeline

`Hikvision webhook → AccessLog → employee mapping → AttendanceEvidence → AttendanceProcessor → Attendance → API/UI`

Only mapped, granted `STANDARD_TAP` events create attendance evidence. Denied, alarm, and unknown-employee events remain AccessLog-only. Evidence uniqueness by `access_log_id` prevents duplicate attendance processing. Processor failures retain evidence as `PROCESSING_FAILED` without deleting security records or exposing raw payloads.

## 8. Module completion matrix

| Module | Local status | Scope |
|---|---|---|
| Dashboard | Verified | Global super admin; assigned building admin |
| Setup Gedung | Verified | Read hierarchy scoped; writes permission-gated |
| Pengguna | Verified | Direct-ID and list scope enforced |
| Perangkat Pintu | Verified | Door scope and physical-control policy |
| Hak Akses | Verified | Profile/request/credential/sync scope enforced |
| Log Akses | Verified | Building-scoped query and bounded pagination |
| Rekap Kehadiran | Verified | Building-scoped reporting/export |
| Audit Log | Verified | Global super-admin-only, redacted |
| Akun Sistem | Verified | Read-only, redacted, lifecycle `PLANNED` |
| Status Sistem | Verified | Scoped multi-door evidence summary |

## 9. Security findings

- Fixed: Building Admin could target an employee from another building through biometric provisioning and sync-status direct IDs while selecting an in-scope door. Employee policy authorization now runs before provisioning, queueing, assignment creation, or status serialization.
- Verified: audit/system-account payloads redact credentials, tokens, hashes, card identifiers, and biometric context.
- Verified: webhook authentication fails closed for invalid credentials, wrong source, wrong door binding, and wildcard web tokens.
- Verified: simulator and pull sync cannot fake physical webhook health.
- Verified: no `dd()`, `dump()`, or `var_dump()` production path found in reviewed scope.
- Known production capability: explicit remote unlock and biometric provisioning endpoints can write to devices when authorized. They were not called during local review and require controlled office validation.

## 10. Known limitations

- Audit records lack universal canonical building ownership; global audit remains super-admin-only.
- Account lifecycle status and last-login timestamps are not backed by schema; UI reports them as unsupported/`PLANNED` rather than fabricating values.
- Legacy records without canonical building IDs depend on controlled text fallback.
- Physical device behavior, reverse-proxy SSE, production queue workers, and webhook ingress cannot be proven from home.

## 11. OFFICE-ONLY VERIFICATION PENDING

- Production server reachability and current runtime state.
- Physical Hikvision authentication and read-only UserInfo/CardInfo/event reads.
- Physical webhook delivery, authentication, source, and freshness transition.
- One controlled physical attendance event and duplicate prevention.
- Production reverse-proxy SSE streaming and reconnect behavior.
- Production environment/config parity, queue worker behavior, and device-write controls.
- Production backup readability and rollback point.

## 12. Production risks

- Device writes are irreversible external side effects; require named operator, selected test identity/door, and explicit GO decision.
- Schema/config drift may change scope or runtime behavior.
- Webhook proxy headers and source IP handling must match trusted production topology.
- Queue workers may execute pending device jobs after deployment; inspect queues before enabling workers.
- DB rollback may lose post-deploy business data; prefer application rollback unless migration incompatibility requires a reviewed DB restore.

## 13. Rollback plan summary

Do not execute these steps outside approved office change window.

1. Record current production branch, commit, image digest, running containers, volumes, queue depth, and migration status.
2. Create immutable pre-deploy tag from captured production commit.
3. Back up database before migration or deployment; verify backup can be listed/read in separate safe process.
4. Record candidate commit and built image digest.
5. Deploy without destroying containers/volumes; run authentication, API, DB, queue, SSE, webhook, scope, and health checks.
6. On application failure, restore previous image/commit while preserving database and volumes.
7. Decide DB rollback separately: restore only when migration is incompatible and impact/data-loss review approves it.
8. Re-run health/security checks after rollback and preserve logs/evidence.

Prohibited: `docker compose down`, `docker system prune`, `docker image prune`, `docker volume prune`, `php artisan migrate:fresh`, `php artisan db:wipe`, and force-push to `main`.

## 14. Phase 17 readiness

Local code, tests, static checks, isolated browser checks, candidate diff, and protected Hikvision service identity must pass before office work begins. Office execution checklist: `docs/PHASE_17_OFFICE_VERIFICATION_CHECKLIST.md`.
