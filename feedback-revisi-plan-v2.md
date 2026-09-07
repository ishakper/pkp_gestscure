# FEEDBACK / REVISI UNTUK IMPLEMENTATION PLAN v2 — Access Control & Biometric Management

Plan v2 sudah bagus, tapi tolong revisi/tambahkan poin-poin berikut sebelum mulai generate kode:

## 1. Amankan endpoint webhook ISAPI (PRIORITAS TINGGI)

`POST /api/v1/isapi/event-notification` menerima request langsung dari perangkat fisik Hikvision (Door A–D), bukan dari dashboard admin, sehingga **tidak bisa** dilindungi Sanctum Bearer token (device tidak melakukan login). Tanpa proteksi, siapa pun bisa mengirim POST palsu dan mencemari `access_logs`.

Tambahkan salah satu (atau kombinasi) berikut:
- **Shared secret per device**: setiap device mengirim header custom (misal `X-Device-Secret`), dicocokkan dengan secret yang disimpan di `.env` per `door_id` (`DOOR_A_WEBHOOK_SECRET`, dst.)
- **IP Whitelist Middleware**: buat middleware khusus (`RestrictToDeviceIp`) yang hanya menerima request dari 4 IP terminal (`192.168.90.11`–`.14`) dan central server, tolak selain itu dengan `403`
- Endpoint ini dikecualikan dari middleware `auth:sanctum`, tapi tetap wajib lewat middleware keamanan di atas

## 2. Auto-update status koneksi pintu (PRIORITAS TINGGI)

`connection_status` saat ini hanya bisa diubah manual lewat `PATCH .../status`. Tambahkan **scheduled job** menggunakan Laravel Task Scheduler:

- Buat job/command baru, misal `PingDoorDevicesJob` atau `php artisan doors:ping`
- Jadwalkan berjalan setiap 1 menit (`$schedule->job(new PingDoorDevicesJob)->everyMinute()`)
- Job ini memanggil `HikvisionIsapiService::pingDevice()` ke tiap 4 pintu, lalu update `connection_status` dan `last_checked_at` secara otomatis
- `PATCH .../status` tetap dipertahankan sebagai override manual (misal mode maintenance), tapi beri flag terpisah (misal `is_manual_override`) supaya tidak langsung ditimpa oleh hasil ping otomatis berikutnya

## 3. Constraint database tambahan

- Tambahkan **unique composite index** pada `door_assignments` untuk kombinasi `employee_id` + `door_id`, agar tidak ada duplikat assignment
- Tambahkan **index** pada `access_logs` untuk kolom `door_id`, `employee_id`, dan `timestamp` — tabel ini akan bertumbuh cepat (1 baris per tap) dan sering difilter berdasarkan kolom-kolom tersebut di endpoint `access-logs`

## 4. Automated testing (Pest/PHPUnit)

Verification plan saat ini hanya manual cURL check. Tambahkan minimal **feature test** untuk skenario kritis:
- Login sukses & gagal (`401` untuk kredensial salah)
- CRUD employee (create, update, delete, list dengan pagination)
- Assign & revoke door access (memastikan job `SyncDoorAccessJob` ter-dispatch)
- `building_admin` ditolak (`403`) saat akses data gedung lain
- Webhook ISAPI ditolak tanpa secret/IP yang valid

## 5. Rate limiting

Tambahkan middleware `throttle` pada endpoint publik yang rawan disalahgunakan:
- `POST /api/v1/auth/login` — cegah brute force (misal 5 request/menit per IP)
- `POST /api/v1/isapi/event-notification` — cegah flood dari device yang malfungsi/disalahgunakan

## 6. Dokumentasi tambahan

Setelah semua poin di atas diimplementasikan, sertakan juga catatan singkat di README mengenai:
- Cara menjalankan scheduler (`php artisan schedule:work` untuk local dev)
- Cara menjalankan queue worker (`php artisan queue:work`)
- Daftar environment variable baru yang perlu diisi (secret webhook per device, dll.)

---

Tolong revisi Implementation Plan v2 dengan menyertakan 6 poin di atas, lalu lanjutkan proses generate kode berdasarkan plan yang sudah direvisi.
