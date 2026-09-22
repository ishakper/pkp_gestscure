# AUTOMATED PIPELINE RETRY PROMPT

**For:** Infrastructure Team (once capacity fixed)  
**Usage:** Paste into VS Code after infrastructure is confirmed healthy  
**Target:** Pipeline #18135, SHA 313ecde6fdea9bb4d6577e96efe6c41ce186baf3

---

## PREREQUISITE CHECK

Before running this prompt, verify:

```bash
# 1. Runner disk capacity
ssh infra@10.10.8.124 "df -h /"
# Should show: ≥4 GB Avail

# 2. Runner online in GitLab
# → GitLab Admin → Runners → status green

# 3. Production app healthy
ssh infra@10.10.8.124 "curl -s http://localhost:8000/login | head -10"
# Should return HTML (200 OK)

# 4. Repository state (from local machine)
cd D:\Magang\Project\access-door-management\access-door-management
git rev-parse HEAD
# Should output: 313ecde6fdea9bb4d6577e96efe6c41ce186baf3

git status --short
# Should output: (nothing — working tree clean)
```

---

## AUTOMATED RETRY PROMPT

**Copy everything below and paste into VS Code Copilot Chat:**

```
MODE: AUTOMATED PIPELINE RETRY — POST-INFRASTRUCTURE-FIX

INFRASTRUCTURE_FIXED=YES
RUNNER_DISK_AVAILABLE=≥4GB (verified)
RUNNER_ONLINE=YES (verified)
PRODUCTION_HEALTHY=YES (verified)

TARGET_SHA=313ecde6fdea9bb4d6577e96efe6c41ce186baf3
PIPELINE=18135
BRANCH=ishak/full-functional-integration-2026-09

STRICT_RULES:
- Do NOT create new commits
- Do NOT modify code
- Do NOT force push
- Do NOT restart production
- Do NOT run docker prune on runner
- Do NOT change any configuration

TASK:

1. VERIFY REPOSITORY STATE
   - Repository: D:/Magang/Project/access-door-management/access-door-management
   - HEAD: 313ecde6fdea9bb4d6577e96efe6c41ce186baf3
   - Branch: ishak/full-functional-integration-2026-09
   - Clean: YES (no uncommitted changes)

2. VERIFY RUNNER CAPACITY
   - Disk free: ≥4 GB
   - RAM: ≥500 MB free
   - Status: ONLINE in GitLab

3. VERIFY PRODUCTION
   - App: pkp_securegate_app (UP, HTTP 200)
   - Alert: pkp_securegate_alertstream_door_b (UP, active)
   - No changes made

4. RETRY PIPELINE #18135
   - URL: https://gitlab.pkp.co.id/infra/access-door-management/-/pipelines/18135
   - Action: Click "Retry" button (top right)
   - DO NOT modify any parameters

5. MONITOR JOBS
   Required jobs (all must PASS):
   - fix_runner_workspace ← check first (if this fails, stop and debug)
   - validate_composer
   - validate_syntax
   - build_docker
   - build_verification_image
   - test_suite
   - security_hygiene

6. GATE: CONFIRM ALL GREEN
   - Check pipeline status: PASS (green checkmark)
   - Check all 7 jobs: PASS
   - Record: Pipeline 18135, SHA 313ecde, STATUS=GREEN
   - Time completed: [timestamp]

7. NOTIFY DEPLOYMENT TEAM
   - Message: "CI pipeline 18135 (SHA 313ecde) now GREEN — ready for deployment approval"
   - Include: All job names and status
   - Reference: This is same code quality verified on 2026-09-21

8. DO NOT DEPLOY WITHOUT EXPLICIT APPROVAL
   - Wait for release team approval
   - Use standard deployment workflow
   - Follow existing rollback plan

FINAL GATE:
- CI_GREEN=YES ✓
- PRODUCTION_HEALTHY=YES ✓
- CODE_UNCHANGED=YES ✓
- READY_FOR_DEPLOYMENT_APPROVAL=YES ✓

OUTPUT:

PIPELINE_ID=18135
PIPELINE_SHA=313ecde6fdea9bb4d6577e96efe6c41ce186baf3
PIPELINE_STATUS=[PASS/FAIL]
TIMESTAMP=[when completed]

FIX_RUNNER_WORKSPACE=[PASS/FAIL]
VALIDATE_COMPOSER=[PASS/FAIL]
VALIDATE_SYNTAX=[PASS/FAIL]
BUILD_DOCKER=[PASS/FAIL]
BUILD_VERIFICATION_IMAGE=[PASS/FAIL]
TEST_SUITE=[PASS/FAIL]
SECURITY_HYGIENE=[PASS/FAIL]

ALL_JOBS_GREEN=[YES/NO]
CI_GREEN=[YES/NO]

PRODUCTION_APP=[RUNNING/FAILED]
PRODUCTION_HEALTH=[PASS/FAIL]
LOGIN_HTTP=[200/other]
ALERT_STREAM=[RUNNING/FAILED]

NO_CODE_CHANGES_MADE=[YES]
NO_NEW_COMMITS=[YES]
NO_PUSH=[YES]
NO_DEPLOYMENTS=[YES]

FINAL_VERDICT=[CI_GREEN_READY_FOR_RELEASE_REVIEW / CI_FAILED_REQUIRES_DIAGNOSIS]
```

---

## IF PIPELINE STILL FAILS

If any job fails after infrastructure fix:

1. **Capture job trace** from failed job
2. **Check runner disk** again: `ssh infra@10.10.8.124 "df -h /"`
3. **Check runner logs**: `ssh infra@10.10.8.124 "journalctl -u gitlab-runner -n 50"`
4. **Post logs** to diagnosis ticket (do NOT post credentials or tokens)

---

## EXPECTED TIMELINE

- Pre-check: 5 min
- Pipeline retry: 30–45 min (depending on build cache)
- Notification: 2 min
- **Total:** ~45–60 min from start to green

---

**Status:** Ready for retry once infrastructure team confirms capacity fix complete.
