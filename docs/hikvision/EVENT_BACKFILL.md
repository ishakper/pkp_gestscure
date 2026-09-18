# SOP: EVENT BACKFILL — HISTORICAL ACSEVENT SEARCH

**PURPOSE**: Define how to retrieve historical access events from device when AlertStream has a gap.  
**SCOPE**: `/ISAPI/AccessControl/AcsEvent` search endpoint.  
**AUTHORIZATION REQUIRED**: None (read-only query).  

---

## PREREQUISITES

- Device reachable (ISAPI read access)
- Application has last known AlertStream event timestamp (watermark)
- `HikvisionIsapiService` available

---

## When to Use Backfill

| Scenario | Action |
|---|---|
| AlertStream was down for known period | Backfill from last known `serialNo` timestamp |
| Container restart gap | Backfill events since last processed `dateTime` |
| Initial device onboarding | Backfill from device oldest event |
| Periodic reconciliation | Compare application event count vs device `totalNum` |

**Do NOT use backfill as primary event delivery** — AlertStream is primary. Backfill fills gaps only.

---

## Watermark Design

The application must maintain a watermark: the `dateTime` of the last successfully processed event per door.

```php
// Store in securegate_metrics table or a dedicated watermark table
// Key: "alertstream_watermark_DOOR-B"
// Value: "2026-09-18T18:30:00+07:00"
```

On backfill, query from `watermark_timestamp` to `now()`.

**Never set watermark in the future.** If watermark is corrupted, reset to 24 hours ago and accept possible duplicates (dedup layer handles them).

---

## AcsEvent Search API

```
POST /ISAPI/AccessControl/AcsEvent?format=json
```

**Request body**:
```json
{
    "AcsEventCond": {
        "searchID": "backfill-<unique>",
        "searchResultPosition": 0,
        "maxResults": 30,
        "major": 5,
        "minor": 75,
        "startTime": "2026-09-18T00:00:00+07:00",
        "endTime": "2026-09-18T23:59:59+07:00"
    }
}
```

**major=5, minor=75**: Access control events (card swipe / access granted/denied).  
Use `major=0, minor=0` to retrieve all event types (noisy — avoid unless auditing).

---

## Pagination (same as UserInfo/Search)

```php
$allEvents = [];
$pos = 0;
while (true) {
    $cond['searchResultPosition'] = $pos;
    $response = $client->post($url, ['AcsEventCond' => $cond]);
    $body = trim(preg_replace('/<\?xml[^?]+\?>/', '', $response->body()));
    $data = json_decode($body, true);

    $batch = $data['AcsEventSearch']['InfoList'] ?? [];
    if (empty($batch)) break;

    $allEvents = array_merge($allEvents, $batch);
    $pos += count($batch);

    $total = $data['AcsEventSearch']['totalMatches'] ?? 0;
    if ($pos >= $total) break;
}
```

Max per page: 30. Total device events: 22,923 as of 2026-09-18.

---

## Deduplication During Backfill

Backfill events MUST go through the same dedup layer as AlertStream events.

```php
// Check serialNo before insert
if (AccessLog::where('serial_no', $event['serialNo'])->where('door_id', $doorId)->exists()) {
    continue; // Already processed via AlertStream
}
```

**Expected behavior**: Most backfill events will be duplicates if AlertStream was only briefly down. Only true gap events will insert.

---

## Gap Detection

Compare device event count to application event count:

```php
$deviceTotal = $isapiService->getEventTotalNum($door);   // AcsEvent totalNum
$appTotal = AccessLog::where('door_id', $door->id)->count();
$gap = $deviceTotal - $appTotal;  // May be negative if device log rotated
```

**Negative gap**: Device log has rotated (overwritten old events). Normal — not an error.  
**Large positive gap** (> 1000): Indicates AlertStream was down for extended period. Trigger backfill.

---

## PRE-CHECK

```bash
# Verify AcsEvent endpoint responsive
php artisan tinker --execute="
    \$svc = app(App\Services\HikvisionIsapiService::class);
    \$d = App\Models\Door::where('door_id', 'DOOR-B')->first();
    \$total = \$svc->getEventTotalNum(\$d);
    dump('DEVICE_EVENT_TOTAL: ' . \$total);
"
```

Expected: integer ≥ 0. If endpoint returns 4xx, do not attempt backfill.

---

## PROCEDURE — Manual Backfill

1. Determine gap start time (watermark or known downtime start)
2. Run backfill artisan command: `php artisan hikvision:backfill-events DOOR-B --from="2026-09-18T10:00:00+07:00"`
3. Monitor progress: watch application logs for "backfill.inserted" and "backfill.skipped_dup" entries
4. After completion: verify `securegate_event_ingestion_total{status="processed"}` increased by expected count
5. Update watermark to current time

---

## VALIDATION

- [ ] Backfill command exited 0
- [ ] Application event count increased by (expected gap count ± dedup)
- [ ] No `status="error"` entries in Prometheus during backfill
- [ ] Watermark updated to post-backfill timestamp
- [ ] AlertStream still running (backfill must not interrupt live stream)

---

## STOP CONDITIONS

Stop backfill and investigate if:
- More than 10% of backfill events fail to insert (not due to dedup)
- Device returns 500 during backfill search
- Application DB disk space drops below 500 MB during large backfill
- Backfill would insert events older than retention policy

---

## AUDIT EVIDENCE

Log for each backfill run:
- Start timestamp, end timestamp, door ID
- Events found on device in range
- Events inserted (new)
- Events skipped (dedup)
- Events failed (errors)
- Total run duration
