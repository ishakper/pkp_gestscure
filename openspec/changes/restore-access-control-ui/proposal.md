## Why

Antarmuka SecureGate telah berkembang menjadi portal multi-modul, tetapi alur operasional akses pintu utama kehilangan prioritas visual dan konsistensi interaksi yang dibutuhkan tim infrastruktur. Perubahan ini mengembalikan fokus control-room pada perangkat, akses karyawan, dan audit tanpa menurunkan arsitektur backend Sprint 1–12.

## What Changes

- Menata sidebar menjadi empat kelompok yang permission-aware: Access Control, HR & Workforce, Attendance, dan Security.
- Memadatkan Dashboard Terpusat agar KPI fisik, status terminal aktual, dan tindakan operasional menjadi prioritas.
- Menyempurnakan kartu Devices & Doors dengan status aktual, kondisi tombol yang aman, tindakan diagnose/log/sync, dan maintenance override yang jelas.
- Menyempurnakan tabel Hak Akses Karyawan, modal penugasan pintu, serta empty/loading/error state.
- Menyatukan Security & Audit menjadi tampilan operasional yang mudah dibaca tanpa payload mentah atau rahasia.
- Menggunakan SSE yang ada dan polling aman untuk pembaruan KPI/log, tanpa melakukan polling ISAPI fisik berfrekuensi tinggi.
- Mengganti konfirmasi remote unlock berbasis browser dengan modal konfirmasi yang menampilkan identitas pintu dan peringatan relay fisik.
- Mempertahankan seluruh endpoint, RBAC, audit, skema data, dan integrasi Hikvision yang sudah stabil.

## Capabilities

### New Capabilities

- `access-control-operations-ui`: Pengalaman control-room permission-aware untuk dashboard, perangkat, akses karyawan, dan security/audit.
- `safe-remote-unlock-ui`: Konfirmasi, status permintaan, penanganan hasil, dan pembatasan UI untuk perintah remote unlock fisik.
- `realtime-operations-dashboard`: Pembaruan KPI, status perangkat tersimpan, dan log akses secara aman tanpa full-page reload atau hammering perangkat.

### Modified Capabilities


## Impact

- Blade dashboard dan JavaScript dashboard client.
- Presentasi resource pintu serta endpoint activity/access log yang sudah ada bila diperlukan untuk kontrak UI.
- Feature tests dashboard, remote unlock, RBAC, dan realtime behavior.
- Tidak ada migrasi database, perubahan credential, atau operasi fisik pada terminal.
