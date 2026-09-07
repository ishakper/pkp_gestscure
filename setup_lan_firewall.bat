@echo off
:: Self-elevation to Administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [INFO] Requesting Administrator privileges to configure Windows Defender Firewall...
    powershell -Command "Start-Process cmd -ArgumentList '/c \"\"%~dpnx0\"\"' -Verb RunAs"
    exit /b
)

echo ==============================================================================
echo [FIREWALL CONFIGURATION] Laravel Dev Server (Port 8000)
echo ==============================================================================

echo 1. Removing any conflicting or older firewall rules...
netsh advfirewall firewall delete rule name="Laravel Dev Server (Port 8000)" >nul 2>&1

echo 2. Adding Inbound TCP rule for Port 8000 (Profile: Any - Private and Public)...
netsh advfirewall firewall add rule name="Laravel Dev Server (Port 8000)" dir=in action=allow protocol=TCP localport=8000 profile=any

echo.
echo 3. Verifying the rule status:
echo ------------------------------------------------------------------------------
netsh advfirewall firewall show rule name="Laravel Dev Server (Port 8000)"
echo ------------------------------------------------------------------------------

echo.
echo [SUCCESS] Port 8000 is now open across all network profiles (Private and Public).
echo Colleagues on the same Wi-Fi/LAN can now access:
echo   http://192.168.90.81:8000/
echo.
pause
