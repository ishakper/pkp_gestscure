#!/usr/bin/env pwsh
# Reliable test runner using named containers and docker cp

param(
    [Parameter(Mandatory = $true)]
    [string]$ClassName
)

$ErrorActionPreference = 'Stop'

$resultsDir = Join-Path $env:TEMP ("yazied-" + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $resultsDir -Force | Out-Null

$image = "pkp-securegate-verify:3d48426"
$container = "test-$([Guid]::NewGuid().ToString('N').Substring(0, 12))"
$xmlPath = Join-Path $resultsDir "result.xml"
$logPath = Join-Path $resultsDir "result.log"

Write-Output "TEST_CLASS=$ClassName"
Write-Output "CONTAINER=$container"
Write-Output "RESULTS_DIR=$resultsDir"

# Create container with proper environment
docker create `
  --name $container `
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
  -lc "php -d max_execution_time=0 vendor/phpunit/phpunit/phpunit --colors=never --filter '$ClassName' --log-junit /tmp/result.xml > /tmp/result.log 2>&1" | Out-Null

if ($LASTEXITCODE -ne 0) {
    throw "Container creation failed for $ClassName"
}

# Run test
docker start -a $container
$exitCode = $LASTEXITCODE

# Capture exit code
$stateExit = [int](docker inspect --format '{{.State.ExitCode}}' $container)

# Copy results
docker cp "${container}:/tmp/result.log" $logPath 2>&1 | Out-Null
$logCopyExit = $LASTEXITCODE

docker cp "${container}:/tmp/result.xml" $xmlPath 2>&1 | Out-Null
$xmlCopyExit = $LASTEXITCODE

# Cleanup
docker rm $container | Out-Null

# Validate results
if ($logCopyExit -ne 0) {
    Write-Output "FAILED: Log copy failed"
    exit 1
}

if ($xmlCopyExit -ne 0) {
    Write-Output "FAILED: XML copy failed"
    exit 1
}

# Parse XML
$xmlContent = Get-Content $xmlPath -Raw
[xml]$xml = $xmlContent

$testCases = $xml.SelectNodes('//testcase')
$failures = $xml.SelectNodes('//failure')
$errors = $xml.SelectNodes('//error')
$skipped = $xml.SelectNodes('//skipped')
$warnings = $xml.SelectNodes('//warning')

Write-Output "TESTS=$($testCases.Count)"
Write-Output "FAILURES=$($failures.Count)"
Write-Output "ERRORS=$($errors.Count)"
Write-Output "SKIPPED=$($skipped.Count)"
Write-Output "WARNINGS=$($warnings.Count)"
Write-Output "EXIT_CODE=$stateExit"

# Output log
Write-Output ""
Write-Output "=== LOG ==="
Get-Content $logPath -Raw
Write-Output ""

# If failures or errors, print details
if ($failures.Count -gt 0) {
    Write-Output "=== FAILURES ==="
    foreach ($failure in $failures) {
        $testCase = $failure.ParentNode
        Write-Output "TEST: $($testCase.GetAttribute('name'))"
        Write-Output "FILE: $($testCase.GetAttribute('file'))"
        Write-Output "LINE: $($testCase.GetAttribute('line'))"
        Write-Output "MESSAGE:"
        Write-Output $failure.InnerText
        Write-Output ""
    }
}

if ($errors.Count -gt 0) {
    Write-Output "=== ERRORS ==="
    foreach ($error in $errors) {
        $testCase = $error.ParentNode
        Write-Output "TEST: $($testCase.GetAttribute('name'))"
        Write-Output "FILE: $($testCase.GetAttribute('file'))"
        Write-Output "LINE: $($testCase.GetAttribute('line'))"
        Write-Output "MESSAGE:"
        Write-Output $error.InnerText
        Write-Output ""
    }
}

# Test for zero match
if ($testCases.Count -eq 0) {
    Write-Output "FAILED: Zero-test match for $ClassName"
    exit 1
}

# Exit with container exit code
exit $stateExit
