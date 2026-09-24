-- Seed test data matching KPI targets
-- TERDAFTAR = 96 (source employees)
-- CARD_CONFIRMED = 75 (card + confirmed_from_backup)
-- FINGERPRINT_EXPECTED = 13 (fingerprint + expected_from_backup)
-- NEEDS_VERIFICATION = 8 (review OR conflict)

INSERT INTO employees (
  id, name, nik, employee_id, email, phone, position_id, division_id, building_id,
  employment_status, source_person_number, department, role_jabatan,
  credential_method, credential_status, card_registered, card_count, card_type,
  fingerprint_verified, created_at, updated_at
) SELECT
  value as id,
  'Employee ' || value as name,
  'NIK' || PRINTF('%06d', value) as nik,
  'EMP' || PRINTF('%06d', value) as employee_id,
  'emp' || value || '@pkp.co.id' as email,
  '0812345678' || PRINTF('%02d', value) as phone,
  1 as position_id,
  1 as division_id,
  1 as building_id,
  'ACTIVE' as employment_status,
  'SRC' || PRINTF('%06d', value) as source_person_number,
  'General' as department,
  'Staff' as role_jabatan,
  CASE 
    WHEN value <= 75 THEN 'card'
    WHEN value <= 88 THEN 'fingerprint'
    ELSE 'review'
  END as credential_method,
  CASE
    WHEN value <= 75 THEN 'confirmed_from_backup'
    WHEN value <= 88 THEN 'expected_from_backup'
    ELSE 'conflict'
  END as credential_status,
  CASE WHEN value <= 75 OR (value >= 89 AND value <= 95) THEN 1 ELSE 0 END as card_registered,
  CASE WHEN value <= 75 OR (value >= 89 AND value <= 95) THEN 1 ELSE 0 END as card_count,
  CASE WHEN value <= 75 OR (value >= 89 AND value <= 95) THEN 'normalCard' ELSE NULL END as card_type,
  0 as fingerprint_verified,
  datetime('now') as created_at,
  datetime('now') as updated_at
FROM (
  WITH RECURSIVE cnt(value) AS (
    SELECT 1
    UNION ALL
    SELECT value+1 FROM cnt WHERE value < 96
  )
  SELECT value FROM cnt
);
