# PKP SecureGate - Sistem Manajemen Akses Pintu

**PKP SecureGate** adalah sistem terpusat manajemen kontrol akses pintu (*Centralized Access Control & Biometric Management System*) berbasis **Laravel 10 (PHP 8.1+)** yang menghubungkan web dashboard manajemen dengan terminal fisik / emulator kontrol akses **Hikvision DS-K1T804AMF** menggunakan protokol komunikasi **Hikvision ISAPI (RESTful & Digest Authentication)**.

Sistem ini mendukung pengelolaan izin akses karyawan (kartu RFID & sidik jari), monitoring status konektivitas terminal secara real-time, sinkronisasi log riwayat akses (*audit trail*), webhook notifikasi tap akses otomatis, serta mode simulasi mock terintegrasi.

---

## 💻 Kebutuhan Sistem (System Requirements)

Pastikan server atau komputer lokal Anda telah memenuhi persyaratan berikut:

- **PHP**: `^8.1` atau lebih baru (dengan ekstensi `php-sqlite3`, `php-curl`, `php-mbstring`, `php-xml`, `php-zip`, `php-pdo`)
- **Composer**: `^2.0` (Dependency Manager for PHP)
- **Node.js & NPM**: Node.js `^18.x` / `^20.x` & NPM `^9.x`
- **Web Browser**: Google Chrome, Mozilla Firefox, Microsoft Edge, atau browser modern lainnya
- **Database**: SQLite (default zero-config untuk dev/testing) atau MySQL 8.0+

---

## 🚀 Panduan Instalasi Cepat (Quick Start)

Ikuti langkah-langkah berikut untuk menginstal dan menjalankan proyek di lingkungan lokal:

```bash
# 1. Masuk ke direktori proyek
cd access-door-management

# 2. Instal dependensi PHP via Composer
composer install

# 3. Instal dependensi Node.js (opsional jika membuild asset)
npm install

# 4. Salin template konfigurasi environment
cp .env.example .env

# 5. Generate Application Encryption Key
php artisan key:generate

# 6. Jalankan database migration dan seeder data awal
php artisan migrate --seed

# 7. Jalankan server lokal Laravel
php artisan serve
```

Aplikasi web dashboard dapat diakses melalui browser di: **`http://localhost:8000`**

> **Catatan Background Worker (Opsional untuk Asynchronous Sync & Auto-Ping)**:
> - Jalankan Queue Worker: `php artisan queue:work`
> - Jalankan Scheduler Auto-Ping: `php artisan schedule:work`

---

## ⚙️ Konfigurasi ISAPI & Mock Mode

Integrasi terminal Hikvision dikendalikan melalui konfigurasi pada file `.env`:

### 1. Variabel Lingkungan Utama

| Variabel `.env` | Nilai Default | Keterangan |
|---|---|---|
| `HIKVISION_ISAPI_USE_MOCK` | `true` | Set `true` untuk mode simulasi mock lokal, atau `false` untuk koneksi langsung ke terminal fisik. |
| `HIKVISION_ISAPI_HOST` | `192.168.90.11` | IP default terminal kontrol akses fisik Hikvision (DS-K1T804AMF). |
| `HIKVISION_ISAPI_PORT` | `80` | Port HTTP ISAPI terminal (default: 80). |
| `HIKVISION_ISAPI_USERNAME` | `admin` | Username administratif terminal Hikvision. |
| `HIKVISION_ISAPI_PASSWORD` | `Hikvision@DoorA` | Password administratif terminal Hikvision. |
| `HIKVISION_ISAPI_MOCK_BASE_URL` | `http://localhost:8000/api/mock/isapi` | Base URL endpoint mock ISAPI lokal. |
| `ISAPI_CONNECT_TIMEOUT` | `5` | Batas waktu koneksi socket cURL (detik). |
| `ISAPI_REQUEST_TIMEOUT` | `10` | Batas waktu total request ISAPI (detik). |

### 2. Mekanisme Mock Mode & Pencegahan Deadlock Single-Thread

Pada server bawaan PHP (`php artisan serve`), proses berjalan secara *single-threaded*. Jika backend Laravel melakukan HTTP call via cURL ke `localhost:8000` miliknya sendiri saat melayani request browser, akan terjadi **deadlock / timeout (cURL error 28)** karena satu-satunya thread PHP terkunci menunggu dirinya sendiri.

Untuk mengatasi kendala tersebut:
- Saat **`HIKVISION_ISAPI_USE_MOCK=true`**, [`HikvisionIsapiService`](file:///d:/Magang/Project/access-door-management/app/Services/HikvisionIsapiService.php) **secara otomatis mengalihkan pemanggilan langsung ke internal controller** ([`App\Http\Controllers\Mock\HikvisionMockController`](file:///d:/Magang/Project/access-door-management/app/Http/Controllers/Mock/HikvisionMockController.php)) di dalam proses PHP yang sama tanpa memicu outgoing cURL HTTP network loop.
- Saat **`HIKVISION_ISAPI_USE_MOCK=false`**, service akan menggunakan `Http::withDigestAuth(...)` untuk terhubung ke IP perangkat fisik nyata.

---

## 🏢 Topologi Pintu & Jaringan (Physical Hardware Setup)

| Identitas | Lokasi Fisik | Alamat IP | Protokol / Autentikasi | Fungsi Utama |
|---|---|---|---|---|
| **Central Server** | Gedung B (Data Center) | `192.168.90.100` | HTTP/HTTPS (80/443) | Web Dashboard Laravel, REST API, Database |
| **Door A** | Gedung A (Kantor Utama) | `192.168.90.11` | ISAPI Digest (Port 80) | Akses Pegawai Kantor & Tamu |
| **Door B** | Gedung B (IT & Infra) | `192.168.90.12` | ISAPI Digest (Port 80) | Akses Khusus Ruang Server (Restricted) |
| **Door C** | Gedung C (Operasional) | `192.168.90.13` | ISAPI Digest (Port 80) | Akses Staf Operasional Lapangan |
| **Door D** | Gedung D (Produksi) | `192.168.90.14` | ISAPI Digest (Port 80) | Akses Pabrik & Tim Produksi |

---

## 🔑 Kredensial Akun Demo Dashboard

Buka URL: `http://localhost:8000/login`

- **Super Administrator**:
  - **Email**: `admin@accesscontrol.local`
  - **Password**: `password`
  - *Hak Akses*: Akses penuh ke seluruh gedung, pintu, hak akses kartu, dan audit log.

- **Building Admin (Gedung A)**:
  - **Email**: `admin.gedunga@accesscontrol.local`
  - **Password**: `password`
  - *Hak Akses*: Dibatasi hanya untuk resource dan pintu di Gedung A (RBAC Policy).

---

## 🧪 Menjalankan Automated Testing (PHPUnit)

Proyek ini dilengkapi dengan comprehensive test suite (Unit & Feature Tests) yang mencakup autentikasi, otorisasi RBAC, sinkronisasi pintu, integrasi ISAPI mock & real, webhook listener, dan API endpoint:

```bash
php artisan test
```

Contoh output:
```text
   PASS  Tests\Unit\ExampleTest
   PASS  Tests\Feature\ApiListingAndAuditTest
   PASS  Tests\Feature\AuthTest
   PASS  Tests\Feature\DashboardIntegrationTest
   PASS  Tests\Feature\DoorAccessSyncTest
   PASS  Tests\Feature\EmployeeCrudTest
   PASS  Tests\Feature\ExampleTest
   PASS  Tests\Feature\HikvisionIsapiServiceTest
   PASS  Tests\Feature\HikvisionMockTest
   PASS  Tests\Feature\IsapiWebhookTest
   PASS  Tests\Feature\RbacPolicyTest

  Tests:    40 passed (255 assertions)
  Duration: 1.80s
```

---

## 📡 Referensi Endpoint API Utama (`/api/`)

Semua request API terproteksi memerlukan Header:
```http
Authorization: Bearer <sanctum_token>
Accept: application/json
```

### 1. Manajemen Hak Akses Karyawan
- `GET /api/v1/user-management/users` — Daftar karyawan beserta status kartu RFID & pintu yang di-assign.
- `POST /api/v1/user-management/assign-doors` — Assign izin akses kartu ke pintu tertentu (memicu ISAPI sync).
- `POST /api/v1/user-management/revoke-doors` — Cabut (*revoke*) izin akses kartu dari seluruh pintu.

### 2. Monitoring & Cek Konektivitas Terminal
- `GET /api/v1/admin/doors` — Ringkasan status seluruh pintu, lokasi, IP, dan jumlah user terdaftar.
- `POST /api/v1/admin/doors/{door}/check-connection` — Cek status konektivitas ISAPI untuk pintu tertentu.
- `POST /api/v1/admin/doors/check-all-connections` — Cek serentak status konektivitas seluruh terminal pintu.

### 3. Log Riwayat Akses (*Audit Trail*)
- `GET /api/v1/admin/access-logs` — Riwayat tap akses pintu dengan filter status (`Granted` / `Denied`), tanggal, dan lokasi.
- `POST /api/v1/admin/access-logs/sync-hardware` — Menarik (*fetch events*) riwayat tap langsung dari terminal Hikvision ke database.

### 4. Webhook Notifikasi Real-Time Terminal
- `POST /api/v1/isapi/event-notification` — Endpoint penerima event push tap akses dari terminal fisik Hikvision (dilindungi header `X-Device-Secret`).

### 5. Mock Endpoint ISAPI Simulasi (`/api/mock/isapi/`)
- `GET /api/mock/isapi/System/status` — Simulasi status perangkat (`deviceStatus`).
- `PUT /api/mock/isapi/AccessControl/CardInfo/Record` — Simulasi registrasi/sinkronisasi kartu RFID (`syncCard`).
- `POST /api/mock/isapi/AccessControl/AcsEvent` — Simulasi penarikan log riwayat tap akses (`fetchAccessLogs`).

---

## 🛑 Format Standar Respons Error API

Semua kegagalan API dikembalikan dalam format JSON terstandarisasi:

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

- **401**: Unauthenticated (Token Sanctum tidak valid atau kadaluarsa).
- **403**: Forbidden (Pelanggaran RBAC / IP & Webhook Secret ditolak).
- **404**: Resource Not Found (Pintu atau karyawan tidak ditemukan).
- **422**: Validation Exception (Payload parameter tidak sesuai aturan).
- **503**: Device Offline / Service Unavailable.
