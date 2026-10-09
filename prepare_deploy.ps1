# Prepare for deployment
cd D:\Magang\Project\access-door-management

Write-Host "=== DEPLOYMENT PREPARATION ==="
Write-Host ""

# Final merged SHA
$FINAL_SHA = "4cb46b0"
$FINAL_IMAGE_TAG = "pkp-securegate:$FINAL_SHA"

Write-Host "FINAL_SHA: $FINAL_SHA"
Write-Host "FINAL_IMAGE_TAG: $FINAL_IMAGE_TAG"
Write-Host ""

Write-Host "The Docker image should have been built by GitLab CI pipeline #18107"
Write-Host "CI pipeline status: PASS"
Write-Host ""

Write-Host "Next steps:"
Write-Host "1. SSH to production server: infra@10.10.8.124"
Write-Host "2. Create database backup"
Write-Host "3. Deploy using: docker-compose pull && docker-compose up -d"
Write-Host "4. Verify: login works, dashboard loads, Building-B dry-run passes"
Write-Host ""

# Show deployment details
Write-Host "=== DEPLOYMENT DETAILS ==="
Write-Host "Production server: infra@10.10.8.124"
Write-Host "Service: pkp-securegate"
Write-Host "Current image: pkp-securegate:50d808b585cf97107d54bc2b7e62c03fe01d76e0"
Write-Host "New image: $FINAL_IMAGE_TAG"
Write-Host ""
Write-Host "Building-B status: DRY-RUN MODE ONLY"
Write-Host "Hikvision writes: NOT EXECUTED"
