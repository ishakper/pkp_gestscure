## Purpose

Menjaga dashboard operasi tetap mutakhir melalui event dan polling aman tanpa reload penuh atau membebani terminal Hikvision dengan pemeriksaan fisik berulang.

## ADDED Requirements

### Requirement: Pembaruan tanpa reload halaman
Dashboard SHALL memperbarui KPI, log akses, jumlah terminal online, dan Last Checked tanpa full-page reload menggunakan kanal realtime yang tersedia dan polling API tersimpan sebagai fallback.

#### Scenario: Event akses baru diterima
- **WHEN** kanal realtime memberi sinyal log baru
- **THEN** dashboard menyegarkan KPI dan daftar log terkait tanpa reload halaman

### Requirement: Polling tidak melakukan hammering perangkat
Polling otomatis SHALL membaca status tersimpan dan metrik aplikasi pada interval aman serta MUST NOT memanggil pemeriksaan ISAPI fisik otomatis.

#### Scenario: Dashboard dibiarkan terbuka
- **WHEN** interval refresh otomatis tercapai
- **THEN** UI mengambil data aplikasi, bukan menjalankan Cek Koneksi terhadap seluruh terminal

### Requirement: Refresh fisik tetap eksplisit
Pemeriksaan ISAPI fisik SHALL hanya dijalankan melalui tindakan Cek Koneksi atau Cek Semua Koneksi yang eksplisit dan permission-aware.

#### Scenario: Operator memeriksa satu terminal
- **WHEN** operator berwenang menekan Cek Koneksi
- **THEN** UI menjalankan satu pemeriksaan terminal, menampilkan loading state, lalu memperbarui status dan waktu pemeriksaan
