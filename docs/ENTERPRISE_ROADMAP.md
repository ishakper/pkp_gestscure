# PKP SecureGate Enterprise Roadmap

Status per 2026-09-09.

| Sprint | Scope | Status |
|---|---|---|
| 0 | Repository, module, RBAC audit | PASS |
| 1 | Role architecture and portal navigation foundation | PASS |
| 2 | Organization, Employee Master, Employee 360 | PASS |
| 3 | Recruitment / ATS | NOT_STARTED |
| 4 | Internship | NOT_STARTED |
| 5 | Onboarding, contracts, documents | NOT_STARTED |
| 6 | Access provisioning, credentials, e-money | NOT_STARTED |
| 7 | Assets | NOT_STARTED |
| 8-12 | Calendar, attendance, field attendance, requests, overtime | NOT_STARTED |
| 13-18 | Skills, work, projects, approvals, training, performance | NOT_STARTED |
| 19-24 | Reporting, analytics, documents, offboarding, search, mobile UX | NOT_STARTED |
| 25-27 | Privacy/RBAC audit, backup readiness, full regression | NOT_STARTED |

Sprint 1 maps legacy `super_admin` to ADMIN_PORTAL and `building_admin` to MANAGEMENT_PORTAL while preserving building scope. Future employee/intern identities require a first-class authenticated employee account before self-service access is enabled.

Sprint 1 validation: portal mapping and legacy building scope regression passed (77 tests, 383 assertions). Commit SHA: `30c210082547ee705eca0c2abfa5fb62f5336622`.
Next: Sprint 3 Recruitment / ATS.

Sprint 2: Employee 360, reporting line, scoped authorization, and audit regression. Validation: 85 tests / 409 assertions. Limitation: SQLITE_REBUILD_BEFORE_COUNT = NOT_CAPTURED; future operational table migrations MUST record before_count and after_count.
