# Check if running as administrator
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "[INFO] Requesting Administrator privileges to add Windows Defender Firewall rule..." -ForegroundColor Yellow
    Start-Process powershell -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    exit
}

Write-Host "==================================================================" -ForegroundColor Cyan
Write-Host "Configuring Windows Defender Firewall for Laravel Dev Server (8000)" -ForegroundColor Cyan
Write-Host "==================================================================" -ForegroundColor Cyan

# 1. Remove old rule
Remove-NetFirewallRule -DisplayName "Laravel Dev Server (Port 8000)" -ErrorAction SilentlyContinue

# 2. Add rule for Port 8000 TCP Inbound on Any profile
New-NetFirewallRule -DisplayName "Laravel Dev Server (Port 8000)" `
    -Direction Inbound `
    -Action Allow `
    -Protocol TCP `
    -LocalPort 8000 `
    -Profile Any `
    -Description "Allows incoming LAN traffic to Laravel Dev Server on port 8000"

Write-Host "`nRule configuration successfully applied:" -ForegroundColor Green
Get-NetFirewallRule -DisplayName "Laravel Dev Server (Port 8000)" | Format-List DisplayName, Name, Enabled, Direction, Action, Profile

Write-Host "[SUCCESS] Port 8000 is permanently allowed across Private and Public profiles." -ForegroundColor Green
Write-Host "Colleagues can now access http://192.168.90.81:8000/ over LAN/Wi-Fi." -ForegroundColor Green
Read-Host "Press Enter to exit"
