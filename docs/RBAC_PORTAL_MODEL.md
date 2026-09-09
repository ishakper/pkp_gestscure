# RBAC Portal Model

| Portal | Roles | Initial modules |
|---|---|---|
| ADMIN_PORTAL | super admin, infra, developer, DevOps, security engineer | Dashboard, System, Devices, Security, RBAC, Integrations, Audit, Settings |
| MANAGEMENT_PORTAL | building admin, HRD, management, supervisor, project manager, security, auditor | Dashboard, People, Organization, Attendance, Work, Projects, Approvals, Assets, Reports |
| EMPLOYEE_PORTAL | employee, intern | Home, My Attendance, My Requests, My Work, My Documents, My Access, My Profile |

Building admins retain their existing assigned-building query and policy restrictions. Employee/intern portal access is deferred until its identity guard is implemented; it is never inferred from a frontend role string.
