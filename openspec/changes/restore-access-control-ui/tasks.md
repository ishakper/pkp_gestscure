## 1. Audit dan Kontrak

- [x] 1.1 Audit layout v1 dari riwayat Git dan UI saat ini, lalu catat pola yang dipertahankan dalam implementasi serta verifikasi tidak ada backend Sprint 1–12 yang diganti.
- [x] 1.2 Tambahkan targeted test untuk hierarki sidebar, state kartu perangkat, modal remote unlock, dan refresh aman; verifikasi test gagal sebelum implementasi terkait.

## 2. Access-Control Operations UI

- [x] 2.1 Kelompokkan sidebar menjadi Access Control, HR & Workforce, Attendance, dan Security dengan item permission-aware; verifikasi Blade cache dan test portal lulus.
- [x] 2.2 Padatkan Dashboard Terpusat dan kartu Devices & Doors dengan status, last checked, action hierarchy, loading/empty/error state; verifikasi DOM contract test lulus.
- [x] 2.3 Sempurnakan tabel Hak Akses Karyawan dan modal Assign Doors agar menampilkan data akses/sinkronisasi yang ringkas; verifikasi feature test karyawan dan door sync lulus.
- [x] 2.4 Sempurnakan Security & Audit menjadi tabel/timeline aman yang menggabungkan access log dan activity log; verifikasi tidak ada raw payload atau credential dirender.

## 3. Realtime dan Remote Unlock

- [x] 3.1 Ganti confirm/alert remote unlock dengan modal konfirmasi fisik, loading state, toast, dan refresh status/log; verifikasi success, failure, cancel, offline, serta permission state.
- [x] 3.2 Pertahankan SSE dan tambahkan polling data aplikasi berinterval aman tanpa pemeriksaan ISAPI otomatis; verifikasi test/inspection membuktikan polling tidak memanggil check-all.

## 4. Validasi dan Delivery

- [x] 4.1 Jalankan targeted test UI/backend, lint JavaScript, Blade cache, dan build asset; verifikasi seluruhnya lulus.
- [x] 4.2 Jalankan satu full regression dan secret/diff scan; verifikasi tidak ada kegagalan atau rahasia baru.
- [x] 4.3 Clear cache, rebuild Docker app tanpa migrasi destruktif, verifikasi `/login` HTTP 200 dan dashboard terautentikasi dapat dirender tanpa melakukan perintah hardware.
- [x] 4.4 Perbarui bukti OpenSpec, commit hanya file perubahan ini, push ke origin/main, dan catat status pipeline yang benar-benar dapat diamati.
