# Card Access — Backend Contract Lock v1.1 (read-only) · Frontend binding

Approved by ISHAK, 07 Oct 2026. Scope: **Overview, Cards, Employee Detail** (read-only).
Mutation, Replace/Revoke, NFC enrollment and physical sync actions stay locked until the next Contract Lock.

## Endpoints bound

| UI | Request | Permission |
|---|---|---|
| Overview KPI + Sync Health | `GET /api/v1/card-access/overview` | `credential.view` |
| Recent Card Activity | `GET /api/v1/card-access/activity?limit=` | `credential.view` |
| Cards list | `GET /api/v1/card-access/cards?search=&building_id=&lifecycle_state=&sync_status=&verification=&page=&per_page=` | `credential.view` |
| Perlu Tindakan (attention queue) | `GET /api/v1/card-access/cards?attention=1&page=1&per_page=10` | `credential.view` |
| Employee Access Profile | `GET /api/v1/card-access/employees/{employee}` | `credential.view` |
| Audit History | `GET /api/v1/card-access/employees/{employee}/audit` | `audit.view` |
| Building filter | `GET /api/v1/admin/buildings` (existing) | `organization.view` — filter is hidden on 403 |

All requests go through the dashboard's `apiFetch` (Bearer token, 401/403/429 handling). The module issues
GET requests only; `CardAccessPreviewTest` fails if a request method, `fetch(` or `apiFetch(` appears in it.

## Contract rules applied

- Lifecycle status comes from backend: `ACTIVE_SYNCED | ACTIVE_NOT_SYNCED | CARD_NOT_REGISTERED | NEEDS_VERIFICATION | DISABLED | REVOKED`.
- KPI **Active = ACTIVE_SYNCED only**; the KPI tile links to the Cards list filtered by `lifecycle_state=ACTIVE_SYNCED`.
- KPIs, totals and pagination are backend-calculated; the frontend never counts rows.
- Only `masked_identifier` is used; search accepts last-4 digits and is sent to the server.
- `person_match / credential_match / access_match` ∈ `MATCH | MISMATCH | MISSING | UNKNOWN | PENDING`.
- `device.sync_status` ∈ `SYNCED | SYNCING | PENDING | FAILED | PARTIAL | UNKNOWN` (write to device).
- `verification` ∈ `VERIFIED | OUT_OF_SYNC | NEEDS_VERIFICATION | DISABLED | REVOKED | NOT_VERIFIED` (physical confirmation), shown separately from sync.
- `credential.origin` ∈ `CREDENTIAL_RECORD | LEGACY_EMPLOYEE_CARD | NFC_ENROLLMENT`; all are valid existing credentials, none prompts re-enrollment.
- Unknown fields arrive as `null`; `normalizeRecord()` turns null arrays into `[]` and null states into `UNKNOWN` / `NOT_VERIFIED` so nothing crashes.
- The display guard still never shows a record as ACTIVE · SYNCED when its own match fields or verification disagree.

## Assumptions to confirm with ISHAK (response envelope)

The approval fixed the endpoints and values but not every envelope detail. The adapter accepts both forms below:

1. **Overview**: `{ data: { kpis: {…}, sync_health: { items: [{ key, count }] }, as_of } }`. Flat KPI keys under `data`, or `sync_health` as a bare array, also work.
   KPI keys: `total_cards, active_cards, pending_sync, sync_failed, needs_verification, disabled_cards`.
   Health keys: `HEALTHY, WARNING, OFFLINE, SYNC_FAILED, NEEDS_VERIFICATION`.
2. **Cards meta**: `meta.page | meta.current_page`, `meta.per_page`, `meta.total`.
3. **Audit items**: `{ at | created_at, title | action | type, by | operator | actor, tone? }`.
4. **Activity items**: `{ at, type, employee, operator, target, result }`.

## Locked in this binding

Edit Access, Replace, Re-Sync, Verify, Disable, Revoke and Daftarkan Kartu NFC are visible but disabled, with the tooltip
"Menunggu Contract Lock berikutnya". The Access Permissions and Device Sync tabs show the "Menunggu Backend Contract
Lock" state. Diagnostics stays off as well (no contract yet).

## Enabling

`CARD_ACCESS_UI=api` in `.env` turns on the read-only binding (any environment). `preview` (fixtures) is only accepted in
`local`/`testing`. If the variable is empty, local/testing default to `preview` and every other environment renders nothing.
Enable `api` only after the backend endpoints are deployed.
