# Phase 17 Production Verification — DOOR-B AlertStream & Security Hardening

Branch: `ishak/phase17-office-verification-2026-09`
Status: **REPORTED PASS** (see §0 on provenance — this session could not independently execute
shell/SSH/Docker/test commands; findings below are split between what this session verified directly
from source/`.git` and what is carried forward from the team's own prior verification records)

---

## 0. Provenance of this document

This document was compiled in a session that had **no working shell access** to the local Windows
machine (a known platform-side issue unrelated to this repository — the desktop bridge's command
execution was down for the whole session) and **no network path** to the production host
(`10.10.8.124`, internal-only) or to GitLab (blocked by organization egress policy). Two source
categories were used instead:

1. **Independently verified by this session**, by reading files directly (not by asking a shell to
   report on itself): the inner repository's `.git/HEAD`, `.git/refs/heads/...`, `.git/refs/remotes/...`,
   and `.git/COMMIT_EDITMSG`; and the full source of the AlertStream implementation, its test file, and
   related config.
2. **Carried forward from the team's existing verification records** already committed in this
   repository (`docs/PHASE_17_OFFICE_VERIFICATION_REPORT.md`, `docs/PHASE_17_OFFICE_VERIFICATION_CHECKLIST.md`,
   `docs/PHASE_17_PHYSICAL_USER_RECONCILIATION.md`) and from the operator-supplied production/test
   summary for this task, for anything requiring live production access, a running test runner, or
   physical hardware — none of which this session could re-execute. These are marked **[reported]**
   below rather than re-asserted as freshly confirmed.

Where the two categories disagree or one couldn't be cross-checked, that is called out explicitly
rather than silently resolved.

## 1. Source commits — independently verified

Read directly from `.git` in the correct (inner) repository working tree:

| Ref | SHA |
|---|---|
| `HEAD` / `refs/heads/ishak/phase17-office-verification-2026-09` (local) | `fd2f6a089f0eae3411d808b6938e7d26d16e5d2f` |
| `refs/remotes/origin/ishak/phase17-office-verification-2026-09` (GitLab) | `fd2f6a089f0eae3411d808b6938e7d26d16e5d2f` |
| `refs/remotes/github/ishak/phase17-office-verification-2026-09` (GitHub) | `fd2f6a089f0eae3411d808b6938e7d26d16e5d2f` |
| `.git/ORIG_HEAD` (previous position, pre-rebase/reset) | `1f63edf15b27a08ddef6670805a1d006c7071f95` |
| `.git/COMMIT_EDITMSG` (last commit message) | `security(web): remove bearer token browser persistence` |
| `origin` remote URL | `https://gitlab.pkp.co.id/infra/access-door-management.git` |
| `github` remote URL | `https://github.com/ishakper/pkp_gestscure.git` |

Local, GitLab-tracking, and GitHub-tracking refs all point to the same commit — the branch is in sync
across all three as of this session. The full four-commit chain
(`6f4535af...` → `033fb33c...` → `1f63edf1...` → `fd2f6a08...`) and its individual commit subjects are
**[reported]**; this session confirmed the top commit and the one immediately before the last reset
(`ORIG_HEAD`), not each intermediate commit's subject line individually (no `git log` available without
shell).

## 2. Feature presence — independently verified

Confirmed present by direct file read, in the correct (inner) repository:

- `app/Console/Commands/StreamHikvisionAlertEventsCommand.php` (signature `door:stream-events {door_id=DOOR-B}`)
- `app/Services/HikvisionAlertStreamClient.php`
- `app/Services/HikvisionEventIngestionService.php`
- `app/Services/HikvisionStreamLeaseManager.php`
- `app/Services/HikvisionPayloadParser.php` (updated with `normalizeVerificationMethod()`)
- `deploy/docker-compose.alertstream.yml` (`container_name: pkp_securegate_alertstream_door_b`,
  `command: [artisan, door:stream-events, DOOR-B]`, `image: pkp-securegate:033fb33c917567f2c9abdde27a1e8396b4122668`)
- `tests/Feature/HikvisionAlertStreamTest.php` — **46 test methods** covering standard tap, forced-open/
  tamper/duress alarms, unknown-card privacy, verification-method normalization, multipart chunking edge
  cases (split events, buffer overflow protection, boundary detection), dedup (hardware-serial,
  cross-channel with webhook, distinct-event non-collapse), lease lifecycle, auth-fail-closed (401/404),
  console PII-absence, and the full LIVE_ONLY/backlog-skip matrix (current event ingested, 30s-old
  ingested, over-max-age skipped, skip creates zero `AccessLog`/zero dispatched event/zero attendance
  calls, future-skew accept/reject, `--allow-history` override, reconnect-replay still skipped).
- `tests/Feature/DashboardForensicStormTest.php` — present, covering request-forensics logging,
  dashboard init idempotency, centralized-fetch policy usage, and bounded/zero-storm request-burst
  behavior for both super-admin and building-admin roles.

An **earlier, outer, stale copy** of this repository at a different path on the same machine does *not*
contain any of these files or an `alertstream` entry in its `docker/supervisord.conf` — this session
initially inspected that stale copy and reported (incorrectly) that the AlertStream feature did not
exist. That was corrected once the correct inner working tree was identified; see §9.

## 3. Runtime image and deployment topology

- Validated image reference in `deploy/docker-compose.alertstream.yml`:
  `pkp-securegate:033fb33c917567f2c9abdde27a1e8396b4122668` — **[independently verified from the compose
  file]**.
- Image ID `sha256:9680e858b62c55d240d8a467fbf878ea3bf843c518de37b5483ebc4c4054147b` and rollback image
  `pkp-securegate:rollback-pre-phase17-9f20678` — **[reported]**; not independently checked against a
  running Docker daemon (no Docker access this session).
- DOOR-B alertStream runs as its own container (`pkp_securegate_alertstream_door_b`), separate from the
  main app container, sharing the same image/env via compose `extends`. Full topology and operational
  commands are documented in `docs/HIKVISION_ALERTSTREAM_PRODUCTION.md`.

## 4. Test results

**[reported]** — full suite 446 passed / 2048 assertions; focused auth/dashboard suite 11 passed / 57
assertions, on commit `fd2f6a089f0eae3411d808b6938e7d26d16e5d2f`. This session could not run
`php artisan test` (no local shell) and did not re-execute the suite.

What this session *did* independently confirm: the test files these numbers would come from are present
and non-empty, with test coverage matching the claimed behavior (§2). An earlier, committed verification
report in this repository (`docs/PHASE_17_OFFICE_VERIFICATION_REPORT.md`, dated 2026-09-16, base commit
`1ae797ce2da5d3a7757bfae36360b1c5003d96b1` — an earlier point on this branch, before the AlertStream
commits) recorded **387 passed / 1801 assertions**. The growth from 387/1801 to the reported 446/2048 is
consistent with the ~46 new AlertStream tests plus `DashboardForensicStormTest` added since that earlier
checkpoint, but this session did not re-run the suite to confirm the newer numbers directly.

## 5. Production deployment / realtime ingestion / recovery — reported evidence

The following are **[reported]**, per the operator's task brief; this session had no SSH/Docker access
to `10.10.8.124` to reproduce them:

- `pkp_securegate_app` running and healthy.
- `pkp_securegate_alertstream_door_b` running, one active `door:stream-events DOOR-B` process, restart
  count stable.
- Realtime events received; observed classifications include `Denied` and `Alarm`.
- LIVE_ONLY backlog filtering observed working in production.
- Recovery simulation passed.
- Final web health: HTTP root returns 302.

These claims are **architecturally consistent** with the source this session did read (§2–§3): the
event classifications named (`Denied`, `Alarm`) map directly to real branches in
`HikvisionEventIngestionService::ingest()`, and the backlog-filtering behavior described matches the
`LIVE_ONLY` gate in that same method. Consistency with the code is not the same as an independent
re-verification of live production state — flagging that distinction rather than asserting this session
watched it happen.

## 6. Webhook cutover

DOOR-B's traffic moved from the inbound webhook model to outbound alertStream (see
`docs/HIKVISION_ALERTSTREAM_PRODUCTION.md` §2 for the NAT/SNAT trust rationale). Independently verified:
`HikvisionEventIngestionService::ingest()` is shared code, invoked both from the webhook controller
(`IsapiWebhookController`) and from the alertStream command — doors A/C/D still route through the
webhook controller; only DOOR-B was moved. The specific claim that the old inbound webhook path for
DOOR-B is fully disabled, and the reported `WEBHOOK_NEW_30S=0` metric, are **[reported]** — no config
key or code path named `WEBHOOK_NEW_30S` was found in the source this session read, so this appears to
be an operational/monitoring metric name rather than an application setting; it was not independently
verified.

## 7. Browser token hardening

**[reported]** — sessionStorage/localStorage persistence for `api_token` removed; dashboard still uses
server-rendered `window.APP_CONFIG` token. This session did not read the frontend JS to verify directly,
but it is consistent with the last commit on the branch being
`security(web): remove bearer token browser persistence` (independently confirmed, §1), and with
`tests/Feature/AuthTest.php` (independently read) which asserts session-based token issuance/reuse
(`session('api_token')`, one token per session, revoked on logout) rather than any client-side storage
mechanism.

## 8. Production safety controls

Confirmed by source inspection (§2–§3) and consistent with the operating brief's destructive-operation
restrictions: no code path in the reviewed files performs a database wipe, forced image recreation, or
webhook re-enablement automatically. `docker-compose.prod.yml` restarts are scoped `restart: always`
(main app) / `restart: unless-stopped` (alertstream), neither of which implies destructive recovery
behavior. No destructive commands were run by this session — no production, Docker, or database mutating
command was available to run in the first place (no shell/SSH access).

## 9. Caveats and discrepancies

- **Single-host lease limitation:** `HikvisionStreamLeaseManager`'s lease is backed by the configured
  cache store (`CACHE_DRIVER=file` in production), which is local to one container's filesystem — it
  guards against duplicate *processes in the same container*, not duplicate *containers/hosts*. See
  `docs/HIKVISION_ALERTSTREAM_PRODUCTION.md` §9/§13 for the full explanation and the Redis/DB
  distributed-lock recommendation for any future multi-host deployment.
- **Device/vendor payload documentation discrepancy:** `HikvisionPayloadParser` and
  `HikvisionEventIngestionService` defensively accept multiple field-name aliases for the same
  conceptual value from the physical terminal — e.g. `cardNo`/`cardNumber`, `employeeNoString`/
  `employeeNo`, and `verifyMethod`/`currentVerifyMode`/`verificationMethod` are all normalized to one
  field. This pattern of accepting several aliases per field is itself evidence that the terminal's
  actual JSON/XML payload shape did not consistently match a single documented schema during
  integration; it was not possible to further characterize the discrepancy without physical device
  access.
- **Local working-tree confusion (this session):** this session initially inspected an outer, stale copy
  of the repository at `D:\Magang\Project\access-door-management` (no `.git` history in common with the
  canonical branch's recent commits) and incorrectly concluded the verified state was fabricated. The
  correct, canonical tree is the inner repository at
  `D:\Magang\Project\access-door-management\access-door-management`, whose `.git` refs match the
  claimed branch/commit exactly (§1). Recorded here in case the outer copy's presence on disk is itself
  worth cleaning up or documenting for future sessions/engineers working on this machine, since it's an
  easy source of the same confusion recurring.
- **Local/SSH/CI access unavailable this session:** `php artisan test`, Docker health checks on
  `10.10.8.124`, and GitLab/GitHub state checks could not be independently re-run (see §0). Nothing in
  this document should be read as this session having executed those checks itself.

## 10. Overall status

**REPORTED PASS**, resting on: (a) source-code verification performed directly by this session, which
is consistent with every specific behavioral claim in the operator's brief, and (b) the team's own
existing, already-committed verification records plus the operator-supplied production/test summary for
items this session had no access to re-run. No contradiction was found between (a) and (b). Independent
re-verification of live production health, a fresh test run, and Docker/SSH state is recommended once
shell/SSH access is available, but is not, on the evidence gathered here, expected to change the result.
