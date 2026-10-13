param(
    [string]$ComposeFile = "docker-compose.staging.yml",
    [string]$Service = "staging-app",
    [string]$DatabaseService = "staging-db"
)
$ErrorActionPreference = 'Stop'
$compose = @('-f', $ComposeFile)
function Invoke-Compose([string[]]$ComposeArgs) { & docker compose -f $ComposeFile @ComposeArgs }
function Assert-Equal($Expected, $Actual, [string]$Name) {
    if ($Expected -ne $Actual) { throw "$Name expected '$Expected', got '$Actual'" }
}

# This runner only classifies an existing disposable orphan; it never edits state manually.
$ps = Invoke-Compose @('exec','-T',$Service,'sh','-lc','printf "PID="; cat /tmp/test-worker.pid 2>/dev/null || true; echo; ps -o pid,ppid,args')
$psText = ($ps | Out-String)
$pidMatch = [regex]::Match($psText, 'PID=(\d+)')
if ($pidMatch.Success) {
    $testPid = $pidMatch.Groups[1].Value
    if ($psText -match ('(?m)^\s*' + [regex]::Escape($testPid) + '\s+\d+\s+.*php /var/www/html/artisan queue:work')) {
        throw 'Refusing to continue: test PID still matches a live queue worker; do not recover an active worker.'
    }
}
$first = (Invoke-Compose @('exec','-T',$Service,'php','artisan','device-sync:recover') | Out-String).Trim()
$second = (Invoke-Compose @('exec','-T',$Service,'php','artisan','device-sync:recover') | Out-String).Trim()
if ($first -notmatch 'Recovered \d+ orphaned sync job\(s\)\.' -or $second -notmatch 'Recovered 0 orphaned sync job\(s\)\.') {
    throw "Unexpected recovery output: $first / $second"
}
Write-Output $first
Write-Output $second
Write-Output 'Runner completed; inspect DB/audit evidence separately.'


