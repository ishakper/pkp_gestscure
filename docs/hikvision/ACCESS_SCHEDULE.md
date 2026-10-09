# SOP: ACCESS SCHEDULE

**PURPOSE**: Define how time-based access restrictions are configured and managed.  
**SCOPE**: ISAPI schedule endpoints, week plans, holiday plans, door status plans.  
**AUTHORIZATION REQUIRED**: Schedule changes require infrastructure lead approval.  

---

## PREREQUISITES

- Device reachable (ISAPI read/write)
- Schedule requirements documented and approved
- `BACKUP_RESTORE.md` backup completed before any schedule write

---

## Device Schedule Capabilities (All Confirmed Supported)

| Feature | ISAPI Endpoint | Status |
|---|---|---|
| Week plan (Mon–Sun time windows) | `/AccessControl/ScheduleTemplate` | ✅ SUPPORTED |
| Holiday plan (date-specific overrides) | `/AccessControl/Holiday` | ✅ SUPPORTED |
| Door status plan (auto-lock/unlock) | `/AccessControl/DoorStatusPlan` | ✅ SUPPORTED |

Evidence: `isSupportVerifyWeekPlanCfg=true`, `isSupportVerifyHolidayPlanCfg=true`, `isSupportDoorStatusPlan=true` from capabilities.

---

## Schedule Types

### Week Plan (ScheduleTemplate)

Defines per-day time windows when access is permitted.

```json
{
    "ScheduleTemplate": {
        "id": 1,
        "name": "Standard Work Hours",
        "WeekPlan": [
            {"dayOfWeek": 1, "TimeSegment": [{"startTime": "07:00:00", "endTime": "18:00:00"}]},
            {"dayOfWeek": 2, "TimeSegment": [{"startTime": "07:00:00", "endTime": "18:00:00"}]},
            ...
        ]
    }
}
```

`dayOfWeek`: 1=Sunday, 2=Monday, ..., 7=Saturday (Hikvision convention).

### Holiday Plan

Overrides week plan on specific dates (public holidays, closures).

```json
{
    "Holiday": {
        "id": 1,
        "name": "Hari Kemerdekaan",
        "holidayType": "byDate",
        "startDate": "2026-08-17",
        "endDate": "2026-08-17",
        "TimeSegment": []
    }
}
```

Empty `TimeSegment` = no access on that holiday.

### Door Status Plan

Scheduled automatic lock/unlock (e.g., office hours auto-unlock).

```json
{
    "DoorStatusPlan": {
        "doorID": 1,
        "PlanCfgList": [
            {"dayOfWeek": 2, "startTime": "08:00:00", "endTime": "17:00:00", "doorStatus": "normalOpen"}
        ]
    }
}
```

**Use with caution**: `normalOpen` unlocks door without authentication. Only for lobbies/public areas.

---

## Current Schedule State

**Not yet configured** — no schedule templates have been created on the device. All 97 current users have unrestricted access (no schedule template assigned).

This is a known gap. Access schedule implementation is deferred until after:
1. Building B assignment gate cleared (96 employees provisioned)
2. HR provides approved work hours matrix

---

## PROCEDURE — Create Week Plan

1. Document required access windows per employee group (from HR)
2. Back up current device config: `BACKUP_RESTORE.md`
3. Create template via ISAPI: `POST /ISAPI/AccessControl/ScheduleTemplate`
4. Verify template created: `GET /ISAPI/AccessControl/ScheduleTemplate/<id>`
5. Assign template to users: `UserInfo/Record` PUT with `userVerifyMode` unchanged + `TimeTemplateNum` set
6. Verify: test card swipe inside and outside window

---

## PROCEDURE — Add Holiday Override

1. Identify holiday date and access policy (no access / reduced hours)
2. Create holiday: `POST /ISAPI/AccessControl/Holiday`
3. Assign holiday to existing template or as global override
4. Verify: check holiday appears in `GET /ISAPI/AccessControl/Holiday`

---

## VALIDATION

After schedule change:
- [ ] Template readable via GET after POST
- [ ] Users assigned to template (re-read UserInfo and check TimeTemplateNum)
- [ ] Test access inside permitted window → GRANTED
- [ ] Test access outside permitted window → DENIED
- [ ] AlertStream shows correct event types for both outcomes

---

## STOP CONDITIONS

- Stop if template POST returns non-200
- Stop if test swipe at wrong time produces GRANTED (schedule not applied)
- Revert: delete template and reassign users to "no template" (unrestricted) state

---

## AUDIT EVIDENCE

- Template creation: timestamp, template name, day/time ranges, initiating admin
- Holiday creation: date, policy, authorization reference
- User template assignment: employee ID, template assigned, timestamp
