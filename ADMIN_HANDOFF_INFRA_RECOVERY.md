# ADMIN HANDOFF — CI/CD Infrastructure Recovery

**Generated:** 2026-09-21  
**Status:** Awaiting infrastructure capacity fix  
**Audience:** GitLab Admin + Infrastructure/DevOps Team

---

## CURRENT STATE (Do NOT modify)

```
CORRECT_REPO=D:/Magang/Project/access-door-management/access-door-management
TARGET_SHA=313ecde6fdea9bb4d6577e96efe6c41ce186baf3
BRANCH=ishak/full-functional-integration-2026-09

CODE_STATUS=READY ✓
  - Tests: 509/509 PASS
  - Quality gates: ALL PASS
  - Repository: CLEAN (no uncommitted changes)
  - Sync: LOCAL == GITLAB == GITHUB

PRODUCTION_STATUS=HEALTHY ✓
  - App: pkp_securegate_app (UP 19h, HTTP 200)
  - Alert stream: pkp_securegate_alertstream_door_b (UP 3d, active)
  - Hikvision pipeline: INTACT
  - Impact: NONE (no changes deployed)

CI_STATUS=FAILED ❌
  - Pipeline: 18135
  - Job: 45606 (script_failure)
  - Root cause: INCONCLUSIVE (likely disk/capacity)
  - Runner disk: 1.3 GB free (required ≥2 GB)
  - Runner swap: 0 B (none)
```

---

## IMMEDIATE ACTION — SECURITY

### Step 1: Rotate Runner Token (CRITICAL)

**Why:** Runner authentication token was exposed in terminal output during diagnosis.

**How:**

1. Log in to GitLab as admin: https://gitlab.pkp.co.id/admin
2. Navigate: Admin Panel → Runners
3. Find runner: `infra-Standard-PC-i440FX-PIIX-1996` (IP 10.10.8.124)
4. Click **"Reset runner token"**
5. **Copy new token** (will be shown once, keep safe)
6. On runner host (10.10.8.124):
   ```bash
   sudo gitlab-runner register \
     --url https://gitlab.pkp.co.id \
     --token <NEW_TOKEN_HERE> \
     --executor shell \
     --description "CI Runner (shell)"
   ```
7. Restart runner:
   ```bash
   sudo systemctl restart gitlab-runner
   ```

**Verify:** Check runner status in GitLab admin panel (should show as online within 30 sec).

---

## CAPACITY FIX — SELECT ONE

### Option A: Expand Filesystem (Quick, 15–30 min)

**If:** Current disk partition has unallocated space available

```bash
# Check LVM status
sudo lvdisplay
sudo pvdisplay

# If VG has free space, expand LV
sudo lvextend -L +2G /dev/mapper/vg0-root  # adjust path as needed
sudo resize2fs /dev/mapper/vg0-root  # or xfs_growfs if XFS
```

**Risk:** Low (if space available). Temporary solution.

### Option B: Dedicate Runner Host (Recommended, 1–2 hours)

**Specification:**
- OS: Ubuntu 20.04 LTS or later
- CPU: 4 cores (2 minimum)
- RAM: 8 GB (4 minimum)
- Disk: 100 GB (50 GB minimum)
- Network: Same subnet as CI coordinator

**Steps:**

1. Provision new VM with above spec
2. SSH in and install GitLab Runner:
   ```bash
   curl -L https://packages.gitlab.com/install/repositories/runner/gitlab-runner/script.deb.sh | sudo bash
   sudo apt-get install gitlab-runner
   ```

3. Register with new token:
   ```bash
   sudo gitlab-runner register \
     --url https://gitlab.pkp.co.id \
     --token <NEW_TOKEN_HERE> \
     --executor docker \
     --docker-image ubuntu:20.04 \
     --description "CI Runner (dedicated)" \
     --tag-list docker,securegate,ci
   ```

4. Update `.gitlab-ci.yml` to use new tags (if needed)

5. Test with a simple job

6. Decommission old runner:
   ```bash
   sudo gitlab-runner uninstall
   ```

**Benefit:** Permanent, scalable, separates CI from production.

### Option C: Attach Network Storage (30–60 min, interim)

**If:** New host provisioning is delayed

1. Provision NFS or iSCSI storage (50–100 GB)
2. Mount on runner host:
   ```bash
   sudo mkdir -p /mnt/runner-cache
   sudo mount -t nfs4 <storage-server>:/export/runner /mnt/runner-cache
   sudo chown -R gitlab-runner:gitlab-runner /mnt/runner-cache
   ```

3. Update GitLab Runner config:
   ```toml
   # /etc/gitlab-runner/config.toml
   volumes = [
     "/mnt/runner-cache:/runner-cache",
     "/var/run/docker.sock:/var/run/docker.sock"
   ]
   ```

4. Restart: `sudo systemctl restart gitlab-runner`

---

## VERIFICATION CHECKLIST

After infrastructure fix, verify:

```bash
# 1. Runner is online
ssh infra@10.10.8.124 "df -h / && free -h"
# Expected: ≥4 GB free disk, ≥500 MB RAM

# 2. GitLab sees runner as online
# → Admin Panel → Runners → should show green "online"

# 3. Production is still healthy
ssh infra@10.10.8.124 "curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/login"
# Expected: 200

# 4. Alert stream active
ssh infra@10.10.8.124 "docker ps --format 'table {{.Names}}\t{{.Status}}'"
# Expected: pkp_securegate_alertstream_door_b Up
```

---

## RETRY PIPELINE — NO CODE CHANGES

Once infrastructure is verified healthy:

**Do NOT:**
- Create new commits
- Modify code
- Force push
- Restart production containers

**Do:**

1. Navigate to GitLab: https://gitlab.pkp.co.id/infra/access-door-management/-/pipelines/18135
2. Click **"Retry"** button (top right)
3. **Monitor** all 8 jobs:
   - ✅ fix_runner_workspace
   - ✅ validate_composer
   - ✅ validate_syntax
   - ✅ build_docker
   - ✅ build_verification_image
   - ✅ test_suite
   - ✅ security_hygiene
   - ✅ (any remaining required jobs)

4. **Confirm:** All jobs PASS (green pipeline)

5. **Record:** Pipeline #18135 status = GREEN, SHA = 313ecde

---

## RELEASE APPROVAL — AFTER CI PASSES

Once pipeline 18135 is GREEN:

1. Send notification: "CI/CD infrastructure recovered, pipeline 18135 passing"
2. Request deployment approval from stakeholders
3. Proceed with deployment workflow (separate runbook)

---

## ROLLBACK (if needed)

No rollback required — no code was deployed. If deployment fails after CI passes, revert using existing rollback procedures.

---

## CONTACT

- **Code/CI Questions:** [CodeOwner]
- **Infrastructure/Runner Issues:** [InfraTeam]
- **GitLab Admin Access:** [AdminTeam]

---

**Status:** Awaiting infrastructure team action. Code is ready. Do not modify repository.
