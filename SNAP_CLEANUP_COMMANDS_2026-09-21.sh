#!/bin/bash
# SNAP CLEANUP SCRIPT — PKP SecureGate Runner Disk Recovery
# Date: 2026-09-21
# Target: Remove disabled snap revisions to free ~1.0-1.2GB
# Prerequisites: sudo access on infra@10.10.8.124

echo "=== PRE-CLEANUP DISK STATE ==="
df -h /
df -B1 / | tail -1

echo ""
echo "=== REMOVING DISABLED SNAP REVISIONS ==="
echo "Removing: core22 rev 2292"
sudo snap remove core22 --revision=2292
df -B1 / | tail -1

echo ""
echo "Removing: core24 rev 1643"
sudo snap remove core24 --revision=1643
df -B1 / | tail -1

echo ""
echo "Removing: firefox rev 8819"
sudo snap remove firefox --revision=8819
df -B1 / | tail -1

echo ""
echo "Removing: firmware-updater rev 210"
sudo snap remove firmware-updater --revision=210
df -B1 / | tail -1

echo ""
echo "Removing: gnome-42-2204 rev 247"
sudo snap remove gnome-42-2204 --revision=247
df -B1 / | tail -1

echo ""
echo "Removing: snap-store rev 1270"
sudo snap remove snap-store --revision=1270
df -B1 / | tail -1

echo ""
echo "Removing: snapd rev 27710 (disabled only, not snapd service)"
sudo snap remove snapd --revision=27710
df -B1 / | tail -1

echo ""
echo "Removing: snapd-desktop-integration rev 343"
sudo snap remove snapd-desktop-integration --revision=343
df -B1 / | tail -1

echo ""
echo "=== POST-CLEANUP DISK STATE ==="
df -h /
df -B1 / | tail -1

echo ""
echo "=== OPTIONAL: APT CACHE CLEANUP ==="
echo "sudo apt-get clean"

echo ""
echo "=== OPTIONAL: JOURNAL ROTATION ==="
echo "sudo journalctl --vacuum-time=7d"

echo ""
echo "=== VERIFY RUNNER ==="
gitlab-runner verify
systemctl is-active gitlab-runner

echo ""
echo "=== FINAL CAPACITY CHECK ==="
FREE_BYTES=$(df -B1 / | tail -1 | awk '{print $4}')
FREE_GB=$(echo "scale=2; $FREE_BYTES / 1024 / 1024 / 1024" | bc)
echo "Free disk: ${FREE_GB}GB"

if (( $(echo "$FREE_GB >= 4" | bc -l) )); then
    echo "✓ CAPACITY GATE PASS (>= 4GB)"
else
    echo "✗ CAPACITY GATE FAIL (< 4GB)"
    echo "REQUIRED: Dedicated runner or filesystem expansion"
fi
