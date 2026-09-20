# Check GitLab for latest MR
$mr_url = 'https://gitlab.pkp.co.id/api/v4/projects/infra%2Faccess-door-management/merge_requests?state=opened&order_by=created_at&sort=desc&per_page=1'
$response = Invoke-WebRequest -Uri $mr_url -UseBasicParsing -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Content
if ($response) {
  $mr = $response | ConvertFrom-Json
  [PSCustomObject]@{
    IID = $mr[0].iid
    Title = $mr[0].title
    State = $mr[0].state
    Web_URL = $mr[0].web_url
  } | Format-List
}
