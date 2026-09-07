# PROMPT UNTUK ANTIGRAVITY (v2) — Sistem Centralized Access Control & Biometric Management

## KONTEKS PROYEK

Bangun full backend + web dashboard menggunakan **Laravel 10 (PHP 8.1)** untuk sistem **Centralized Access Control & Biometric Management** yang menjembatani 1 Web Dashboard terpusat dengan 4 terminal access control fisik **Hikvision DS-K1T804AMF** (protokol **ISAPI RESTful**) yang tersebar di 4 gedung.

## TECH STACK

1. **Backend:** Laravel 10
2. **Autentikasi Dashboard (Admin):** Laravel Sanctum (Bearer Token)
3. **Autentikasi ke Perangkat Fisik (Device):** HTTP Digest Authentication per device (bukan Bearer — ini standar protokol ISAPI Hikvision), kredensial per pintu disimpan di `.env`
4. **Database:** MySQL (default), buat portable ke SQLite untuk local dev
5. **Queue Worker:** Laravel Queue (`database` atau `redis` driver — **jangan pakai driver `sync`**, karena job harus benar-benar asinkron) untuk job `SyncDoorAccessJob`
6. **ISAPI Integration Service:** `HikvisionIsapiService` — implementasi HTTP ISAPI REST (method: `setUser`, `setUserAccessRight`, `pingDevice`) dengan **mock mode toggle** untuk testing tanpa hardware fisik
7. **Response Wrapper:** format standar sukses `{ "status": "success", "pagination": {...}, "data": [...] }`
8. **Frontend:** Web Dashboard (Blade + CSS + JS) untuk monitoring pintu real-time, manajemen akses karyawan, viewing log, dan test simulator ISAPI
9. **Timezone:** set `Asia/Jakarta` di `config/app.php` agar timestamp log akses sesuai WIB

## PEMISAHAN ENTITAS PENTING (perbaikan dari draft sebelumnya)

Jangan gabungkan akun login dashboard dengan data karyawan subjek access control — keduanya punya siklus hidup dan kebutuhan data yang berbeda:

- **`Admin`** (tabel `admins`) — akun yang login ke Web Dashboard: `id`, `name`, `email`, `password`, `role` (`super_admin` / `building_admin`), `assigned_building` (nullable, dipakai kalau role `building_admin`), timestamps.
- **`Employee`** (tabel `employees`) — subjek dari access control (bukan user login sistem): `id`, `employee_id` (misal `USR-1001`), `nik`, `name`, `department`, `role_jabatan`, timestamps. **Tidak punya kolom password.**

## STRUKTUR DATABASE LENGKAP

1. **admins** — lihat di atas
2. **employees** — lihat di atas
3. **biometric_statuses** — relasi 1:1 ke `employees`: `employee_id` (FK), `fingerprint_enrolled` (bool), `card_enrolled` (bool)
4. **doors** — 4 pintu: `door_id` (`DOOR-A`..`DOOR-D`), `door_name`, `location`, `device_ip`, `device_model` (default `"DS-K1T804AMF"`), `connection_status` (`online`/`offline`), `last_checked_at`
5. **door_assignments** — pivot employee ↔ door: `employee_id`, `door_id`, `sync_status` (`synced`, `pending`, `failed`), `sync_attempts` (int, default 0), `last_synced_at`
6. **access_logs** — riwayat tap: `log_id`, `door_id`, `employee_id` (nullable — untuk kasus tap gagal/tidak dikenali), `verify_method` (`Fingerprint`/`Card`), `access_status` (`Granted`/`Denied`), `timestamp`
7. **activity_logs** (audit trail) — mencatat aksi admin: `admin_id`, `action` (misal `assign_door_access`, `revoke_door_access`, `update_employee`), `subject_type`, `subject_id`, `description`, `timestamp`

## API ENDPOINTS (`/api/v1/`)

### Auth
- `POST /api/v1/auth/login` — login admin, return Sanctum Bearer token
- `GET /api/v1/auth/me` — data admin yang sedang login
- `POST /api/v1/auth/logout` — revoke token aktif

### User Management (CRUD lengkap, bukan cuma listing)
- `GET /api/v1/user-management/employees` — listing dengan pagination, nested `biometric_status`, dan `door_assign` (array `door_id`, `door_name`, `sync_status`)
- `POST /api/v1/user-management/employees` — tambah karyawan baru
- `PUT /api/v1/user-management/employees/{id}` — update data karyawan
- `DELETE /api/v1/user-management/employees/{id}` — hapus karyawan (soft delete)
- `POST /api/v1/user-management/employees/{id}/door-access` — assign akses pintu ke karyawan (trigger `SyncDoorAccessJob`)
- `DELETE /api/v1/user-management/employees/{id}/door-access/{door_id}` — revoke akses pintu tertentu

### Admin — Doors Monitoring
- `GET /api/v1/admin/doors` — list 4 pintu dengan `connection_status`, `total_assigned_users`, root `total_doors`
- `PATCH /api/v1/admin/doors/{door_id}/status` — override status koneksi secara manual (misal maintenance mode)

### Admin — Access Logs
- `GET /api/v1/admin/access-logs` — filter via query param: `door_id`, `nik`, `start_date`, `end_date`, `limit`; nested `employee` (`nik`, `name`, `department`)

### Sync & Hardware Webhook
- `POST /api/v1/admin/door-assignments/sync` — trigger ulang `SyncDoorAccessJob` (manual retry untuk assignment berstatus `failed`)
- `POST /api/v1/isapi/event-notification` — endpoint publik/khusus yang menerima notifikasi event tap dari perangkat Hikvision, dan otomatis mencatat ke `access_logs`

## STANDAR ERROR RESPONSE

Gunakan format konsisten untuk semua error, jangan cuma sukses saja yang distandarisasi:

```json
{
  "status": "error",
  "code": 422,
  "message": "Validasi gagal",
  "errors": { "field_x": ["pesan error"] }
}
```

Wajib di-handle secara eksplisit: `401` (token tidak valid), `403` (role tidak berwenang, khusus `building_admin` yang akses gedung lain), `404` (data tidak ditemukan), `422` (validasi request), `503` (device Hikvision offline saat sync).

## LOGIKA SYNC JOB (`SyncDoorAccessJob`)

- Kirim assignment ke device via `HikvisionIsapiService` (Digest Auth)
- Kalau device offline/timeout: retry otomatis dengan backoff (misal 3x percobaan, delay bertahap), increment `sync_attempts`
- Kalau tetap gagal setelah max retry: set `sync_status = 'failed'`, catat ke `activity_logs`, agar admin bisa lihat dan trigger manual retry dari dashboard
- Kalau sukses: set `sync_status = 'synced'`, update `last_synced_at`

## ROLE & PERMISSION (RBAC sederhana)

- `super_admin`: akses penuh ke semua gedung/pintu/karyawan
- `building_admin`: hanya bisa lihat/kelola data yang terkait `assigned_building`-nya (middleware/policy check di setiap request ke resource door/employee)

## SEEDER / DUMMY DATA

- 1 akun `super_admin` (`admin@accesscontrol.local` / password default)
- 4 pintu (`DOOR-A`..`DOOR-D`) dengan IP `192.168.90.11`–`192.168.90.14`
- 10+ karyawan lintas departemen (IT Support, Produksi, Operasional, HR) dengan variasi status biometrik
- Data door_assignments dengan variasi `sync_status` (termasuk beberapa `failed` untuk testing retry)
- Access log dummy yang realistis (Fingerprint/Card, Granted/Denied, timestamp WIB)

## HAL TEKNIS PENDUKUNG LAINNYA

- Konfigurasi CORS jika dashboard suatu saat dipisah jadi SPA terpisah
- Kredensial device (`DOOR_A_IP`, `DOOR_A_USER`, `DOOR_A_PASS`, dst.) di `.env` dan `.env.example`
- Form Request class untuk validasi (`LoginRequest`, `StoreEmployeeRequest`, `AssignDoorAccessRequest`, `AccessLogFilterRequest`)
- API Resource class (`EmployeeResource`, `DoorResource`, `AccessLogResource`)
- Controller terpisah per pilar (`AuthController`, `EmployeeController`, `AdminDoorController`, `AdminAccessLogController`, `DoorSyncController`, `IsapiWebhookController`)

## FITUR DASHBOARD WEB

- Kartu status real-time tiap pintu (online/offline, jumlah user ter-assign)
- Tabel & form manajemen karyawan + matrix hak akses per pintu
- Tabel access log interaktif dengan filter (gedung, tanggal, nik)
- Tool simulator ISAPI (trigger event tap palsu + manual sync trigger) untuk testing tanpa hardware
- Modal dokumentasi API / built-in API tester (contoh cURL per endpoint)
- Halaman activity log (audit trail aksi admin)

## VERIFIKASI

- `php artisan migrate:fresh --seed` berhasil tanpa error
- `php artisan route:list` menampilkan semua endpoint di atas
- Uji tiap endpoint via cURL/HTTP test, hasil sesuai format response yang didefinisikan (termasuk skenario error 401/403/422/503)
- Uji manual: buka dashboard di browser, cek kartu status pintu, tabel karyawan (pagination + CRUD), filter access log, trigger event tap simulasi dan pastikan log baru muncul, serta uji retry sync untuk assignment berstatus `failed`

## OUTPUT YANG DIHARAPKAN

Struktur project Laravel lengkap (migration, model, service, job, form request, resource, controller, route, seeder, view dashboard) yang siap dijalankan dengan `php artisan migrate:fresh --seed`, beserta contoh cURL/Postman collection untuk seluruh endpoint di atas.
