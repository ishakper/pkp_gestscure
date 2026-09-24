# Button Functional Acceptance — Evidence Matrix

**Date:** 2026-09-21 (Asia/Jakarta)
**Environment:** Isolated Docker UAT; authenticated super-admin session; Hikvision mock mode enabled

## Counting Rule

Each row below is one distinct control scenario. It may aggregate a defined control group (for example the ten primary navigation destinations or five access sub-tabs). Counts are not raw DOM-element totals. A row is `PASS` only when the UI state change occurred and any expected request completed without a fatal console error.

## Evidence Matrix

| # | Module | Control | Expected behavior | Actual behavior | Network result | Console error | Result |
|---:|---|---|---|---|---|---|---|
| 1 | Login | Submit valid UAT credentials | Open authenticated dashboard | Dashboard rendered | Authentication/navigation succeeded | None | PASS |
| 2 | Shell | Sidebar collapse/expand | Toggle navigation state twice | Body state changed open/closed correctly | N/A — client state | None | PASS |
| 3 | Shell | Primary navigation (10 destinations) | Activate each requested module and change visible content | Correct tab and module heading rendered for every destination | Required module APIs returned 200 | None | PASS |
| 4 | Dashboard | Refresh Live Data | Reload current operational summaries | Cards/tables refreshed | Doors, logs, activity, dashboard and attendance metrics returned 200 | None | PASS |
| 5 | Dashboard | Access-log filter | Reduce displayed rows to matching data | Filtered result displayed | Access-log API returned 200 | None | PASS |
| 6 | Dashboard | Reset access-log filters | Clear filters and reload | Default filter state restored | Access-log API returned 200 | None | PASS |
| 7 | Dashboard | Employee search | Show matching employee | Matching row for Budi displayed | Employee data already loaded / filtered safely | None | PASS |
| 8 | Dashboard | Add Employee modal open/close | Display modal without submitting | Modal opened and closed | N/A — no mutation | None | PASS |
| 9 | Pengguna | Required-form validation | Block an incomplete employee submit | Form stayed open and no write request was sent | No employee write request | None | PASS |
| 10 | Perangkat | Refresh | Reload device data | Device view refreshed | Operational APIs returned 200 | None | PASS |
| 11 | Perangkat | Diagnose first door | Run safe mocked connection check | Mocked diagnose completed | `check-connection` returned 200 | None | PASS |
| 12 | Perangkat | Remote Unlock | Require guarded device action | Not triggered; physical write intentionally gated | No request sent | None | GATED_PHYSICAL_ACTION |
| 13 | Hak Akses | Five access sub-tabs | Activate Requests, Profiles, Credentials, ISAPI Queue, E-Money | Each requested sub-tab became active | Data APIs returned 200 | None | PASS |
| 14 | Hak Akses | Refresh | Reload provisioning data | Tables and metrics refreshed | Access endpoints returned 200 | None | PASS |
| 15 | Hak Akses | Access-request modal/profile selector/close | Open top-level modal, change reusable profile, close | Modal visible; 8 options; handler callable; modal closed | Profile/employee endpoints returned 200 | None | PASS |
| 16 | Rekap Kehadiran | Refresh | Reload attendance views | Empty/data state rendered without breaking | Records, metrics, lookup, and report APIs returned 200 | None | PASS |
| 17 | Log Akses | Filters | Apply access-log criteria | Matching history reloaded | Access-log API returned 200 | None | PASS |
| 18 | Log Akses | Reset filters | Restore defaults | Default history reloaded | Access-log API returned 200 | None | PASS |
| 19 | Audit Log | Refresh Audit | Reload administrative timeline | Timeline rendered | Activity-log API returned 200 | None | PASS |
| 20 | Buildings | Refresh Hierarchy | Reload building/door structure | Four building headings rendered | Building/door APIs returned 200 | None | PASS |
| 21 | Buildings | Add Building modal open/close | Display top-level modal without mutation | Modal opened visibly and closed | N/A — no mutation | None | PASS |
| 22 | System Status | Refresh Status | Reload evidence-based health state | Cards refreshed; app remained truthfully `DEGRADED` | Health API returned 200 | None | PASS |
| 23 | Session | Logout | End session and return to login | Login page displayed | Logout navigation succeeded | None | PASS |
| 24 | Pengguna | Pagination next/previous | Change employee page | Only one seeded page exists; controls cannot exercise page transition | N/A | None | NOT_TESTED |
| 25 | Hak Akses | Submit access request/profile/credential | Persist a business mutation | Modal/validation path inspected; no mutation submitted | No write request sent | None | NOT_TESTED |
| 26 | Buildings | Submit building/zone change | Persist hierarchy mutation | Modal path inspected; no mutation submitted | No write request sent | None | NOT_TESTED |

## Defects Found and Retested

| Defect | Correction | Retest |
|---|---|---|
| `loadAccessLogs`, `loadAttendanceData`, `loadActivityLogs`, and other async inline handlers were block-scoped | Added an explicit public handler registry; added the missing profile-selection handler | PASS |
| Modal launchers called an undefined `openModal` helper | Added the shared helper beside `closeModal` | PASS |
| Access/building and later modals were nested under hidden `modalVerifyDocument` | Closed the missing overlay boundary in dashboard markup | PASS |

Static audit after correction reports **0 missing async handler exports** and **0 undeclared handler calls**. The access-request and add-building overlays were verified as top-level DOM overlays, visible after click, and closed by their own controls.

## Responsive Control Evidence

| Viewport | Sidebar/navigation | Tables/cards/forms | Modal fit | Global overflow | Console/API failure | Result |
|---|---|---|---|---|---|---|
| 1440×900 | Visible and functional | Rendered across all modules | Within viewport | None | None | PASS |
| 768×1024 | Toggle and auto-close functional | Rendered across all modules | Within viewport | None | None | PASS |
| 390×844 | Toggle and auto-close functional | Rendered across all modules | Within viewport | None | None in isolated rerun | PASS |

## Totals

- `BUTTONS_TESTED=22`
- `BUTTONS_PASS=22`
- `BUTTONS_FAIL=0`
- `BUTTONS_NOT_TESTED=3`
- `PHYSICAL_ACTIONS_GATED=1`

Physical remote unlock was not executed. Stakeholder acceptance remains **PENDING**.
