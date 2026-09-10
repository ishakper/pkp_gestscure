## Purpose

Menjamin perintah remote unlock dari dashboard memiliki konfirmasi eksplisit, feedback yang aman, pembatasan izin, dan tidak pernah membocorkan detail credential perangkat.

## ADDED Requirements

### Requirement: Konfirmasi relay fisik
UI SHALL meminta konfirmasi modal yang menyebut kode dan nama pintu serta memperingatkan bahwa relay fisik akan diaktifkan sebelum mengirim perintah unlock.

#### Scenario: Operator membatalkan unlock
- **WHEN** operator menutup atau membatalkan modal
- **THEN** UI tidak mengirim permintaan remote unlock

### Requirement: Siklus permintaan unlock aman
Selama permintaan berlangsung UI SHALL menonaktifkan tombol dan menampilkan Membuka..., lalu SHALL memulihkan state dan menampilkan toast aman setelah berhasil atau gagal.

#### Scenario: Unlock berhasil
- **WHEN** endpoint mengembalikan hasil sukses terverifikasi
- **THEN** UI menampilkan toast sukses, menutup modal, dan menyegarkan status/log terkait

#### Scenario: Unlock gagal
- **WHEN** endpoint menolak atau gagal mengeksekusi perintah
- **THEN** UI memulihkan kontrol dan menampilkan pesan bersih tanpa exception atau credential

### Requirement: Unlock mengikuti status dan izin
UI SHALL hanya mengaktifkan remote unlock untuk pengguna berwenang dan terminal online, sementara backend MUST tetap menjadi otoritas final.

#### Scenario: Terminal offline
- **WHEN** kartu terminal berstatus offline
- **THEN** tombol Buka Pintu terlihat tetapi disabled dengan tooltip Terminal belum terhubung
