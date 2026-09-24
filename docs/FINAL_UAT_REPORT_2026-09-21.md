# Final UAT Verification Report — PKP SecureGate

**Date:** 2026-09-21 (Asia/Jakarta)
**Branch:** `ishak/full-functional-integration-2026-09`
**Pre-UAT baseline:** `38afc91a0235aafbf0bc8da442f8017e957c5c28`

## Executive Summary

Local integration validation is **PASS**. A clean verification image completed **509 tests, 2,292 assertions, 0 failures**. The frontend production build completed successfully. Authenticated Edge UAT exercised login, all ten authenticated primary modules, safe controls, a mocked device-diagnose action, and the required desktop, tablet, and mobile viewports.

Browser UAT found and corrected three related UI defects:

- async inline handlers were block-scoped by the dashboard initialization guard;
- the shared `openModal` helper was missing;
- `modalVerifyDocument` lacked a closing overlay tag, nesting later modals inside a hidden parent.

The corrections are limited to dashboard JavaScript/markup and do not change the Hikvision event, webhook, attendance, device credential, network, or production deployment paths.

## Environment and Safety

| Item | Evidence | Result |
|---|---|---|
| UAT endpoint | `http://localhost:8080` | PASS |
| Container | `pkp_securegate_app`, healthy | PASS |
| Effective environment | testing; isolated `database/uat.sqlite` | PASS |
| Hikvision mode | application and ISAPI mock flags enabled | PASS |
| Physical Hikvision writes | Not executed | GATED_PHYSICAL_ACTION |
| Production server/container | Not accessed or modified | NOT_TESTED |

`docker-compose.uat.yml` is classified **COMMIT_SAFE**. It is not an automatically loaded override. Default `docker compose config` retains production defaults; UAT/test settings apply only when the file is explicitly supplied with `-f docker-compose.uat.yml`.

## Automated Regression

The date-dependent `FieldAttendanceTest` fixture now fixes Carbon time before creating fixtures and clears it during teardown. No application behavior was weakened to make the test pass.

```powershell
docker compose -f docker-compose.yml -f docker-compose.uat.yml --profile test build test
docker compose -f docker-compose.yml -f docker-compose.uat.yml --profile test run --rm test
```

| Layer | Result |
|---|---|
| Clean verification image build | PASS |
| Full PHPUnit suite | PASS |
| Tests | 509 |
| Assertions | 2,292 |
| Failures | 0 |

## Frontend and Static Validation

| Check | Evidence | Result |
|---|---|---|
| `npm ci` | 120 packages installed | PASS |
| `npm run build` | Vite 5.4.21; 58 modules transformed | PASS |
| Production dependency audit | 0 production vulnerabilities | PASS |
| Development dependency audit | 2 advisories (1 moderate, 1 high) | PARTIAL |
| Dashboard JavaScript syntax | `node --check` | PASS |
| Inline-handler audit | 0 missing async exports; 0 undeclared handler calls | PASS |

Generated `public/build` assets remain ignored and are not intended for this commit.

## Authenticated Browser UAT

Real Edge interactions were used. Page changes were asserted from visible module content, relevant API responses, and console capture; HTTP reachability alone was not treated as acceptance evidence.

| Module | Visible evidence | Network/console evidence | Result |
|---|---|---|---|
| Login | Authenticated dashboard displayed | Successful navigation; no fatal console error | PASS |
| Dashboard | Activity, device status, and user-summary content | Dashboard, door, employee, log, and attendance APIs returned 200 | PASS |
| Pengguna | `Manajemen Pengguna` table and controls | Employee API returned 200; no console error | PASS |
| Perangkat | `Monitoring Perangkat Pintu` cards/actions | Door API returned 200; mocked diagnose returned 200 | PASS |
| Hak Akses | Provisioning, profile, credential, sync, and E-Money tabs | All access APIs returned 200; no console error | PASS |
| Rekap Kehadiran | Attendance/calendar content and refresh | Records, metrics, organization, and monthly-report APIs returned 200 | PASS |
| Log Akses | Full access-log history and filters | Access-log API returned 200; no console error | PASS |
| Audit Log | Structured activity timeline | Activity-log API returned 200; no console error | PASS |
| Buildings | Building hierarchy and safe modal controls | Door/building APIs returned 200; no console error | PASS |
| System Account | Read-only `PLANNED` lifecycle content rendered | System-account API returned 200; no console error | PASS |
| System Status | Health cards rendered and refresh worked | Health API returned 200; displayed application state `DEGRADED` | PASS |

`System Status` is a UI-function result: the module and refresh control pass, while the local UAT health payload truthfully reports `DEGRADED`. No `ONLINE` state is inferred from that response.

## Responsive UAT

| Viewport | Coverage | Result |
|---|---|---|
| Desktop 1440×900 | Ten authenticated modules, navigation, tables/cards, controls, employee modal | PASS |
| Tablet 768×1024 | Sidebar open/close, ten modules, title bounds, global overflow, modal fit | PASS |
| Mobile 390×844 | Sidebar auto-close, ten modules, title bounds, global overflow, modal fit | PASS |

All three viewports showed no material page-level horizontal overflow or title clipping. Table overflow remained contained by table wrappers. A long combined mobile sweep once emitted browser resource exhaustion (`ERR_NO_BUFFER_SPACE`); an isolated full mobile rerun completed with no console error or failed API request, so this was not classified as an application failure.

## Button/Action Acceptance

The detailed evidence is in `docs/BUTTON_FUNCTIONAL_ACCEPTANCE_2026-09-21.md`.

| Metric | Count |
|---|---:|
| Executed control scenarios | 22 |
| PASS | 22 |
| FAIL | 0 |
| NOT_TESTED | 3 |
| GATED_PHYSICAL_ACTION | 1 |

Counts represent explicit scenario rows, not raw DOM button counts. Destructive business mutations were not submitted merely to inflate coverage.

## Acceptance Boundaries

| Layer | Result |
|---|---|
| AUTOMATED REGRESSION | PASS |
| BROWSER UAT | PASS |
| RESPONSIVE UAT | PASS |
| PHYSICAL DEVICE UAT | NOT_TESTED |
| SERVER READ-ONLY VERIFICATION | NOT_TESTED |
| STAKEHOLDER ACCEPTANCE | PENDING |

Physical remote unlock remained gated. No production host, production container, production database, network configuration, device credential, webhook secret, firmware, EHome, or ISAPI setting was changed.

## Local Verdict

**LOCAL VALIDATION = PASS**

Remote synchronization and CI status are recorded after the commit/push phase; stakeholder acceptance remains **PENDING**.
