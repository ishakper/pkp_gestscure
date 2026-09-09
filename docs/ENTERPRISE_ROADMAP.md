# PKP SecureGate Enterprise Roadmap

Status per 2026-09-09.

| Sprint | Scope | Status |
|---|---|---|
| 0 | Repository, module, RBAC audit | PASS |
| 1 | Role architecture and portal navigation foundation | PASS |
| 2 | Organization, Employee Master, Employee 360 | PASS |
| 3 | Recruitment / ATS | PASS |
| 4 | Internship | PASS |
| 5 | Onboarding, contracts, documents | NOT_STARTED |
| 6 | Access provisioning, credentials, e-money | NOT_STARTED |
| 7 | Assets | NOT_STARTED |
| 8-12 | Calendar, attendance, field attendance, requests, overtime | NOT_STARTED |
| 13-18 | Skills, work, projects, approvals, training, performance | NOT_STARTED |
| 19-24 | Reporting, analytics, documents, offboarding, search, mobile UX | NOT_STARTED |
| 25-27 | Privacy/RBAC audit, backup readiness, full regression | NOT_STARTED |

Sprint 1 maps legacy `super_admin` to ADMIN_PORTAL and `building_admin` to MANAGEMENT_PORTAL while preserving building scope. Future employee/intern identities require a first-class authenticated employee account before self-service access is enabled.

Sprint 1 validation: portal mapping and legacy building scope regression passed (77 tests, 383 assertions). Commit SHA: `30c210082547ee705eca0c2abfa5fb62f5336622`.

Sprint 2: Employee 360, reporting line, scoped authorization, and audit regression.
- SPRINT_2: PASS
- FINAL_COMMIT_SHA: `0c48f7f11c747344263bd53f4ac041f8f23176ee`
- FINAL_PIPELINE_ID: 17717
- FINAL_TEST_COUNT: 85 tests
- FINAL_ASSERTION_COUNT: 409 assertions
- GITLAB_VALIDATE: PASS
- GITLAB_BUILD: PASS
- GITLAB_TEST: PASS
- GITLAB_SECURITY: PASS
- DEPLOYMENT_STATUS: DEFERRED_WITH_EVIDENCE (Office target infrastructure 192.168.90.81 offline; manual job gated)
- SECRET_HYGIENE: PASS
- CREDENTIAL_ROTATION_RECOMMENDED: YES
- REPOSITORY_SYNC: PASS
- Known limitation: SQLITE_REBUILD_BEFORE_COUNT = NOT_CAPTURED; future operational table migrations MUST record before_count and after_count.

Sprint 3: Recruitment / Applicant Tracking System (ATS), Vacancies, Talent Pool, Stage Transitions, Interviews, Offering, and Employee Conversion.
- SPRINT_3: PASS
- FINAL_COMMIT_SHA: `89227a2d`
- FINAL_PIPELINE_ID: 17719
- FINAL_TEST_COUNT: 90 tests
- FINAL_ASSERTION_COUNT: 461 assertions
- GITLAB_VALIDATE: PASS (composer & php -l syntax validation)
- GITLAB_BUILD: PASS (Docker container built & tagged)
- GITLAB_TEST: PASS (PHPUnit suite in runner container)
- GITLAB_SECURITY: PASS (Clean secret hygiene and config checks)
- DEPLOYMENT_STATUS: DEFERRED_WITH_EVIDENCE (Office infrastructure 192.168.90.81 offline; manual rule maintained)
- REPOSITORY_SYNC: PASS

Sprint 4: Internship Management, Programs, Mentor Scoping, Daily Activity Worklogs, Monthly Reports, 8-Dimension Evaluation, Candidate-to-Intern Conversion, Completion & Access Revocation Marker.
- SPRINT_4: PASS
- FINAL_COMMIT_SHA: `2b9c8711`
- FINAL_PIPELINE_ID: 17721
- FINAL_TEST_COUNT: 100 tests (98 passed, 2 warnings)
- FINAL_ASSERTION_COUNT: 505 assertions
- GITLAB_VALIDATE: PASS (composer & php -l syntax validation)
- GITLAB_BUILD: PASS (Docker container built & tagged)
- GITLAB_TEST: PASS (PHPUnit suite in runner container)
- GITLAB_SECURITY: PASS (Clean secret hygiene and config checks)
- DEPLOYMENT_STATUS: DEFERRED_WITH_EVIDENCE (Office infrastructure 192.168.90.81 offline; manual rule maintained)
- REPOSITORY_SYNC: PASS
- DATABASE_SAFETY: Row preservation verified (BEFORE_COUNT == AFTER_COUNT)

Next: Sprint 5 Onboarding, Contracts, Documents.

