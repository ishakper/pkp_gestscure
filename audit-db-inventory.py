#!/usr/bin/env python3
"""
Database Inventory Audit for PKP SecureGate
Reads active Laravel SQLite database; checks doors table schema, counts employees,
credential records, access logs. JSON output with timestamps. No credential leakage.
"""
import sqlite3
import sys
import json
from datetime import datetime
from pathlib import Path

# Sensitive columns to never print
SENSITIVE_COLS = {'password', 'remember_token', 'api_token', 'isapi_password'}

def check_db(db_path):
    if not Path(db_path).exists():
        return None, f"DB not found: {db_path}"
    try:
        conn = sqlite3.connect(db_path)
        conn.execute("SELECT 1")
        return conn, None
    except Exception as e:
        return None, str(e)

def query(conn, sql, label):
    try:
        rows = conn.execute(sql).fetchall()
        return rows, None
    except Exception as e:
        return None, f"Query '{label}' failed: {e}"

def audit():
    ts = datetime.now().isoformat()
    report = {"timestamp": ts, "status": "ERROR", "errors": [], "data": {}}

    # Find effective DB path
    db_path = "/var/www/html/database/database.sqlite"
    env_path = Path("/var/www/html/.env")
    if env_path.exists():
        for line in env_path.read_text().splitlines():
            if line.startswith("DB_DATABASE="):
                db_path = line.split("=", 1)[1].strip()
                break

    conn, err = check_db(db_path)
    if err:
        report["errors"].append(err)
        print(json.dumps(report, indent=2))
        return 1

    report["data"]["db_path"] = db_path

    # PRAGMA integrity_check
    rows, err = query(conn, "PRAGMA integrity_check", "integrity_check")
    if err:
        report["errors"].append(err); print(json.dumps(report, indent=2)); return 1
    integrity = rows[0][0] if rows else "UNKNOWN"
    report["data"]["integrity_check"] = integrity
    if integrity != "ok":
        report["errors"].append(f"Integrity check failed: {integrity}")
        print(json.dumps(report, indent=2)); return 1

    # PRAGMA table_info(doors) — NOT 'DOOR-B'. DOOR-B is a door_id value.
    rows, err = query(conn, "PRAGMA table_info(doors)", "doors_schema")
    if err:
        report["errors"].append(err); print(json.dumps(report, indent=2)); return 1
    if not rows:
        report["errors"].append("Table 'doors' not found or empty schema")
        print(json.dumps(report, indent=2)); return 1

    schema = [(r[1], r[2]) for r in rows]
    report["data"]["doors_schema"] = [
        {"name": n, "type": t} for n, t in schema if n not in SENSITIVE_COLS
    ]

    # Detect IP column
    col_names = [n for n, _ in schema]
    ip_col = "ip_address" if "ip_address" in col_names else ("device_ip" if "device_ip" in col_names else None)
    report["data"]["ip_column_name"] = ip_col

    # Counts
    checks = [
        ("employee_count", "SELECT COUNT(*) FROM employees"),
        ("active_employee_count", "SELECT COUNT(*) FROM employees WHERE employment_status='ACTIVE'"),
        ("credential_count", "SELECT COUNT(*) FROM credential_records"),
        ("door_count", "SELECT COUNT(*) FROM doors"),
        ("access_log_count", "SELECT COUNT(*) FROM access_logs"),
        ("admin_count", "SELECT COUNT(*) FROM admins"),
    ]
    for label, sql in checks:
        rows, err = query(conn, sql, label)
        if err:
            report["errors"].append(err); print(json.dumps(report, indent=2)); return 1
        report["data"][label] = rows[0][0] if rows else None

    # Door details (no secrets)
    safe_cols = [c for c in ["door_id", "name", ip_col, "connection_status", "health_status", "last_checked_at", "building_id"] if c]
    sql = f"SELECT {','.join(safe_cols)} FROM doors"
    rows, err = query(conn, sql, "doors_detail")
    if err:
        report["errors"].append(err); print(json.dumps(report, indent=2)); return 1
    report["data"]["doors"] = [dict(zip(safe_cols, r)) for r in rows]

    # DOOR-B specific
    door_b = [d for d in report["data"]["doors"] if d.get("door_id") == "DOOR-B"]
    report["data"]["door_b"] = door_b[0] if door_b else None

    # FK check
    rows, err = query(conn, "PRAGMA foreign_key_check", "fk_check")
    if err:
        report["data"]["fk_check"] = f"FAILED: {err}"
    else:
        report["data"]["fk_violations"] = len(rows) if rows else 0

    conn.close()
    report["status"] = "SUCCESS"
    print(json.dumps(report, indent=2))
    return 0

if __name__ == "__main__":
    sys.exit(audit())
