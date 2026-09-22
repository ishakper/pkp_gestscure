#!/usr/bin/env pwsh

$image = "pkp-securegate-verify:3d48426"
$container = "test-pengguna-$([Guid]::NewGuid().ToString('N').Substring(0, 10))"
$tempDir = Join-Path $env:TEMP ("pagination-" + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

Write-Output "Running PenggunaPaginationAndSidebarTest..."
Write-Output "Container: $container"
Write-Output "Results: $tempDir"

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
  --entrypoint vendor/phpunit/phpunit/phpunit `
  $image `
  --colors=never --filter PenggunaPaginationAndSidebarTest --log-junit /tmp/result.xml 2>&1 | Out-Null

docker start -a $container
$exitCode = $LASTEXITCODE

docker cp "${container}:/tmp/result.xml" "$tempDir/result.xml" 2>&1 | Out-Null
$xmlCopy = $LASTEXITCODE

docker rm $container | Out-Null

if ($xmlCopy -ne 0) {
    Write-Output "ERROR: Could not copy test results"
    exit 1
}

[xml]$xml = Get-Content "$tempDir/result.xml" -Raw
$testCases = $xml.SelectNodes('//testcase')
$failures = $xml.SelectNodes('//failure')
$errors = $xml.SelectNodes('//error')

Write-Output ""
Write-Output "=== TEST RESULTS ==="
Write-Output "Tests: $($testCases.Count)"
Write-Output "Failures: $($failures.Count)"
Write-Output "Errors: $($errors.Count)"
Write-Output "Exit Code: $exitCode"
Write-Output ""

if ($failures.Count -gt 0) {
    Write-Output "=== FAILURES ==="
    foreach ($failure in $failures) {
        Write-Output "Test: $($failure.ParentNode.GetAttribute('name'))"
        Write-Output "Message: $($failure.InnerText)"
        Write-Output ""
    }
}

if ($errors.Count -gt 0) {
    Write-Output "=== ERRORS ==="
    foreach ($error in $errors) {
        Write-Output "Test: $($error.ParentNode.GetAttribute('name'))"
        Write-Output "Message: $($error.InnerText)"
        Write-Output ""
    }
}

if ($exitCode -eq 0 -and $failures.Count -eq 0 -and $errors.Count -eq 0) {
    Write-Output "[PASS] All tests passed!"
} else {
    Write-Output "[FAIL] Tests failed"
}

exit $exitCode
