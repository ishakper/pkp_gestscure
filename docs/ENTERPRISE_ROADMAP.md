# PKP SecureGate Enterprise Roadmap

Status per 2026-09-09.

| Sprint | Scope | Status |
|---|---|---|
| 0 | Repository, module, RBAC audit | PASS |
| 1 | Role architecture and portal navigation foundation | PASS |
| 2 | Organization, Employee Master, Employee 360 | PASS |
| 3 | Recruitment / ATS | PASS |
| 4 | Internship | PASS |
| 5 | Onboarding, contracts, documents | PASS |
| 6 | Access provisioning, credentials, e-money | PASS |
| 7 | Assets | IN_PROGRESS |
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

Sprint 5: Onboarding Cases, 10-Task Standard Checklist, Completion Gate, Contract Lifecycle, Private HR Document Storage, Strict Document RBAC/IDOR Prevention, Versioning, Acknowledgements, and Expiring Alerts.
- SPRINT_5: PASS
- FINAL_COMMIT_SHA: `a6445e4a`
- FINAL_PIPELINE_ID: 17724
- FINAL_TEST_COUNT: 108 tests (106 passed, 2 warnings)
- FINAL_ASSERTION_COUNT: 576 assertions
- GITLAB_VALIDATE: PASS (composer & php -l syntax validation)
- GITLAB_BUILD: PASS (Docker container built & tagged)
- GITLAB_TEST: PASS (PHPUnit suite in runner container)
- GITLAB_SECURITY: PASS (Clean secret hygiene and config checks)
- DEPLOYMENT_STATUS: DEFERRED_WITH_EVIDENCE (Office infrastructure 192.168.90.81 offline; manual rule maintained)
- REPOSITORY_SYNC: PASS
- DATABASE_SAFETY: Row preservation verified (BEFORE_COUNT == AFTER_COUNT)

Sprint 6: Access Provisioning, Reusable Access Profiles, Approval Gate with Building Scope Check, Centralized Credential Center (Zero Raw Biometric Storage, Masked Identifiers), Idempotent Asynchronous Device Sync Queue, Inactive Employee & Completed Intern Revocation Hooks, Admin-Only E-Money Registry, and Employee 360 Access Integration.
- SPRINT_6: PASS
- FINAL_COMMIT_SHA: `a9c8f593`
- FINAL_PIPELINE_ID: 17726
- FINAL_TEST_COUNT: 124 tests (122 passed, 2 warnings)
- FINAL_ASSERTION_COUNT: 659 assertions
- GITLAB_VALIDATE: PASS (composer & php -l syntax validation)
- GITLAB_BUILD: PASS (Docker container built & tagged)
- GITLAB_TEST: PASS (PHPUnit suite in runner container)
- GITLAB_SECURITY: PASS (Clean secret hygiene and config checks)
- DEPLOYMENT_STATUS: DEFERRED_WITH_EVIDENCE (Office infrastructure 192.168.90.81 offline; manual rule maintained)
- REPOSITORY_SYNC: PASS
- ACCESS_PROVISIONING_DOMAIN: PASS
- ACCESS_REQUEST_WORKFLOW: PASS
- ACCESS_APPROVAL: PASS
- ACCESS_PROFILE: PASS
- CREDENTIAL_CENTER: PASS
- CARD_CREDENTIAL: PASS
- BIOMETRIC_METADATA_ONLY: PASS
- NO_RAW_BIOMETRIC_STORAGE: PASS
- DEVICE_SYNC_QUEUE: PASS
- SYNC_IDEMPOTENCY: PASS
- REVOCATION: PASS
- EMPLOYEE_STATUS_HOOK: PASS
- INTERN_COMPLETION_HOOK: PASS
- E_MONEY_REGISTRY: PASS
- MASKING: PASS
- RBAC: PASS
- BUILDING_SCOPE: PASS
- IDOR_PREVENTION: PASS
- AUDIT: PASS
- SECURITY: PASS
- UI_UX: PASS
- TARGETED_TESTS: PASS
- FULL_REGRESSION: PASS (124 tests, 659 assertions)
- MIGRATION_SAFETY: PASS (BEFORE_COUNT == AFTER_COUNT, 100% row preservation)
- SECRET_HYGIENE: PASS
- PHYSICAL_DEVICE_E2E: DEFERRED_WITH_EVIDENCE (Physical DOOR-B hardware not reachable from runner; software mock integration PASS)

Next: Sprint 7 Asset Management.
