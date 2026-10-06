# PostgreSQL Phase 1 – Compatibility Report

Branch base: `integration/yazied-final-2026-09` @ `bcaf776`
Date: 2026-10-06
Scope: preparation only. No deploy, no production database access, no ISAPI writes,
no data rewrite, no identity normalization.

## Authoritative production baseline (SQLite)

`/home/infra/access-door-management/database/database.sqlite`

| table | rows |
|---|---|
| employees | 109 |
| buildings | 5 |
| doors | 4 |
| door_assignments | 98 |
| access_logs | 4095 |
| activity_logs | 231 |
| device_person_states | 99 |

Older snapshots (buildings=4, door_assignments=97) are superseded.
`php artisan db:verify-row-parity` (added in this series) checks these numbers on both sides:

```
php artisan db:verify-row-parity --source=sqlite --target=pgsql \
  --expect=employees=109 --expect=buildings=5 --expect=doors=4 \
  --expect=door_assignments=98 --expect=access_logs=4095 \
  --expect=activity_logs=231 --expect=device_person_states=99
```

## Test evidence

| suite | before (bcaf776) | after this series |
|---|---|---|
| SQLite (`phpunit.xml`, in-memory) | 832 tests, 0 failures | 845 tests, 0 failures |
| PostgreSQL 16 (`phpunit.pgsql.xml`) | 841 tests, 167 failing (122 errors, 45 failures) | 845 tests, 0 failures |
| `git diff --check` | – | clean |

PostgreSQL was a local PostgreSQL 16 server (same major as `postgres:16-alpine`).
`php artisan migrate` on an empty PostgreSQL 16 database completes for all 49 migrations.

## Findings

Legend: PASS, NEEDS_PATCH, BLOCKER, PRODUCTION_RISK. "Fixed" means fixed in this series.

### 1. SQLite-specific SQL (repository-wide search)

| item | where | class | status |
|---|---|---|---|
| `PRAGMA foreign_keys`, `AUTOINCREMENT`, table rebuild | `2026_09_09_171100_normalize_admin_roles` | BLOCKER | Fixed: SQLite path unchanged; on pgsql drops `admins_role_check` instead |
| SQLite rebuild with `"STANDARD_TAP"` quoting and swallowed `DROP INDEX` (aborts the pg transaction, SQLSTATE 25P02) | `2026_09_07_000002_recreate_access_logs_table_without_enum_checks` | BLOCKER | Fixed: skipped on non-SQLite; pgsql gets the `event_type` index directly |
| `PRAGMA query_only`, `sqlite_master` | `VerifyRowParityCommand` (new, read-only, driver-switched) | PASS | – |
| `sqlite_sequence`, `strftime(`, `datetime(`, `julianday(`, `json_extract(`, `COLLATE NOCASE`, `INSERT OR IGNORE/REPLACE`, `group_concat` | none found in `app/`, `routes/`, `config/`, `database/` | PASS | – |
| `DB::statement` upsert `value = value + excluded.value` (ambiguous column on pg) | `SecuregateMetricsService` | BLOCKER | Fixed: column qualified with the table name |
| `whereRaw` / `orWhereRaw` with `LOWER(x) LIKE ? ESCAPE '!'` | `AdminAccessLogController` | PASS | portable |
| `selectRaw('status, count(*)')->groupBy('status')`, `MAX(id)`, `MIN(attendance_date)` | Overtime/Correction/Request controllers, `AttendanceProcessor`, reconciliation | PASS | portable, covered by the pg suite |
| `whereRaw('1 = 0')` | provisioning/task scopes | PASS | – |
| `orderByRaw`, `havingRaw`, `DB::select`, `DB::unprepared` | none in app code | PASS | – |

### 2. id-or-code lookups (`where('door_id',$x)->orWhere('id',$x)`)

SQLite compares any text with the integer `id` silently; PostgreSQL raises
`invalid input syntax for type bigint` (HTTP 500) when `$x` is a code such as `DOOR-B`.

| where | class | status |
|---|---|---|
| 22 call sites from the a4fe4e3 audit (Employee, AdminDoor, DoorSync, Biometric, AccessLog, Facility, commands) | BLOCKER | Fixed via `App\Support\DbKey` guard |
| `FacilityConfigurationController::testManualConnection` (new on this branch) | BLOCKER | Fixed (15 pg test failures) |
| `findOrFail($id)` on untyped string route params (Onboarding, AccessProvisioning, Recruitment, Attendance, DeviceReconciliation) | NEEDS_PATCH (low) | Open: a non-numeric id returns 500 on pg instead of 404. Fix later with `whereNumber('id')` per route, not globally (Employee routes accept codes) |

Note (unchanged behaviour, PRODUCTION_RISK, low): `findEmployeeByIdentifier('00001')` also
matches `employees.id = 1` on both SQLite and PostgreSQL, because `'00001'` is a valid
key. Behaviour is identical on both drivers; it is listed so nobody "fixes" it by
normalizing identities.

### 3. Case sensitivity

- SQLite `LIKE` ignores ASCII case; PostgreSQL `LIKE` does not. 57 `'like'` conditions in
  `app/` (employee/door/log searches).
  Status: Fixed without touching call sites: `App\Database\PostgresConnection` uses a
  query grammar that compiles `like`/`not like` to `ilike`/`not ilike` on pgsql only.
  SQLite is untouched. Difference to note: `ILIKE` is also case-insensitive for
  non-ASCII letters, SQLite is not (no practical impact for NIK/codes).
- `=` comparisons are case-sensitive on both SQLite (BINARY) and PostgreSQL: PASS.
  No `COLLATE NOCASE` columns exist.
- Unique indexes (`admins.email`, `employees.employee_id`, `employees.nik`, `employees.email`,
  `employees.hikvision_employee_no`, `doors.door_id`) are case-sensitive on both: PASS. Before import, check for rows that differ only in case.

### 4. Identity safety

| column | type in migrations | class |
|---|---|---|
| `employees.employee_id`, `nik`, `card_no`, `hikvision_employee_no`, `source_person_number` | `string` | PASS |
| `device_person_states.device_employee_no`, `device_person_links.device_employee_no` | `string(64)` | PASS |
| `credential_records.card_number` | `string(64)` | PASS |
| `access_logs.nik` | `string` | PASS |
| `activity_logs.employee_id` / `door_id` / `assignment_id` | `unsignedBigInteger` (internal FK ids, not identities) | PASS |

No code in this series changes or casts these values. Import must read them as text
(no numeric casting), so `"00001"` stays `"00001"` (PRODUCTION_RISK for Phase 2 tooling,
see §8).

### 5. Migrations (PostgreSQL 16)

| check | result |
|---|---|
| enum columns | Become `varchar + CHECK` on pg. 11 CHECK constraints (door_assignments.sync_status, doors.status/connection_status, employees.credential_method/credential_status/credential_source/card_type, job_descriptions.status, credential_reconciliation_*). Code only writes allowed values. PRODUCTION_RISK: production rows must satisfy them; verify before import (§8). `2026_09_23_000003` only alters MySQL; `needs_verification` is already in the pg CHECK from `2026_09_23_000001`: PASS |
| `admins.role` enum | CHECK dropped on pg by the fixed normalize migration (same as the SQLite rebuild): PASS |
| `access_logs` enums | Created as plain strings on pg: PASS |
| boolean columns | Native `boolean` on pg; SQLite stores 0/1. No `where(bool_col, 0/1)` integer comparisons found: PASS. Import must cast 0/1 to boolean |
| timestamp defaults | `useCurrent()` on `failed_jobs.failed_at`, `job_applications.applied_at`: portable PASS |
| JSON columns | `json()` (13 columns: accessories, anomaly_flags, door_ids, summary, counts, card_hashes, card_masks, reasons, allowed_doors, employment_type_restrictions, specific_doors, metadata, rollback_data) become pg `json`. No JSON operators used in queries; models cast to array. Recommendation: keep as is (`json`), do not change to JSONB in Phase 1. Import must reject invalid JSON text (PRODUCTION_RISK, check in §8). `securegate_metrics.labels_json` is text: keep TEXT |
| indexes / unique | All created; `access_logs_event_type_index` added on pg for parity: PASS |
| foreign keys / cascade | `cascadeOnDelete` / `nullOnDelete` are enforced on pg exactly as declared. SQLite enforces them only with `foreign_keys=ON` (Laravel default, `DB_FOREIGN_KEYS=true`), and the `admins` / `access_logs` rebuilds ran with it OFF, so orphan rows are possible and would fail the pg import (PRODUCTION_RISK, check in §8) |
| nullable | Same definitions on both drivers: PASS |
| integer/bigInteger | `id()` and `foreignId()` are bigint/bigserial on pg; `unsignedInteger` becomes `integer` (no unsigned on pg; values are counts/ports/timeouts): PASS |
| rollback (`down()`) | Several migrations are intentionally irreversible (`2026_09_09_160100` etc.). `migrate:rollback` fails on pg where it silently passed on SQLite: NEEDS_PATCH (low, never run on production). The one test that used `DatabaseMigrations` now uses `RefreshDatabase` |

### 6. Critical domain flows (covered by the pg suite, 845/845 green)

employee CRUD, organization hierarchy, buildings, doors, door_assignments,
device_person_states, reconciliation, credential matching, access_logs, activity_logs,
dashboard metrics, attendance, webhook ingestion (`IsapiWebhookTest`), SSE/live stream,
queue jobs (`SyncDoorAccessJob`, sync driver), remote unlock (`RemoteUnlock*Test`, mocked
ISAPI), provisioning diagnostics (`SyncDoorAccessDiagnosticsTest`): PASS on both drivers.

Additional fixes found by the pg suite (patch 0005, earlier `0002` patch):

| item | class | status |
|---|---|---|
| `ProductionNormalizationService` / `BuildingAccessService` filter on non-existent `employees.person_no` / `is_active`. SQLite treats the quoted names as string literals (matches nothing); pg errors | BLOCKER (pg) | Fixed: dummies by `employee_id`, eligibility by `employment_status`. Both services are not wired to any route or command (only tests) |
| `AccessProvisioningPolicy::viewRequest` compared `access_requests.employee_id` (employees.id) with the admin's id | PRODUCTION_RISK (authorization) | Fixed: uses the admin's linked `employee_id`. This is a behaviour change on SQLite too; review before merge |

### 7. Sequences after explicit-ID import

All tables below use `bigserial id`. After importing rows with their original ids, every
sequence must be moved past `MAX(id)`:

```
SELECT setval(pg_get_serial_sequence('<table>', 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM <table>;
```

access_logs, access_profiles, access_requests, activity_logs, admins, assessments,
asset_assignments, asset_categories, asset_incidents, asset_maintenances, assets,
attendance_correction_requests, attendance_evidences, attendance_requests, attendances,
biometric_statuses, buildings, candidates, contracts, credential_device_syncs,
credential_reconciliation_audits, credential_reconciliation_batches, credential_records,
device_person_links, device_person_states, device_reconciliation_door_results,
device_reconciliation_runs, divisions, document_acknowledgements, door_assignments, doors,
emoney_cards, employee_calendar_assignments, employee_documents, employee_skills,
employees, failed_jobs, field_assignments, field_attendance_evidences, field_locations,
floors, internship_daily_activities, internship_evaluations, internship_reports,
internships, interviews, job_applications, job_descriptions, job_offers, job_vacancies,
jobs, migrations, onboarding_cases, onboarding_tasks, overtime_requests,
personal_access_tokens, positions, public_holidays, recruitment_stages,
skill_requirements, skills, task_worklogs, work_calendars, work_schedule_days, work_tasks,
zones (66 tables).

No serial id: `password_reset_tokens`, `securegate_metrics`.

### 8. Production risks to clear in Phase 2 (read-only checks on a copy of the SQLite file)

1. Schema drift: the production SQLite file was migrated incrementally (some migrations
   guard against half-applied ALTERs). Compare `sqlite3 copy.sqlite .schema` with the
   migration-built schema before import.
2. Type affinity: SQLite accepts any value in any column. Check, per identity column,
   `SELECT typeof(employee_id), COUNT(*) FROM employees GROUP BY 1` (expect only `text`).
   Never cast; copy the text as-is.
3. CHECK/enum values: `SELECT DISTINCT sync_status FROM door_assignments`, same for the
   other 10 CHECK columns in §5.
4. Orphans: rows whose foreign keys point at missing parents (`PRAGMA foreign_key_check`
   on the copy).
5. JSON validity: `SELECT id FROM <t> WHERE <col> IS NOT NULL AND json_valid(<col>) = 0`.
6. Booleans: only 0/1/NULL in boolean columns.
7. Run `db:verify-row-parity` with the baseline above after import, then reset sequences.

## Environment added

- `phpunit.pgsql.xml`: the suite against pgsql (`DB_HOST=postgres`, `DB_PORT=5432`,
  `DB_DATABASE=securegate_test`, `DB_USERNAME=securegate`, `DB_PASSWORD` from the environment).
- `docker-compose.pgsql-test.yml`: `postgres:16-alpine` on tmpfs plus a test runner.
- `.env.pgsql.example`: `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_PORT=5432`,
  `DB_DATABASE=securegate`, `DB_USERNAME=securegate`, empty `DB_PASSWORD`.
- `.gitlab-ci.yml`: `test_suite_pgsql` (allow_failure) next to the SQLite gate.
- Test guard: on any non-SQLite connection the suite refuses to run unless the database
  name ends with `_test`.

Production `docker-compose.yml` still uses SQLite and is unchanged.

## Status

POSTGRES_PHASE1_READY (all five patches applied: SQLite and PostgreSQL suites both green).
Without patch 0005 the PostgreSQL suite has 5 failures (§6), all in that patch's scope.
