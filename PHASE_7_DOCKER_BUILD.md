# PHASE 7: Docker Image Build

**Status**: Ready for execution (after PR merge)  
**Trigger**: After merge to main (f88407a → 8a80745 or equivalent)

## Build Strategy

### Build Parameters
- **Source**: Merged HEAD from main
- **Tag**: `pkp-securegate:<SHORT_SHA>-doorfix`
- **Build Context**: Repository root (this directory)
- **Dockerfile**: `./Dockerfile`
- **No-cache**: false (use layer cache for speed)

### Build Environment

```bash
# In worktree: integration/yazied-final-2026-09
cd D:\Magang\Project\access-door-management\access-door-management

# Get short SHA
SHORT_SHA=$(git rev-parse --short HEAD)  # 8a80745 → 8a80745

# Build image
docker build \
  --tag pkp-securegate:${SHORT_SHA}-doorfix \
  --build-arg BUILDKIT_INLINE_CACHE=1 \
  -f Dockerfile \
  .

# Expected output:
# Successfully built <IMAGE_ID>
# Successfully tagged pkp-securegate:8a80745-doorfix
```

## Security Verification (Post-Build)

### 1. No Hardcoded Secrets
```bash
docker run --rm pkp-securegate:8a80745-doorfix \
  grep -r "APP_KEY\|DATABASE_PASSWORD\|ISAPI_PASSWORD" .env* || echo "✓ No .env committed"
```
Expected: No .env files in image, only .env.example

### 2. App Key Validation
```bash
docker run --rm pkp-securegate:8a80745-doorfix \
  php artisan config:show app | grep APP_KEY
```
Expected: APP_KEY empty or base64:... (must be set at runtime via env var)

### 3. Mock Mode Status
```bash
docker run --rm pkp-securegate:8a80745-doorfix \
  php artisan config:show hikvision | grep use_mock
```
Expected: `use_mock: false` (from production .env)

### 4. No Debug Mode
```bash
docker run --rm pkp-securegate:8a80745-doorfix \
  php artisan config:show app | grep APP_DEBUG
```
Expected: `APP_DEBUG: false`

### 5. Image Size
```bash
docker images pkp-securegate:8a80745-doorfix --format "{{.Size}}"
```
Expected: <800MB (typical Laravel app with dependencies)

## Image Metadata

```bash
# Inspect image
docker inspect pkp-securegate:8a80745-doorfix

# Check layers
docker history pkp-securegate:8a80745-doorfix
```

## Registry Push (If applicable)

```bash
# Tag for registry (e.g., ACR, Docker Hub)
docker tag pkp-securegate:8a80745-doorfix <registry>/pkp-securegate:8a80745-doorfix

# Push
docker push <registry>/pkp-securegate:8a80745-doorfix
```

## Validation Checklist

- [ ] Build completes without errors
- [ ] Image runs without startup errors
- [ ] No embedded .env file
- [ ] APP_KEY requires runtime injection
- [ ] Hikvision mock mode correctly configured
- [ ] APP_DEBUG=false
- [ ] Image size reasonable (<1GB)
- [ ] All dependencies installed (composer install clean)
- [ ] JavaScript assets compiled (if needed)

## Build Failure Handling

If build fails:

1. **Missing dependencies**: Verify `composer.json` + `package.json` valid
   ```bash
   composer validate
   npm audit
   ```

2. **Syntax errors**: Check PHP/JavaScript linting
   ```bash
   node --check public/js/dashboard.js
   php -l app/Http/Controllers/Api/V1/AdminDoorController.php
   ```

3. **File permissions**: Ensure Dockerfile can access all source files
   ```bash
   ls -la storage/ bootstrap/cache/
   ```

4. **Rollback**: Revert to baseline image
   ```bash
   git reset --hard f88407a
   docker build -t pkp-securegate:f88407a .
   ```

## Image Lifecycle

### Storage
- **Local**: `docker images pkp-securegate:8a80745-doorfix`
- **Registry**: `<registry>/pkp-securegate:8a80745-doorfix`
- **Production**: Pull from registry when deploying

### Retention Policy
- Keep last 5 tagged images
- Delete intermediate/failed builds
- Document SHA256 hash for immutable reference

## Next Phase (PHASE 8)

After successful build + validation:
1. Pre-deployment checks (disk space, network, firewall)
2. Production environment validation
3. Load test (optional)
4. Deployment approval gate

## Commands Summary

```bash
# Build
SHORT_SHA=$(git rev-parse --short HEAD)
docker build --tag pkp-securegate:${SHORT_SHA}-doorfix -f Dockerfile .

# Validate
docker run --rm pkp-securegate:${SHORT_SHA}-doorfix php artisan config:show app | grep APP_DEBUG

# Push (if registry)
docker tag pkp-securegate:${SHORT_SHA}-doorfix <registry>/pkp-securegate:${SHORT_SHA}-doorfix
docker push <registry>/pkp-securegate:${SHORT_SHA}-doorfix

# Verify
docker images pkp-securegate:${SHORT_SHA}-doorfix --format "{{.ID}} {{.Size}}"
```
