# Building B Business Input Contract

This document specifies the required business-approved values needed to create Building B entitlements in PKP SecureGate production.

## Building Definition

| Field | Value | Status |
|-------|-------|--------|
| BUILDING_CODE | TBD_BUSINESS_APPROVAL | Awaiting |
| BUILDING_NAME | TBD_BUSINESS_APPROVAL | Awaiting |
| BUILDING_DESCRIPTION | TBD_BUSINESS_APPROVAL | Awaiting |
| ACTIVE_STATUS | true (assumed) | TBD |

## Floor Definition (if required)

| Field | Value | Status |
|-------|-------|--------|
| FLOOR_REQUIRED | TBD_BUSINESS_APPROVAL | Awaiting |
| FLOOR_CODE | TBD_BUSINESS_APPROVAL | Awaiting |
| FLOOR_NAME | TBD_BUSINESS_APPROVAL | Awaiting |

## Zone Definition (if required)

| Field | Value | Status |
|-------|-------|--------|
| ZONE_REQUIRED | TBD_BUSINESS_APPROVAL | Awaiting |
| ZONE_CODE | TBD_BUSINESS_APPROVAL | Awaiting |
| ZONE_NAME | TBD_BUSINESS_APPROVAL | Awaiting |

## Door Mapping

| Field | Value | Status |
|-------|-------|--------|
| DOOR_ID | DOOR-B | Confirmed |
| DOOR_NAME | Door B - Restricted Server Room | Confirmed |

## Employee Access

| Field | Value | Status |
|-------|-------|--------|
| ELIGIBILITY_SCOPE | All 96 real employees | Confirmed |
| EXCEPTION_GROUPS | TBD_BUSINESS_APPROVAL | Awaiting |
| ACCESS_SCHEDULE | TBD_BUSINESS_APPROVAL | Awaiting |

## Approvals

| Field | Value | Status |
|-------|-------|--------|
| APPROVAL_OWNER | TBD_BUSINESS_APPROVAL | Awaiting |
| APPROVAL_DATE | TBD_BUSINESS_APPROVAL | Awaiting |

## Instructions for Business Team

1. Fill in all TBD_BUSINESS_APPROVAL fields with actual values
2. Send approved document to Operations
3. Operations will execute Building B automation
4. No Hikvision writes will occur — read-only only

