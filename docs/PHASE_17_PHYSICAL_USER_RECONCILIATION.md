# Phase 17 Physical Hikvision User Reconciliation Report

## Device Context
- **Door ID**: `DOOR-B`
- **Door Name**: `Door B - Restricted Server Room`
- **IP Address**: `192.168.90.15:80`
- **Model**: `DS-K1T804AMF`
- **Verification Mode**: Physical hardware read-only dry run

## Inventory and Reconciliation Aggregates
- `DEVICE_USERS`: 96
- `UNIQUE_DEVICE_EMPLOYEE_NO`: 96
- `CARD_REGISTERED`: 82
- `NO_CARD`: 14
- `MATCHED_EXISTING`: 0
- `PENDING_CREATION`: 96
- `CONFLICTS`: 0
- `UNKNOWN`: 0
- `DUPLICATE_EMPLOYEE_NO`: 0
- `DUMMY_ONLY`: 12

## Privacy and Protection
- **Raw Card Identifiers**: 0 (neither cardNo nor masked suffixes are exposed, logged, or stored)
- **Biometric Templates**: 0 (never fetched or emitted)
- **Safe Field Mapping**: Missing mandatory enterprise fields default to `NEEDS_BUSINESS_DATA` rather than fabricated values.
- **Idempotency**: Proved via automated test suite (`PhysicalUserReconciliationTest`). First apply creates missing identities; second apply creates 0 and matches all.
