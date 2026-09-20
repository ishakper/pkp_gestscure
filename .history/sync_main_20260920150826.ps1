# Sync local main with merged changes from GitLab
cd D:\Magang\Project\access-door-management

Write-Host "=== Syncing main branch ==="

# Get GitLab main SHA
$GITLAB_MAIN_SHA = git rev-parse origin/main
Write-Host "GITLAB_MAIN_SHA: $GITLAB_MAIN_SHA"

# Switch to main and pull
Write-Host ""
Write-Host "Checking out main..."
git checkout main

Write-Host "Pulling latest from origin..."
git pull origin main --no-edit

# Get local main SHA
$LOCAL_MAIN_SHA = git rev-parse HEAD
Write-Host "LOCAL_MAIN_SHA: $LOCAL_MAIN_SHA"

# Verify they match
if ($GITLAB_MAIN_SHA -eq $LOCAL_MAIN_SHA) {
  Write-Host ""
  Write-Host "✓ SHAs match - sync successful"
  Write-Host "FINAL_SHA=$GITLAB_MAIN_SHA"
} else {
  Write-Host ""
  Write-Host "✗ WARNING: SHAs DO NOT match"
  Write-Host "GitLab main:  $GITLAB_MAIN_SHA"
  Write-Host "Local main:   $LOCAL_MAIN_SHA"
}

# Show merge commit log
Write-Host ""
Write-Host "=== Latest commits ==="
git log --oneline -5
