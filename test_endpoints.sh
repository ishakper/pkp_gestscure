#!/bin/bash
# Test PKP SecureGate API endpoints

API_URL="http://localhost:8080/api/v1"
TOKEN=""

echo "=== PKP SECUREGATE API ENDPOINT TEST ==="
echo ""

# 1. Get login token (if needed)
echo "[ TEST 1 ] Dashboard Metrics"
curl -s "$API_URL/admin/dashboard-metrics" \
  -H "Accept: application/json" \
  | jq . | head -30

echo ""
echo "[ TEST 2 ] List Employees"
curl -s "$API_URL/user-management/employees" \
  -H "Accept: application/json" \
  | jq . | head -50

echo ""
echo "[ TEST 3 ] List Doors"
curl -s "$API_URL/admin/doors" \
  -H "Accept: application/json" \
  | jq . | head -30

echo ""
echo "[ TEST 4 ] Access Logs (latest 5)"
curl -s "$API_URL/admin/access-logs?limit=5" \
  -H "Accept: application/json" \
  | jq . | head -30

echo ""
echo "=== END TESTS ==="
