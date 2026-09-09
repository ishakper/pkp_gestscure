# Office Attendance Integration

## Status
Implemented; awaiting GitLab delivery gates.

## Purpose
Link authorized Hikvision access events to derived office attendance while keeping physical security logs immutable.

## Requirements
- A granted, mapped standard tap SHALL create one normalized attendance evidence after its AccessLog persists.
- Direction SHALL come from normalized device data. UNKNOWN SHALL never create a check-out by scan order.
- Denied or unmapped taps SHALL remain AccessLog-only.
- Repeated hardware serials SHALL remain idempotent.
- The dashboard SHALL show only processed attendance metadata and no device secret or raw payload.
