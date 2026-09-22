#!/usr/bin/env pwsh

$ErrorActionPreference = 'Continue'

$resultsDir = Get-Content (Join-Path $env:TEMP "yazied-results-dir.txt") -Raw | ForEach-Object { $_.Trim() }
Write-Output "Results dir: $resultsDir"

$containerName = "yazied-dashboard-$([Guid]::NewGuid().ToString('N').Substring(0,8))"
$xmlPath = Join-Path $resultsDir "DashboardButtonContractTest.xml"
$logPath = Join-Path $resultsDir "DashboardButtonContractTest.log"

Write-Output "Container: $containerName"
Write-Output "Log path: $logPath"

docker create `
  --name $containerName `
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
  pkp-securegate-verify:332bf68 `
  -lc "php -d max_execution_time=0 vendor/bin/phpunit --colors=never --filter 'DashboardButtonContractTest' --log-junit /tmp/result.xml > /tmp/result.log 2>&1"

Write-Output "Container created, starting..."
docker start -a $containerName
Write-Output "Container finished"

$testExit = [int](docker inspect --format '{{.State.ExitCode}}' $containerName)
Write-Output "EXIT_CODE=$testExit"

docker cp "${containerName}:/tmp/result.log" $logPath 2>&1
if (Test-Path $logPath) {
    Write-Output "=== TEST LOG ==="
    Get-Content $logPath -Raw
}

docker cp "${containerName}:/tmp/result.xml" $xmlPath 2>&1
if (Test-Path $xmlPath) {
    Write-Output "=== XML RETRIEVED ==="
}

docker rm $containerName | Out-Null
Write-Output "Cleanup done"
