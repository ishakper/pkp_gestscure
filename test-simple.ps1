#!/usr/bin/env pwsh

$ErrorActionPreference = 'Continue'
$image = "pkp-securegate-verify:332bf68"
$resultsDir = Get-Content (Join-Path $env:TEMP "yazied-results-dir.txt") -Raw | ForEach-Object { $_.Trim() }

# Test 1: DashboardButtonContractTest
$containerName = "test-dashboard-$([Guid]::NewGuid().ToString('N').Substring(0,8))"
$logPath = Join-Path $resultsDir "dashboard.log"

docker run --rm `
  --network none `
  -e APP_ENV=testing `
  -e APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' `
  -e DB_CONNECTION=sqlite `
  -e DB_DATABASE=:memory: `
  -e QUEUE_CONNECTION=sync `
  -e CACHE_STORE=array `
  -e SESSION_DRIVER=array `
  -e MAIL_MAILER=array `
  -e HIKVISION_MOCK_MODE=true `
  -e HIKVISION_ISAPI_USE_MOCK=true `
  --entrypoint /bin/sh `
  $image `
  -c "php -d max_execution_time=0 vendor/bin/phpunit --colors=never --filter 'DashboardButtonContractTest'" 2>&1 | Tee-Object -FilePath $logPath

Write-Host "Log saved to: $logPath"
