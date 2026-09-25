# PHASE 8-9: Pre-Deployment & Deployment

**Status**: Blocked by disk space (815MB free, need ≥3GB)  
**Target Environment**: Production (10.10.8.124:8000)  
**Image**: `pkp-securegate:8a80745-doorfix`

## PHASE 8: Pre-Deployment Validation

### Gate 1: Disk Space Check ⚠️ BLOCKED
```bash
# On production host (10.10.8.124)
df -h | grep /

# Current state:
# Mounted on /         Size    Used   Avail  Use%
# <device>            <size>  <used>  815M  
# STATUS: FAILED - Need ≥3GB free, have 815MB
```

**Action Required**: 
1. Expand disk on VM/host to +3GB
2. OR clean up old images/logs:
   ```bash
   docker system prune -a --volumes  # Caution: removes unused data
   rm -rf /var/log/docker/*
   ```

### Gate 2: Network Connectivity
```bash
# Verify connection to Hikvision doors
ping -c 1 192.168.90.15   # DOOR-B
ping -c 1 192.168.90.11   # DOOR-A
ping -c 1 192.168.90.13   # DOOR-C
ping -c 1 192.168.90.14   # DOOR-D

# Expected: All ICMP replies (0% loss)
```

### Gate 3: Database Health
```bash
# Inside current production container
docker compose exec -T app php artisan tinker

# Check database
DB::table('users')->count()     # Expected: 110 (108 employees + 2 admins)
DB::table('doors')->count()     # Expected: 4
```

### Gate 4: Current Image State
```bash
# Check running container
docker ps | grep pkp_securegate_prod

# Get current image tag
docker inspect pkp_securegate_prod --format '{{.Config.Image}}'
# Expected output: pkp-securegate:<PREVIOUS_SHA>

# Document for rollback
CURRENT_IMAGE=$(docker inspect pkp_securegate_prod --format '{{.Config.Image}}')
echo "$CURRENT_IMAGE" > /tmp/rollback_image.txt
```

### Gate 5: Configuration Validation
```bash
# Verify .env.production has correct settings
# - HIKVISION_ISAPI_USE_MOCK=false
# - APP_ENV=production
# - ALLOWED_DEVICE_IPS includes door IPs
# - HIKVISION_LISTENER_IP reachable by Hikvision

# Inside container:
docker compose exec -T app php artisan config:show hikvision | grep -E "use_mock|host|port"
```

## PHASE 9: Deployment

### Pre-Deployment Confirmation
```
[ ] Gate 1: Disk space ≥3GB available
[ ] Gate 2: All door networks reachable (ping success)
[ ] Gate 3: Database integrity verified (110 users, 4 doors)
[ ] Gate 4: Current image documented for rollback
[ ] Gate 5: Production .env validated
[ ] Backup: Current database exported
```

### Deployment Steps

**Step 1: Update Image Tag**
```bash
# Edit docker-compose.prod.yml
# Change:
#   image: pkp-securegate:<OLD_SHA>-<tag>
# To:
#   image: pkp-securegate:8a80745-doorfix

# OR use sed:
sed -i 's/pkp-securegate:[^ ]*/pkp-securegate:8a80745-doorfix/g' docker-compose.prod.yml
```

**Step 2: Pull New Image**
```bash
# If image stored in registry
docker pull pkp-securegate:8a80745-doorfix

# Verify pull success
docker images | grep 8a80745-doorfix
# Expected: <some output>
```

**Step 3: Graceful Container Update**
```bash
# Stop current container (gives apps time to drain connections)
docker compose -f docker-compose.prod.yml down

# Wait 5 seconds
sleep 5

# Start new container
docker compose -f docker-compose.prod.yml up -d app

# Monitor startup
docker logs -f pkp_securegate_prod --tail 50
```

**Step 4: Health Check**
```bash
# Wait for container to be healthy (usually 15-30 seconds)
sleep 30

# Check container status
docker ps | grep pkp_securegate_prod
# Status should show: "Up X seconds (healthy)"

# Test HTTP endpoint
curl -s -o /dev/null -w "%{http_code}" http://10.10.8.124:8000/login
# Expected: 200 (OK)

# Test dashboard endpoint
curl -s -o /dev/null -w "%{http_code}" http://10.10.8.124:8000/dashboard
# Expected: 200 or 302 (if redirects to login)
```

**Step 5: Database Connection Test**
```bash
docker compose exec -T app php artisan migrate --force
# Should complete with: "No migrations pending"

# OR:
docker compose exec -T app php artisan tinker
>>> DB::connection()->getPdo()
# Expected: PDOStatement object (successful)
```

### Rollback Procedure (If Deployment Fails)

**Scenario 1: Container won't start**
```bash
# Revert to previous image
ROLLBACK_IMAGE=$(cat /tmp/rollback_image.txt)
sed -i "s|pkp-securegate:.*|${ROLLBACK_IMAGE}|g" docker-compose.prod.yml

# Restart
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d app

# Verify
curl http://10.10.8.124:8000/login
```

**Scenario 2: Database migrations fail**
```bash
# Immediately revert
git checkout docker-compose.prod.yml  # Restore old image tag
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d app

# Check database for corruption
docker compose exec -T app php artisan db:check
```

**Scenario 3: JavaScript errors in dashboard**
```bash
# Check browser console for errors
# If "isPrimaryDeploymentDoor is not defined":
#   → Build failed, reverted code
#   → Rollback immediately

# If other errors:
#   → Check Docker logs
docker logs pkp_securegate_prod --tail 100 | grep -i error
```

## PHASE 10: Acceptance Testing (60 seconds minimum)

After deployment successful, run acceptance gates:

```bash
# Gate 1: HTTP Response
curl -w "%{http_code}" http://10.10.8.124:8000/login
# Expected: 200

# Gate 2: Container Health
docker ps | grep pkp_securegate_prod | grep "healthy"
# Expected: Some output (status is healthy)

# Gate 3: Database Integrity
docker compose exec -T app php artisan tinker
>>> DB::table('users')->count()
# Expected: 110

>>> DB::table('doors')->count()
# Expected: 4

# Gate 4: Employee Count
curl -s http://10.10.8.124:8000/api/v1/admin/employees | jq '.data | length'
# Expected: 108

# Gate 5: Door Cards Render
curl -s http://10.10.8.124:8000/api/v1/admin/doors | jq '.data | length'
# Expected: 4 (all 4 doors in response)

# Gate 6: JavaScript Validation
# Open browser to http://10.10.8.124:8000/dashboard
# Open Developer Tools (F12) → Console
# Expected: No "isPrimaryDeploymentDoor is not defined" error
# Expected: Door cards render successfully
# Expected: Building filter dropdown available

# Gate 7: Building Filter Functional
# In dashboard, change building filter from "Semua Gedung" to "A"
# Expected: Door cards filter to only Gedung A doors
# Expected: KPI metrics update for selected building

# Gate 8: Remote Unlock Test (Optional)
# Click "Remote Unlock" on a door card
# Expected: API call succeeds (check Network tab)
# Expected: No auth errors in response

# Gate 9: No 5XX Errors
docker logs pkp_securegate_prod --tail 100 | grep -c "500\|ERROR\|Exception"
# Expected: 0

# Gate 10: Network Traffic OK
# Access logs should show access from user IP
docker compose exec -T app php artisan tinker
>>> DB::table('access_logs')->latest()->first()
# Expected: Recent entry with employee_id + door_id

```

## Success Criteria

All 10 gates pass → **DEPLOYMENT SUCCESSFUL**

**Final Status**: 
- ✓ Container healthy
- ✓ Database integrity verified  
- ✓ HTTP 200 on login/dashboard
- ✓ Door cards render without errors
- ✓ Building filter functional
- ✓ No undefined variable errors
- ✓ Remote unlock works
- ✓ All 4 doors visible
- ✓ KPI metrics correct
- ✓ No 5XX errors in logs

## Rollback Timeline

- **Detection**: If acceptance test fails, immediately identified
- **Action**: < 5 minutes to revert (run rollback commands)
- **Recovery**: Previous image should be healthy within 30 seconds
- **Verification**: Run acceptance gates again

## Communication

Post-deployment:
1. Document successful deployment with timestamp + new image SHA
2. Notify team of deployment completion
3. Archive acceptance test results
4. Update status page (if applicable)
5. Schedule post-deployment review (24 hours after)

## Files Modified

- `docker-compose.prod.yml` (image tag update only)
- No source code changes in deployment phase

## Next Steps

After successful deployment & acceptance:
1. Monitor production logs for 24 hours
2. Run extended acceptance tests (bandwidth, load)
3. Communicate to stakeholders
4. Document deployment procedure for future use
5. Archive all logs and acceptance test results
