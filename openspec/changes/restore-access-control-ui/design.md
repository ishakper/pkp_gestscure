## Context

Lihat `proposal.md` untuk motivasi. Dashboard saat ini adalah satu Blade besar dengan JavaScript client tunggal, SSE `/live-stream`, API Sanctum, dan komponen CSS internal. Backend Hikvision, attendance, RBAC, audit, dan modul Sprint 1–12 telah teruji dan tidak boleh diganti. Worktree juga memuat perubahan Sprint 13 yang tidak terkait dan harus tetap tidak tersentuh.

## Goals / Non-Goals

**Goals:**

- Mengubah hierarki visual dan interaksi pada empat alur akses-kontrol utama.
- Memakai endpoint dan event pipeline yang ada dengan state UI yang aman.
- Menjaga kompatibilitas semua tab HR/attendance serta responsive drawer.
- Memvalidasi markup, perilaku JavaScript, RBAC, dan regresi backend.

**Non-Goals:**

- Tidak mengubah skema database, credential, alamat runtime DOOR-B, atau protokol ISAPI.
- Tidak mengeksekusi remote unlock fisik maupun menganggap mock sebagai bukti hardware.
- Tidak memecah seluruh dashboard menjadi framework frontend baru pada perubahan ini.

## Decisions

### Pertahankan Blade dan JavaScript yang ada
Refactor dilakukan pada komponen yang ada agar risiko regresi lintas modul rendah. Alternatif migrasi SPA ditolak karena memperluas scope dan mengubah kontrak deployment.

### Pisahkan status aktual dari maintenance override
Warna/ketersediaan tombol mengikuti `connection_status` hasil pemeriksaan perangkat terakhir. Override ditampilkan sebagai mode pemeliharaan yang berbeda dan tidak digunakan untuk mengklaim terminal online.

### Modal remote unlock menggantikan `confirm()` dan `alert()`
Modal memungkinkan identitas pintu, warning fisik, loading state, dan pesan aman yang konsisten. Endpoint backend tidak berubah dan tetap mengaudit hasil sukses.

### Realtime memakai SSE dan polling data aplikasi
SSE yang ada tetap menjadi jalur utama untuk event access log. Fallback periodik hanya mengambil endpoint daftar/metrik/status tersimpan; pemeriksaan fisik ISAPI selalu eksplisit.

### Sidebar dikelompokkan tanpa mengubah permission backend
Blade tetap menyaring setiap item berdasarkan permission catalogue. Heading kelompok hanya presentasional dan tidak memberi akses baru.

## Risks / Trade-offs

- [Blade/JS berukuran besar rentan konflik] → Gunakan perubahan lokal, tes kontrak DOM, dan jangan menyentuh modul tidak terkait.
- [Status tersimpan dapat menjadi stale] → Tampilkan `last_checked_at` dan sediakan Cek Koneksi eksplisit.
- [SSE terputus] → Tampilkan status koneksi dan fallback polling API aplikasi dengan interval aman.
- [Tombol fisik salah sasaran] → Modal menampilkan kode/nama pintu dan backend tetap melakukan authorization.

## Migration Plan

1. Terapkan refactor Blade/JavaScript tanpa migrasi data.
2. Clear cache dan build asset produksi.
3. Jalankan targeted feature/UI contract tests dan satu full regression.
4. Rebuild container aplikasi tanpa menjalankan operasi database destruktif.
5. Rollback dilakukan dengan revert commit UI; backend dan data tidak memerlukan rollback.
