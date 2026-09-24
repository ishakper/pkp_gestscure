# PKP SecureGate — Production Deployment Record & Security Follow-Up

**Date:** 2026-09-17  
**Environment:** Production (`10.10.8.124:8000`)  
**Deployment Status:** SUCCESS / STABLE  

---

## 1. Release & Merge Information

- **GitLab MR:** !9 (`ishak/postmerge-deploy-hardening-2026-09` -> `main`) merged successfully
- **Merge Commit SHA:** `c6755d7a18ddac4a1f7307cb3969cd10baa57563`
- **Post-Merge CI Pipeline:** GitLab Pipeline #18040 (PASS)
- **Deployment Strategy:** Controlled immutable image rollout with release overlay

---

## 2. Production Deployment State

- **Web Container:** `pkp_securegate_app`
  - **Deployed Image:** `pkp-securegate:c6755d7a18ddac4a1f7307cb3969cd10baa57563`
  - **Runtime Status:** `running`
  - **Health Status:** `healthy`
  - **Restart Count:** `0` (Restart Delta: `0`)
- **AlertStream Container:** `pkp_securegate_alertstream_door_b`
  - **Strategy:** Maintained on stable baseline; untouched during deployment
  - **Active Image:** `pkp-securegate:033fb33c917567f2c9abdde27a1e8396b4122668`
  - **Runtime Status:** `running`
  - **Restart Count:** `0` (Restart Delta: `0`)
- **Rollback Safety Baseline:**
  - **Preserved Image:** `pkp-securegate:rollback-pre-phase17-9f20678` (`sha256:4e66608a25cd...`)
- **Database & Migrations:**
  - `DB_MIGRATION_REQUIRED=NO`
  - `SKIP_MIGRATIONS=true`
  - Zero database migrations or wipe operations executed

---

## 3. Host Preflight & Storage Verification

- **Disk Space Preflight:**
  - Initial free space: `232MB` (`99%` used on `/dev/sda1`)
  - Reclamation action: `docker builder prune` (removed `4.075GB` dangling and unused build cache only)
  - Preserved objects: Zero runtime images, volumes, databases, logs, or backups deleted
  - Free space post-cleanup and post-deployment: `3.0GB` (`84%` used on `/dev/sda1`)
- **Configuration & Release Overlay:**
  - Release overlay path: `/home/infra/access-door-management/deploy/docker-compose.release.yml`
  - SHA256 Verification: `514628324c3ee10bee8295b170ba9aee482f7122ddf11d23777a74883316801b`
  - Content check: Exactly matches repository version (`build: !reset null`, immutable `${PKP_IMAGE}`)
  - `CONFIG_DRIFT=NO`

---

## 4. Health & Security Verification

- **HTTP Endpoints:**
  - Root endpoint (`http://127.0.0.1:8000/`): HTTP `302` (redirects to `/login`)
  - Login endpoint (`http://127.0.0.1:8000/login`): HTTP `200` (OK)
- **Swagger Documentation Protection:**
  - `/api/documentation`: HTTP `302` (unauthenticated access redirected to `/login`)
  - `/docs`: HTTP `401` (unauthenticated JSON specification access blocked)
- **Runtime Caches:**
  - `php artisan config:cache`: SUCCESS
  - `php artisan route:cache`: SUCCESS
  - `php artisan view:cache`: SUCCESS

---

## 5. Security Follow-Up Required

A prior interactive session transcript exposed production environment variable names and configuration keys during compose inspection. No secret values are included in this document. The following controlled security follow-up actions are required:

1. **GitLab Personal Access Token (PAT):**
   - Rotation/revocation is REQUIRED unless independently confirmed already completed.
2. **Door Controller (DOOR_B) Credentials:**
   - Coordinate controlled credential rotation on physical device and host environment file.
3. **DOOR_B Webhook Secret:**
   - Coordinate rotation of `DOOR_B_WEBHOOK_SECRET` between controller listener configuration and `.env`.
4. **Application Key (`APP_KEY`):**
   - **DO NOT rotate automatically.**
   - Rotation invalidates active user sessions, encrypted cookies, and any persisted Laravel-encrypted database fields.
   - Comprehensive data impact assessment must precede any planned rotation.
5. **Operational Hygiene:**
   - Review session and terminal log retention per organizational security policy.
   - Future docker compose debugging must use sanitized environment files without dumping secrets to stdout.

### Security Follow-up Checklist

- [ ] GitLab PAT rotated/revoked
- [ ] DOOR_B credential rotation scheduled
- [ ] webhook secret rotation scheduled
- [ ] APP_KEY impact assessment completed
- [ ] terminal/transcript retention reviewed
- [ ] future compose validation sanitized
- [ ] no production secret printed in CI/logs
