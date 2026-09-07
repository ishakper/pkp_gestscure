# Centralized Access Control & Biometric Management System

Sistem Centralized Access Control & Biometric Management terpusat berbasis **Laravel 10 (PHP 8.1)** yang menghubungkan **1 Web Dashboard terpusat** dengan **4 terminal access control fisik Hikvision DS-K1T804AMF** (protokol **ISAPI RESTful Communication**) yang tersebar di 4 gedung berbeda.

---

## 🏢 Topologi Jaringan & Perangkat

| Identitas | Lokasi Fisik | Alamat IP | Protokol / Port | Fungsi Utama |
|---|---|---|---|---|
| **Central Server** | Gedung B (Data Center) | `192.168.90.100` | HTTP/HTTPS (80/443) | Server Web Dashboard Laravel, Database, Queue Worker |
| **Door A** | Gedung A (Kantor Utama) | `192.168.90.11` | ISAPI Digest (Port 80) | Akses Pegawai Kantor & Tamu |
| **Door B** | Gedung B (IT & Infra) | `192.168.90.12` | ISAPI Digest (Port 80) | Akses Khusus Tim IT / Server Room (Restricted) |
| **Door C** | Gedung C (Operasional) | `192.168.90.13` | ISAPI Digest (Port 80) | Akses Operasional Lapangan |
| **Door D** | Gedung D (Produksi) | `192.168.90.14` | ISAPI Digest (Port 80) | Akses Tim Produksi & Pabrik |

---

## 🛠️ Tech Stack & Arsitektur

- **Framework**: Laravel 10 (PHP 8.1)
- **Autentikasi Dashboard**: Laravel Sanctum (Bearer Token)
- **Autentikasi Perangkat Fisik**: HTTP Digest Authentication per terminal ISAPI Hikvision
- **Database**: SQLite (Default Zero-Config Local Dev & Testing) / MySQL Ready
- **Background Jobs**: Laravel Queue Worker (`SyncDoorAccessJob`) untuk push biometrik & hak akses ke terminal
- **Auto-Ping Scheduler**: Laravel Task Scheduler (`doors:ping`) mengecek status koneksi pintu setiap 1 menit
- **Keamanan Webhook**: `VerifyDeviceWebhook` Middleware mengecek IP Whitelist & Header `X-Device-Secret`
- **Timezone**: `Asia/Jakarta` (WIB)

---

## 🚀 Panduan Instalasi & Memulai Proyek

### 1. Kloning & Setup Environment
```bash
# Salin konfigurasi environment
cp .env.example .env

# Generate Application Encryption Key
php artisan key:generate
```

### 2. Jalankan Migrasi & Database Seeder
```bash
# Membuat tabel dan mengisi data dummy realistis (4 doors, 12 employees, access logs)
php artisan migrate:fresh --seed
```

### 3. Jalankan Server Development & Background Workers
Buka 3 jendela terminal terpisah:

- **Terminal 1 (Web Dashboard Server)**:
  ```bash
  php artisan serve
  # Buka di browser: http://localhost:8000
  ```

- **Terminal 2 (Queue Worker Async ISAPI)**:
  ```bash
  php artisan queue:work
  ```

- **Terminal 3 (Auto-Ping Door Scheduler)**:
  ```bash
  php artisan schedule:work
  ```

---

## 🔑 Akun Demo Dashboard

Buka `http://localhost:8000/login`:

- **Super Admin**:
  - Email: `admin@accesscontrol.local`
  - Password: `password`
  - *Hak Akses*: Akses penuh ke seluruh gedung, pintu, dan karyawan.

- **Building Admin (Gedung A)**:
  - Email: `admin.gedunga@accesscontrol.local`
  - Password: `password`
  - *Hak Akses*: Dibatasi hanya untuk resource di Gedung A.

---

## 🧪 Jalankan Automated Testing (PHPUnit)

Jalankan seluruh pengujian unit dan fitur otomatis (13 Test Cases):
```bash
php artisan test
```

---

## 📡 API Endpoint Reference (`/api/v1/`)

Semua request API memerlukan Header:
```http
Authorization: Bearer <sanctum_token>
Accept: application/json
```

### 1. User Management Listing
`GET /api/v1/user-management/users` atau `GET /api/v1/user-management/employees`

**cURL Request**:
```bash
curl -X GET "http://localhost:8000/api/v1/user-management/users?page=1" \
  -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
  -H "Accept: application/json"
```

**Example Success Response**:
```json
{
  "status": "success",
  "pagination": {
    "current_page": 1,
    "per_page": 10,
    "total_records": 12,
    "total_pages": 2
  },
  "data": [
    {
      "id": 1,
      "user_id": "USR-1001",
      "employee_id": "USR-1001",
      "nik": "NIK-882101",
      "name": "Budi Santoso",
      "department": "IT Support",
      "role_jabatan": "Lead Infrastructure",
      "biometric_status": {
        "fingerprint_enrolled": true,
        "card_enrolled": true
      },
      "door_assign": [
        {
          "door_id": "DOOR-A",
          "door_name": "Door A - Akses Pegawai & Tamu",
          "sync_status": "synced",
          "sync_attempts": 1,
          "last_synced_at": "2026-09-04T08:30:00+07:00"
        },
        {
          "door_id": "DOOR-B",
          "door_name": "Door B - Restricted Server Room",
          "sync_status": "synced",
          "sync_attempts": 1,
          "last_synced_at": "2026-09-04T08:30:00+07:00"
        }
      ]
    }
  ]
}
```

---

### 2. Admin - View Doors Monitoring
`GET /api/v1/admin/doors`

**cURL Request**:
```bash
curl -X GET "http://localhost:8000/api/v1/admin/doors" \
  -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
  -H "Accept: application/json"
```

**Example Success Response**:
```json
{
  "status": "success",
  "total_doors": 4,
  "data": [
    {
      "id": 1,
      "door_id": "DOOR-A",
      "door_name": "Door A - Akses Pegawai & Tamu",
      "location": "Gedung A (Kantor Utama)",
      "device_ip": "192.168.90.11",
      "device_model": "DS-K1T804AMF",
      "connection_status": "online",
      "is_manual_override": false,
      "last_checked_at": "2026-09-04T09:00:00+07:00",
      "total_assigned_users": 9
    },
    {
      "id": 2,
      "door_id": "DOOR-B",
      "door_name": "Door B - Restricted Server Room",
      "location": "Gedung B (IT & Infra)",
      "device_ip": "192.168.90.12",
      "device_model": "DS-K1T804AMF",
      "connection_status": "online",
      "is_manual_override": false,
      "last_checked_at": "2026-09-04T09:00:00+07:00",
      "total_assigned_users": 4
    }
  ]
}
```

---

### 3. Admin - Access Logs History
`GET /api/v1/admin/access-logs`

**Query Parameters**: `door_id`, `nik` / `user`, `start_date`, `end_date`, `limit`

**cURL Request**:
```bash
curl -X GET "http://localhost:8000/api/v1/admin/access-logs?door_id=DOOR-D&limit=10" \
  -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
  -H "Accept: application/json"
```

**Example Success Response**:
```json
{
  "status": "success",
  "total_records": 10,
  "data": [
    {
      "log_id": "LOG-20260904-001",
      "door_id": "DOOR-D",
      "door_name": "Door D - Akses Pabrik & Produksi",
      "device_ip": "192.168.90.14",
      "user": {
        "nik": "NIK-882104",
        "name": "Dewi Lestari",
        "department": "Produksi"
      },
      "verify_method": "Fingerprint",
      "access_status": "Granted",
      "timestamp": "2026-09-04T08:45:12+07:00"
    }
  ]
}
```

---

### 4. ISAPI Physical Device Tap Event Webhook Listener
`POST /api/v1/isapi/event-notification`

**Headers Wajib**: `X-Device-Secret: <secret_door_key>`

**cURL Request**:
```bash
curl -X POST "http://localhost:8000/api/v1/isapi/event-notification" \
  -H "X-Device-Secret: secret_door_a_9981" \
  -H "Content-Type: application/json" \
  -d '{
    "door_id": "DOOR-A",
    "user": "NIK-882101",
    "verify_method": "Fingerprint",
    "access_status": "Granted"
  }'
```

**Example Success Response**:
```json
{
  "status": "success",
  "message": "Event notifikasi tap akses berhasil dicatat",
  "data": {
    "log_id": "LOG-20260904091522-AB12",
    "door_id": "DOOR-A",
    "door_name": "Door A - Akses Pegawai & Tamu",
    "employee_name": "Budi Santoso",
    "access_status": "Granted",
    "timestamp": "2026-09-04T09:15:22+07:00"
  }
}
```

---

### 5. Manual Batch Retry Sync
`POST /api/v1/admin/door-assignments/sync`

**cURL Request**:
```bash
curl -X POST "http://localhost:8000/api/v1/admin/door-assignments/sync" \
  -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
  -H "Accept: application/json"
```

---

## 🛑 Standard Error Response Format

Semua error dikembalikan dalam format JSON standar konsisten:

```json
{
  "status": "error",
  "code": 422,
  "message": "Validasi gagal",
  "errors": {
    "employee_id": ["The employee_id field is required."]
  }
}
```

- **401**: Unauthenticated (Token Sanctum tidak valid/kadaluarsa).
- **403**: Forbidden (RBAC / IP & Webhook secret ditolak).
- **404**: Resource Not Found.
- **422**: Validation Exception.
- **503**: Device Offline / Service Unavailable.
