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
| 7 | Assets | PASS |
| 8 | Work Calendar + Attendance Core | PASS |
| 9 | Office Attendance Integration | PASS |
| 10 | Field Attendance + GPS + Geofence | PASS |
| 11 | WFH + Leave + Permission + Sick | PASS |
| 12 | Attendance Correction + Overtime | IN_PROGRESS |
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

Sprint 7: Asset Management, Classification (Asset Categories), Assignment & Handover (Employee, Intern, Building Scope), Return Workflow, Preventive Maintenance & Repairs, Incident Tracking (Loss, Damage, Theft), Safe Retirement & Disposal (History Preserved), Employee 360 Asset Section, Onboarding Asset Handoff Coordination, Intern Outstanding Asset Clearance Gate, Masked Serial Numbers, and Enterprise Asset Dashboard.
- SPRINT_7: PASS
- ASSET_DOMAIN: PASS
- ASSET_MASTER: PASS
- ASSET_CATEGORY: PASS
- ASSIGNMENT: PASS
- DUPLICATE_ASSIGNMENT_PREVENTION: PASS
- HANDOVER: PASS
- RETURN_WORKFLOW: PASS
- MAINTENANCE: PASS
- INCIDENT: PASS
- RETIREMENT_DISPOSAL: PASS
- ONBOARDING_INTEGRATION: PASS
- EMPLOYEE_360_INTEGRATION: PASS
- INTERN_INTEGRATION: PASS
- RBAC: PASS
- BUILDING_SCOPE: PASS
- IDOR: PASS
- PRIVACY: PASS
- AUDIT: PASS
- UI_UX: PASS
- TARGETED_TESTS: PASS (16 tests, 78 assertions)
- FULL_REGRESSION: PASS (140 tests, 737 assertions)
- MIGRATION_SAFETY: PASS (BEFORE_COUNT == AFTER_COUNT, 100% row preservation across all tables)
- SECRET_HYGIENE: PASS
- REPOSITORY_SYNC: PASS

Next: Sprint 8 Work Calendar + Attendance Core.

Sprint 8: Work Calendar + Attendance Core.
- SPRINT_8: PASS (commit `2f1f9dfeb50ab8078ad59b8add7769ba8a83bea0`)
- FULL_REGRESSION: PASS (176 tests, 838 assertions at acceptance)

Sprint 9: Office Attendance Integration.
- SPRINT_9: PASS
- PIPELINE_ID: 17730
- PIPELINE_SHA: 0e62d09118ff3a58e4d48b28bc1fb934542f9179
- PIPELINE_STATUS: PASSED
- GITLAB_VALIDATE: PASS
- GITLAB_BUILD: PASS
- GITLAB_TEST: PASS
- GITLAB_SECURITY: PASS
- ACCESSLOG_IMMUTABLE: PASS
- NORMALIZED_EVIDENCE / EVENT_DISPATCH / LISTENER_EXECUTION: PASS
- ENTRY / EXIT / MULTIPLE_EVENT / UNKNOWN_DIRECTION / DENIED / UNMAPPED / DEDUPLICATION: PASS
- ATTENDANCE_PROCESSOR_INTEGRATION: PASS (08:30 PRESENT, 08:31 LATE)
- OFFICE_ATTENDANCE_UI: PASS (processed data only; no raw device payload or device secret in browser config)
- TARGETED_TESTS: PASS (57 tests, 185 assertions)
- FULL_REGRESSION: PASS (183 tests, 884 assertions)
- MIGRATION_SAFETY: additive `attendance_evidences` table
- PHYSICAL_CARD_E2E: DEFERRED_WITH_EVIDENCE
- PHYSICAL_FINGERPRINT_E2E: DEFERRED_WITH_EVIDENCE

Sprint 10: Field Attendance + GPS + Photo + Geofence.
- SPRINT_10: PASS
- ATTENDANCE_PROCESSOR_REUSE: PASS (reuses AttendanceProcessor, sets attendance_type = FIELD)
- GEOFENCE_VALIDATION: PASS (server-side Haversine calculation, accuracy threshold, anomaly detection)
- PRIVATE_PHOTO_EVIDENCE: PASS (stored on local disk under randomized paths, authenticated stream endpoint with path traversal defense)
- RBAC_SCOPING: PASS (Self: employee/intern; Assigned Team: supervisor; Full Management: HRD; Summary: management without photos; Denied: building admin, developer)
- MOBILE_FIRST_UI: PASS (Geolocation API acquisition, camera/photo upload, double-click protection, daily status badge, photo viewer modal)
- TARGETED_TESTS: PASS (21 tests, 94 assertions in FieldAttendanceTest)
- FULL_REGRESSION: PASS (204 tests, 978 assertions)
- MIGRATION_PRESERVATION: PASS (row count check before and after additive migration)
Sprint 6 Follow-Up: Biometric User Provisioning & Centralized Door Sync (HR Integration Hardening).
- BIOMETRIC_PROVISIONING_SCOPE: SPRINT_6_FOLLOW_UP
- BIOMETRIC_PROVISIONING: PASS
- ISAPI_PAYLOAD_BUILDER: PASS (/ISAPI/AccessControl/UserInfo/SetUp?format=json specification)
- MULTI_STEP_ORCHESTRATOR: PASS (device ping -> UserInfo setup -> CardInfo record -> UserRightPlan)
- ASYNC_QUEUE_PROVISIONING: PASS (SyncDoorAccessJob with bounded retries and zero credential serialization)
- RAW_BIOMETRIC_STORAGE: NONE
- LAST_PAYLOAD_PRIVACY: PASS (dropped last_payload, zero raw biometric or ISAPI payload persistence)
- PERSISTED_PROVISIONING_DATA: MINIMAL
- RAW_CARD_LOGGING: NONE
- RAW_DEVICE_PAYLOAD_LOGGING: NONE
- PROVISIONING_IDEMPOTENCY: PASS (repeat sync deterministic, duplicate assignments prevented)
- RBAC / IDOR / BUILDING_SCOPE: PASS (Super Admin & HRD allowed; Building Admin scoped; Employee/Intern self-provision denied; Developer/DevOps technical roles denied)
- TARGETED_TESTS: PASS (22 tests, 94 assertions in BiometricUserProvisioningTest)
- PHYSICAL_BIOMETRIC_PROVISIONING_E2E: DEFERRED_WITH_EVIDENCE

Sprint 11: WFH + Leave + Permission + Sick.
- SPRINT_11: PASS
- FINAL_COMMIT_SHA: `ef3009b472cc625971691b95b5fc195301625a78`
- FINAL_PIPELINE_ID: 17743
- GITLAB_VALIDATE: PASS
- GITLAB_BUILD: PASS
- GITLAB_TEST: PASS
- GITLAB_SECURITY: PASS
- WFH_REQUEST: PASS (date range, work context, Attendance integration attendance_type = WFH)
- LEAVE_REQUEST: PASS (date range, categories, status LEAVE, excludes from ABSENT generation)
- PERMISSION_REQUEST: PASS (full-day & partial-day time boundaries, preserved physical logs)
- SICK_REQUEST: PASS (date range, private supporting document upload, status SICK, excludes from ABSENT generation)
- APPROVAL_WORKFLOW: PASS (backend authoritative approval, deterministic status lifecycle SUBMITTED -> APPROVED / REJECTED / CANCELLED)
- APPROVAL_SAFETY: PASS (anti-self-approval enforced, anti-IDOR enforced, double-approval prevention, rejection reason required)
- ROLE_SCOPES: PASS (Super Admin & HRD org-wide; Supervisor direct reports only; Employee self-service; Building Admin & Technical Roles denied)
- ATTENDANCE_INTEGRATION: PASS (AttendanceProcessor::integrateApprovedRequest, computeStatus recognizes approved requests, OFF_DAY unaffected)
- PRIVATE_DOCUMENTS: PASS (private local disk, random hash naming, MIME & 5MB size validation, authorized stream/download)
- OVERLAP_VALIDATION: PASS (conflicting active requests prevented across date ranges)
- AUDIT_TRAIL: PASS (ActivityLog on submit, approve, reject, cancel)
- MOBILE_FIRST_UI: PASS (Pengajuan Absensi tab, metrics cards, filter bar, modals for new request and rejection reason, status timeline badges)
- TARGETED_TESTS: PASS (30 tests, 74 assertions in AttendanceRequestTest)
- FULL_REGRESSION: PASS (256 tests, 1146 assertions)
- MIGRATION_SAFETY: PASS (additive table attendance_requests, pre/post table counts verified preserved)
- REPOSITORY_SYNC: PASS

Next: Sprint 12 Attendance Correction + Overtime (IN_PROGRESS).
