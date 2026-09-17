# Hikvision AlertStream — Production Operations Guide

Scope: outbound alertStream ingestion for `DOOR-B` (Hikvision DS-K1T804AMF, `192.168.90.15`).
Audience: engineers operating or troubleshooting the PKP SecureGate production deployment.

This document describes the *outbound* alertStream ingestion path added for DOOR-B. Doors A, C and D
still use the original inbound webhook path (`POST /api/v1/isapi/event-notification`, handled by
`IsapiWebhookController` + `VerifyDeviceWebhook`); that path is unchanged and still active for those
terminals. AlertStream is a DOOR-B-specific replacement, not a system-wide cutover.

---

## 1. Architecture

```
┌─────────────────────┐   outbound HTTP Digest    ┌──────────────────────────────┐
│ Hikvision DS-K1T804  │ ───────────────────────▶ │ php artisan door:stream-events│
│ AMF (DOOR-B)         │  GET /ISAPI/Event/        │ (StreamHikvisionAlertEvents   │
│ 192.168.90.15:80     │  notification/alertStream │  Command)                     │
└─────────────────────┘  multipart/mixed, chunked  └──────────────┬───────────────┘
                                                                    │
                                     HikvisionAlertStreamClient     │ parses MIME parts
                                     (cURL, CURLAUTH_DIGEST,        ▼
                                      streaming write-callback)   HikvisionPayloadParser
                                                                    │ normalizes JSON/XML
                                                                    ▼
                                                     HikvisionEventIngestionService::ingest()
                                                       - LIVE_ONLY freshness gate
                                                       - event classification
                                                       - dedup
                                                       - AccessLog::create()
                                                                    │
                                                                    ▼
                                                        AccessLogCreated event
                                                    (attendance processing, dashboard SSE)
```

Key classes (`app/Console/Commands/StreamHikvisionAlertEventsCommand.php`,
`app/Services/HikvisionAlertStreamClient.php`, `HikvisionEventIngestionService.php`,
`HikvisionStreamLeaseManager.php`, `HikvisionPayloadParser.php`):

- **`StreamHikvisionAlertEventsCommand`** (`door:stream-events {door_id=DOOR-B}`) — the long-running
  daemon entrypoint. Acquires a lease, opens the stream, reconnects with exponential backoff
  (`2, 4, 8, 16, 30, 60` seconds) on drop, and reports structured health to cache on every
  connect/disconnect/tick.
- **`HikvisionAlertStreamClient`** — opens a raw cURL connection with Digest auth and parses the
  `multipart/mixed` chunked body incrementally (1 MB buffer / part caps, boundary auto-detected from
  `Content-Type`), so it never has to buffer the whole (indefinite) stream in memory.
- **`HikvisionPayloadParser`** — normalizes JSON or XML event bodies into one canonical shape and
  normalizes vendor verification-method strings (`fingerprint`, `fp`, `card`, `face`, `pin`,
  `password`, multi-factor combinations) into fixed canonical values; anything undocumented becomes
  `UNKNOWN` rather than being guessed.
- **`HikvisionEventIngestionService::ingest()`** — shared by both the alertStream path and the legacy
  webhook path. Applies the LIVE_ONLY freshness gate, classifies the event (`STANDARD_TAP`,
  `DOOR_FORCED_OPEN`, `TAMPER_ALARM`, `DURESS_FINGERPRINT`), deduplicates, and writes `AccessLog`.
- **`HikvisionStreamLeaseManager`** — single-consumer lease so only one `door:stream-events` process
  per door is actively streaming at a time (see §9).

## 2. Why outbound HTTP Digest instead of the inbound webhook

The inbound webhook model requires the physical terminal to reach the application over its configured
`HIKVISION_LISTENER_IP:HIKVISION_LISTENER_PORT`. In the production network, that inbound path sits
behind shared NAT/SNAT, so the application cannot reliably distinguish "the real DOOR-B terminal" from
"anything else behind the same translated source address" — the inbound source IP is not trustworthy
as an identity signal. Doors A/C/D still use this model because it fits their network position, but it
was judged unsafe as the sole trust boundary for DOOR-B's traffic.

AlertStream flips the direction: the application (running inside the trusted network) makes an
**outbound** HTTP Digest-authenticated connection *to* the terminal
(`GET /ISAPI/Event/notification/alertStream`), authenticated with the door's own device credentials
(`DOOR_B_USER` / `DOOR_B_PASS`, resolved via `HikvisionIsapiService::getDeviceCredentials()`). Identity
is proven by the Digest credential exchange, not by source IP, so NAT/SNAT ambiguity is no longer a
trust question. The connection is long-lived and chunked, so the terminal pushes events over it as they
occur — functionally similar latency to a webhook, without depending on inbound reachability or
IP-based trust.

## 3. LIVE_ONLY mode and backlog filtering

By default the command runs in **`LIVE_ONLY`** mode (`--allow-history` switches to
`HISTORICAL_REPLAY`). In `HikvisionEventIngestionService::ingest()`, when `mode === 'LIVE_ONLY'`:

- Events older than `stream_max_event_age_seconds` (`config('services.hikvision.stream_max_event_age_seconds')`,
  default **120s**, env `HIKVISION_STREAM_MAX_EVENT_AGE_SECONDS`) are **skipped** with
  `reason_code = HISTORICAL_BACKLOG` and create no `AccessLog` row, no `AccessLogCreated` event, and no
  attendance-processor invocation.
- Events timestamped more than `stream_max_future_skew_seconds` (default **300s**, env
  `HIKVISION_STREAM_MAX_FUTURE_SKEW_SECONDS`) in the future are rejected as `INVALID_FUTURE_TIMESTAMP`.

This exists because a terminal that reconnects after downtime (or on daemon restart) can replay a
backlog of historical events over the same stream; without this gate, reconnecting would re-ingest
stale events as if they had just happened. The command tracks
`events_received / events_ingested / events_skipped_backlog / events_duplicates / events_invalid`
separately in its per-cycle summary and in the cached health record, so backlog-skips are visible
without being mistaken for ingestion failures.

## 4. Event classification

`HikvisionEventIngestionService` maps Hikvision `major`/`minor` event codes to:

| Type | Trigger | `access_status` |
|---|---|---|
| `STANDARD_TAP` | normal card/face/fingerprint/PIN tap | `Granted` / `Denied` (by employee match) |
| `DOOR_FORCED_OPEN` | minor 21/22 under major 5 | `Alarm` |
| `TAMPER_ALARM` | minor 37/38 under major 5 | `Alarm` |
| `DURESS_FINGERPRINT` | minor 26/27 under major 5 | `Duress` |

A stream event with no card/employee identity and no alarm classification (e.g. a heartbeat-shaped or
structurally empty part) is rejected as `ignored` before it reaches the freshness gate — defense in
depth against malformed or non-access multipart parts.

## 5. Privacy

- Console/log output for ingested events never includes employee name or card number — only door ID,
  access status, and the generated `log_id`.
- Raw card numbers are never persisted verbatim into `nik`/log display fields; `AccessLog.nik` stores
  either the resolved employee's own NIK or a sanitized non-card identifier.
- `HikvisionPayloadParser` does not propagate raw device payloads outward; only normalized fields are
  passed to the ingestion service.

## 6. Deployment: supervisor / container

DOOR-B's stream runs as its **own container**, not as a `supervisord` program inside the main web
image. It is defined in `deploy/docker-compose.alertstream.yml`, which `extends` the `app` service from
the root `docker-compose.yml` (same image/env) but overrides the entrypoint to run the daemon directly:

```yaml
services:
  alertstream-door-b:
    extends:
      file: ../docker-compose.yml
      service: app
    container_name: pkp_securegate_alertstream_door_b
    entrypoint: [php]
    command: [artisan, door:stream-events, DOOR-B]
    ports: []
    healthcheck:
      disable: true
    restart: unless-stopped
```

**The compose project name must be set explicitly and must match the main stack's project** so the
container joins the same Docker network/project namespace as `pkp_securegate_app` (compose defaults the
project name to the current directory, and this file lives in `deploy/`, which would otherwise put it
in a *different* project from the main stack):

```bash
# Start (from the repository root, or pass -f with full paths from elsewhere)
docker compose -p access-door-management \
  -f docker-compose.yml -f deploy/docker-compose.alertstream.yml \
  up -d alertstream-door-b

# Status
docker compose -p access-door-management ps alertstream-door-b
docker inspect pkp_securegate_alertstream_door_b --format '{{.State.Status}} (restarts: {{.RestartCount}})'

# Logs
docker logs -f --tail 200 pkp_securegate_alertstream_door_b

# Stop (does not remove volumes/network — safe for the shared stack)
docker compose -p access-door-management stop alertstream-door-b
```

Do not use `docker compose down` for this — it can tear down the shared network/volumes the main app
container depends on. Use `stop`/`up` on the specific service.

## 7. Recovery behavior

- **Auth failure (HTTP 401/403):** command exits immediately with code `2` and health status
  `AUTH_FAILED`. It does **not** retry — a bad credential will not self-heal by reconnecting, and
  `restart: unless-stopped` will keep restarting the container, so a stuck `AUTH_FAILED` container
  restarting in a loop means the DOOR_B credentials need checking, not the network.
- **Unsupported endpoint (HTTP 404/405):** exits with code `3`, status `UNSUPPORTED`. Also non-retryable
  — indicates the terminal's firmware/ISAPI path doesn't expose `alertStream`.
  loop.
- **Any other disconnect** (network drop, terminal reboot, timeout): status `DISCONNECTED`, then the
  command reconnects internally using the backoff schedule in §1 — it does not rely on the container
  restarting for ordinary reconnects. The container only needs to restart if the *process* exits.
- **Graceful shutdown:** `SIGINT`/`SIGTERM` set a stop flag, so `docker compose stop` lets the current
  cycle finish, release the lease, and release the secondary `Cache::lock()`, before exiting 0.

## 8. Troubleshooting: restart-loop

Symptom: `docker inspect ... RestartCount` keeps climbing, container never stays up.

1. Check logs first (`docker logs --tail 100 pkp_securegate_alertstream_door_b`) for `AUTH_FAILED` or
   `UNSUPPORTED` — those are non-retryable by design (§7) and will loop forever under
   `restart: unless-stopped` until the underlying credential/firmware issue is fixed; the loop is a
   symptom, not the root cause.
2. If logs show `"Stream sudah aktif untuk pintu [DOOR-B]. Menolak duplikasi listener."`, see §9 —
   this is the duplicate-listener case, not a crash.
3. If logs show repeated `DISCONNECTED` with no `AUTH_FAILED`/`UNSUPPORTED`, this is expected
   self-healing reconnect behavior *inside* the process, not a container restart loop — confirm you're
   reading `RestartCount`, not counting internal reconnect log lines.

## 9. Troubleshooting: duplicate listener

`HikvisionStreamLeaseManager` enforces a single-consumer lease (`hikvision-alertstream:lease:DOOR-B`,
30s TTL, renewed every 5s) so a second `door:stream-events DOOR-B` invocation refuses to start
(`acquire()` returns `false`) and the command exits 0 without ever opening the physical connection.

**Important caveat:** the lease is stored via Laravel's configured cache store. Production sets
`CACHE_DRIVER=file`, and `docker-compose.prod.yml` does not mount `storage/framework/cache` as a shared
volume — so the file cache (and therefore the lease) is **local to each container's own filesystem**.
The lease reliably prevents two *processes inside the same container* from both streaming, but it does
**not** prevent two *separate containers* (e.g. an accidental second `alertstream-door-b` deployed
under a different compose project, or someone running `door:stream-events DOOR-B` manually on the main
app container) from each independently opening a connection to the terminal, since they don't share a
cache backend. See §11 for the fix.

If you see the container exiting 0 immediately and repeatedly (which under `restart: unless-stopped`
looks identical to a restart loop from the outside): check for a second container or process running
the same command (`docker ps -a --filter name=alertstream`) before assuming a code-level bug.

## 10. Troubleshooting: inbound webhook storm (doors A/C/D, and the pre-AlertStream DOOR-B path)

The inbound webhook route (`IsapiWebhookController::handleEventNotification`) is still live for doors
A, C and D. A "storm" here means abnormally high request volume against
`/api/v1/isapi/event-notification` — historically caused by a terminal retrying delivery when it
doesn't receive the response it expects, or by a device sending duplicate/backlog deliveries after
reconnecting.

Checks:
- Confirm which door is affected — this is not the alertStream path, so first rule out a mixup between
  DOOR-B (alertStream, outbound) and A/C/D (webhook, inbound).
- `VerifyDeviceWebhook` middleware validates the source against `ALLOWED_DEVICE_IPS` before the request
  reaches the controller — check whether requests are being rejected at that layer (which would show as
  4xx volume, not ingestion volume) versus actually reaching `ingest()`.
- `HikvisionEventIngestionService::ingest()` deduplicates on door + event type + (employee or sanitized
  identifier) + a ±3s timestamp window + device serial, within a 5-minute lookback — genuine retries of
  the *same* physical tap should collapse to one `AccessLog` row rather than one row per retry.
- `TestWebhookSmokeCommand` and `RegisterHikvisionWebhookCommandTest` exercise this path in the test
  suite and are the reference for expected request/response shape if you need to reproduce a storm
  synthetically rather than against the live terminal.

## 11. Rollback

- **AlertStream container only:** `docker compose -p access-door-management stop alertstream-door-b`
  stops DOOR-B ingestion without affecting the main app or other doors. DOOR-B simply stops receiving
  new access logs until restarted (no destructive state change — nothing to "undo").
- **Application image rollback:** the alertstream container's `image:` pin
  (`pkp-securegate:<commit-sha>`) should be moved back to a previously known-good image tag alongside
  the main app's rollback, not left pointing at a newer image while the main app rolls back — they
  should move together since they share the same codebase/image.
- Do not roll back by deleting the lease cache key or force-killing the container while the real
  terminal is mid-connection; prefer `stop` (SIGTERM, graceful — see §7) over `kill -9`/`docker rm -f`.

## 12. Disk pressure warning

Each `alertstream-door-b` container shares the same image as the main app container
(`pkp-securegate:<sha>`), so redeploying it does not multiply image storage — but pinning it to an
**explicit image tag** (rather than `:production`) means old tagged images accumulate on disk across
redeploys unless pruned deliberately. Before any image cleanup: confirm the currently running image, the
designated rollback image, and the candidate image are all preserved; do not run broad
`docker image prune -a` — identify and remove only specific superseded tags after confirming no running
or rollback-designated container references them.

## 13. Single-host lease limitation and HA recommendation

As described in §9, the current lease mechanism is backed by whatever `CACHE_DRIVER` is configured
(`file` in production), which is inherently local to one container/host. This is sufficient for the
current single-instance-per-door deployment model, but it means:

- It cannot prevent duplicate listeners across multiple hosts/containers.
- It cannot support a future horizontally-scaled or multi-host deployment of the same door's stream
  without risking two consumers both connecting to the same physical terminal.

**Recommendation:** for any future multi-host or HA deployment, move `HikvisionStreamLeaseManager`'s
backing cache store to a shared backend (Redis, or a dedicated database-backed lock table) so the lease
is visible across all hosts, not just within one container's filesystem. This is a backlog item, not a
current production defect — the current single-container-per-door topology does not require it, but any
change to that topology should not be made without making this change first.
