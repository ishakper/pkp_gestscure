# Enterprise Architecture

PKP SecureGate uses one Laravel identity/session boundary. Authorization is server-side and combines **role + permission + scope**. Navigation is a presentation of granted permissions and is never the authorization mechanism. Device credentials come only from untracked environment configuration.

Current identities are `Admin` records. Legacy roles remain compatible: `super_admin`, `building_admin`. New named roles are normalized through `PortalAccess` without database migration: infra/developer/security map to the admin portal; HRD/management/supervisor/project manager map to management; employee/intern map to self-service when authenticated identity support is introduced.
