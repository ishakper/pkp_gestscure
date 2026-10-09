# ==============================================================================
# PKP SecureGate - Build Release Tarball Package
# ==============================================================================

$targetTar = "release-securegate.tar.gz"
$updateTar = "update-bundle.tar.gz"

Write-Host "Creating $targetTar..." -ForegroundColor Cyan

# Use tar with strict exclusions
tar --exclude='.git' `
    --exclude='.env' `
    --exclude='node_modules' `
    --exclude='vendor' `
    --exclude='storage/logs/*' `
    --exclude='storage/app/*' `
    --exclude='storage/framework/cache/*' `
    --exclude='storage/framework/sessions/*' `
    --exclude='storage/framework/views/*' `
    --exclude='database/database.sqlite' `
    --exclude='.phpunit.result.cache' `
    --exclude='openspec' `
    --exclude='.agents' `
    --exclude='release-securegate.tar.gz' `
    -czf $targetTar `
    app bootstrap config database public resources routes artisan composer.json composer.lock package.json Dockerfile docker-compose.yml docker deploy

Copy-Item -Path $targetTar -Destination $updateTar -Force

Write-Host "[SUCCESS] Generated $targetTar and updated $updateTar successfully." -ForegroundColor Green
Get-Item $targetTar | Select-Object Name, Length, LastWriteTime | Format-Table
