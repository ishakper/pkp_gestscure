## Purpose

Menyediakan pengalaman operasional akses pintu yang ringkas, permission-aware, responsif, dan berfokus pada kondisi fisik terminal tanpa menghilangkan modul bisnis SecureGate.

## ADDED Requirements

### Requirement: Hierarki navigasi operasional
Sidebar SHALL mengelompokkan menu yang diizinkan ke Access Control, HR & Workforce, Attendance, dan Security serta SHALL memprioritaskan Dashboard Terpusat, Devices & Doors, Hak Akses Karyawan, dan Security & Audit.

#### Scenario: Pengguna membuka dashboard
- **WHEN** pengguna terautentikasi membuka dashboard
- **THEN** sidebar hanya menampilkan menu sesuai permission backend dan item aktif terlihat jelas

### Requirement: Kartu perangkat mencerminkan data tersimpan aktual
Dashboard dan halaman Devices & Doors SHALL menampilkan identitas, lokasi, IP, model, jumlah pengguna, status koneksi, kondisi pintu, dan waktu pemeriksaan terakhir dari data perangkat, tanpa menampilkan credential.

#### Scenario: Terminal offline
- **WHEN** terminal memiliki status koneksi offline
- **THEN** kartu menampilkan status merah, kondisi Device Offline, tombol unlock nonaktif, dan tombol Cek Koneksi tetap aktif

### Requirement: Akses karyawan padat dan dapat dioperasikan
Halaman Hak Akses Karyawan SHALL menyediakan tabel ringkas, pencarian, filter, pagination, status biometrik, pintu terpasang, status sinkronisasi, dan tindakan permission-aware.

#### Scenario: Admin mengelola pintu karyawan
- **WHEN** admin berwenang membuka modal Assign Doors
- **THEN** sistem menampilkan kartu pintu beserta lokasi, status koneksi, akses saat ini, status sinkronisasi, serta tindakan Simpan dan Batal

### Requirement: Security dan audit dapat dibaca
Tampilan Security & Audit SHALL menyajikan log akses dan aktivitas fisik sebagai tabel/timeline yang terbaca tanpa payload mentah, exception internal, atau rahasia.

#### Scenario: Perintah fisik telah diaudit
- **WHEN** remote unlock, pemeriksaan koneksi, override, atau sinkronisasi dicatat
- **THEN** pengguna berwenang dapat melihat waktu, aktor, tindakan, subjek, status, sumber, dan ringkasan aman

### Requirement: Layout responsif dan memiliki state lengkap
Semua halaman prioritas SHALL menghindari horizontal overflow pada viewport mobile dan SHALL memiliki loading, empty, serta graceful error state.

#### Scenario: Data gagal dimuat
- **WHEN** permintaan API dashboard gagal
- **THEN** area terkait menampilkan pesan operasional aman dan kontrol retry tanpa merusak halaman lainnya
