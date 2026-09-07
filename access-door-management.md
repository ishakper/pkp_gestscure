# PROMPT UNTUK ANTIGRAVITY — Sistem Centralized Access Control & Biometric Management

## KONTEKS PROYEK

Buatkan saya sebuah backend web application menggunakan **Laravel** untuk sistem **Centralized Access Control & Biometric Management** yang menghubungkan 1 Web Dashboard terpusat dengan 4 terminal access control fisik (perangkat **Hikvision DS-K1T804AMF**, protokol **ISAPI RESTful Communication**) yang tersebar di 4 gedung berbeda.

Sistem ini digunakan untuk mengelola data karyawan, hak akses pintu per gedung, status enrollment biometrik (fingerprint & card), status sinkronisasi ke perangkat fisik, serta riwayat log akses (tap presensi/sidik jari).

## TOPOLOGI JARINGAN & PERANGKAT

| Identitas | Lokasi Fisik | Alamat IP | Protokol/Port | Fungsi |
|---|---|---|---|---|
| Central Server | Gedung B (Data Center) | 192.168.90.100 | HTTP/HTTPS (80/443) | Server Laravel, Database, Queue Worker |
| Door A | Gedung A (Kantor Utama) | 192.168.90.11 | ISAPI / Port 80 | Akses Pegawai Kantor & Tamu |
| Door B | Gedung B (IT & Infra) | 192.168.90.12 | ISAPI / Port 80 | Akses Khusus Tim IT / Restricted Area |
| Door C | Gedung C (Operasional) | 192.168.90.13 | ISAPI / Port 80 | Akses Operasional Lapangan |
| Door D | Gedung D (Produksi) | 192.168.90.14 | ISAPI / Port 80 | Akses Tim Produksi & Pabrik |

## TECH STACK YANG DIGUNAKAN

- **Framework:** Laravel (versi terbaru yang stabil)
- **Autentikasi API:** Laravel Sanctum (Bearer Token)
- **Database:** MySQL
- **Response format:** Laravel API Resource dengan skema paginasi JSON standar
- **Background job:** Queue Worker untuk push template biometrik/ISAPI ke perangkat fisik
- **Integrasi eksternal:** HTTP Client Laravel untuk komunikasi ke terminal ISAPI Hikvision (mock dulu, real integration menyusul)

## STRUKTUR DATABASE YANG DIBUTUHKAN

Tolong rancang migration & model untuk minimal entitas berikut (boleh disesuaikan/dinormalisasi sesuai best practice Laravel):

1. **users** — data karyawan: `user_id` (kode unik, misal USR-1001), `nik`, `name`, `department`, `role`, timestamps
2. **biometric_status** — relasi 1:1 ke users: `fingerprint_enrolled` (boolean), `card_enrolled` (boolean)
3. **doors** — data 4 pintu: `door_id` (DOOR-A s/d DOOR-D), `door_name`, `location`, `device_ip`, `device_model` (default "DS-K1T804AMF"), `connection_status` (online/offline)
4. **door_assignments** — pivot user ↔ door: `user_id`, `door_id`, `sync_status` (misal: synced, pending, failed)
5. **access_logs** — riwayat tap akses: `log_id`, `door_id`, `user_id` (nik), `verify_method` (Fingerprint/Card), `access_status` (Granted/Denied), `timestamp`

## ENDPOINT API YANG HARUS DIBUAT (sesuai dokumen spesifikasi mockup)

Semua endpoint di bawah wajib:
- Prefix versi: `/api/v1/...`
- Header wajib: `Authorization: Bearer <token_sanctum>` dan `Accept: application/json`
- Response sukses menggunakan format `{ "status": "success", ... }` dan gunakan Laravel API Resource + pagination standar Laravel untuk endpoint listing

### 1. UserManagement — Listing User & Door Assign
`GET /api/v1/user-management/users`

Menampilkan daftar seluruh karyawan terdaftar beserta status enrollment biometrik dan daftar hak akses pintu (Door A–D) beserta status sinkronisasi ISAPI ke perangkat. Response mengandung `pagination` (current_page, per_page, total_records) dan array `data` berisi tiap user dengan nested `biometric_status` dan `door_assign` (array berisi `door_id`, `door_name`, `sync_status`).

### 2. Admin — View Doors
`GET /api/v1/admin/doors`

Menampilkan monitoring status perangkat fisik dari ke-4 gedung: `door_id`, `door_name`, `location`, `device_ip`, `device_model`, `connection_status`, dan `total_assigned_users` (hasil count dari relasi door_assignments). Sertakan juga field `total_doors` di root response.

### 3. Admin — Door Access Log
`GET /api/v1/admin/access-logs`

Menampilkan riwayat log akses dari seluruh terminal 4 gedung, dengan filter fleksibel via query parameter, contoh: `?door_id=DOOR-D&limit=10`. Filter yang harus didukung: per `door_id`, per rentang tanggal, dan per `user` (nik). Setiap item log memuat `log_id`, `door_id`, `door_name`, `device_ip`, nested `user` (nik, name, department), `verify_method`, `access_status`, `timestamp`.

## KEBUTUHAN TAMBAHAN

- Buatkan **route API** (`routes/api.php`) untuk ketiga endpoint di atas, dilindungi middleware `auth:sanctum`
- Buatkan **Controller** terpisah per pilar (`UserManagementController`, `AdminDoorController`, `AdminAccessLogController`)
- Buatkan **API Resource class** untuk setiap response (UserResource, DoorResource, AccessLogResource) agar format JSON konsisten dan rapi
- Buatkan **Seeder/Factory** dengan data dummy yang merepresentasikan skenario di dokumen (4 pintu, minimal ±10 user dengan variasi department: IT Support, Produksi, Operasional, dsb, dan beberapa access log)
- Siapkan struktur dasar untuk **queue job** (misal `SyncDoorAccessJob`) yang nantinya bertugas push data assignment user ke terminal ISAPI Hikvision secara asynchronous
- Ikuti konvensi penamaan dan struktur folder standar Laravel (Controllers, Models, Resources, Requests, Jobs, Database/Migrations, Database/Seeders)
- Tambahkan validasi request dasar menggunakan Form Request class dimana relevan
- Sertakan file `.env.example` dengan variabel koneksi database dan placeholder untuk IP masing-masing terminal (DOOR_A_IP, DOOR_B_IP, dst.)

## OUTPUT YANG DIHARAPKAN

Berikan struktur project Laravel lengkap (migration, model, controller, resource, route, seeder, dan job) yang siap dijalankan dengan `php artisan migrate --seed`, beserta contoh cURL/Postman request untuk ketiga endpoint di atas yang hasilnya sesuai dengan contoh response pada dokumen spesifikasi.
