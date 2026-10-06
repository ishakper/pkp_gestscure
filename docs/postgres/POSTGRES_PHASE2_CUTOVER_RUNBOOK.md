# PostgreSQL Phase 2 – Runbook impor data dan cutover

Dasar: branch `postgres-phase1` (laporan Phase 1:
`docs/postgres/POSTGRES_PHASE1_COMPATIBILITY_REPORT.md`) ditambah seri Phase 2.
Tanggal: 2026-10-06.

Runbook ini hanya dijalankan oleh operator di server, setelah ada persetujuan
cutover. Seri patch Phase 2 sendiri tidak menyentuh produksi, tidak mengubah
`docker-compose.yml`, dan produksi tetap SQLite sampai langkah C6 di bawah.

## 1. Alat yang dipakai

| perintah | sifat |
|---|---|
| `php artisan db:import-sqlite-to-pgsql --source-path=<salinan>` | Preflight saja, read-only di kedua sisi. Tidak menulis apa pun. |
| `... --execute --confirm-target=<nama_db>` | Impor dalam satu transaksi PostgreSQL. Gagal di titik mana pun berarti ROLLBACK, target kembali kosong. |
| `php artisan db:verify-row-parity` | Bandingkan jumlah baris per tabel (read-only). |

Jaminan `db:import-sqlite-to-pgsql`:

- Sumber dibuka dengan `PRAGMA query_only = ON` dalam satu transaksi baca;
  sha256 file sumber dicatat sebelum dan sesudah, impor dinyatakan gagal kalau berubah.
- Menolak `--source-path` yang sama dengan database SQLite aplikasi sendiri:
  selalu impor dari **salinan**.
- Menolak target yang tidak kosong (semua tabel selain `migrations`), target yang
  bukan `pgsql`, dan `--execute` tanpa `--confirm-target` yang sama dengan nama database.
- ID asli disalin apa adanya (termasuk celah ID), tabel induk dulu baru anak.
  Self-reference (`employees.supervisor_id`, `employee_documents.parent_document_id`)
  diisi setelah semua baris tabelnya masuk.
- Kolom teks dibaca dengan `CAST(col AS TEXT)` dan dikirim tanpa diubah:
  `"00001"` tetap `"00001"`, `"0001"` dan `"1"` tetap dua nilai berbeda, huruf besar/kecil
  tidak diubah. Tidak ada normalisasi identitas.
- Setelah insert, setiap sequence `bigserial` digeser dengan
  `setval(pg_get_serial_sequence(t,'id'), COALESCE(MAX(id),1), MAX(id) IS NOT NULL)`.
- Sebelum COMMIT, setiap tabel dibandingkan nilai per nilai dengan sumber
  (teks harus identik byte per byte; boolean, numeric dan tanggal dibandingkan
  berdasarkan nilai). Ada satu saja beda, seluruh impor di-ROLLBACK.

Preflight (§8 laporan Phase 1) memeriksa, untuk setiap baris dan kolom:

1. Tabel/kolom yang ada di sumber tapi tidak di target (data akan hilang) → ERROR.
2. Daftar `migrations` sumber dan target harus sama persis (versi kode sama) → ERROR kalau beda.
3. Kolom teks yang disimpan SQLite sebagai integer/real → WARN (disalin sebagai teks SQLite, tidak pernah ditambah nol).
4. Nilai enum/CHECK (`door_assignments.sync_status`, `doors.status`, `employees.credential_*`, dst.) → ERROR kalau di luar daftar.
5. Foreign key yatim (orphan) → ERROR, dengan contoh ID.
6. JSON tidak valid → ERROR.
7. Boolean selain 0/1 → ERROR.
8. Teks lebih panjang dari `varchar(n)` (SQLite tidak membatasi panjang) → ERROR.
9. NULL di kolom NOT NULL, duplikat untuk unique index, UTF-8 rusak, byte NUL → ERROR.
10. Tanggal/waktu yang tidak bisa dibaca PostgreSQL apa adanya, atau kolom `date` yang berisi jam selain 00:00:00 → ERROR.

## 2. Hasil rehearsal (scratch PostgreSQL 16, data sintetis representatif)

Sumber: file SQLite baru, dimigrasi dengan 49 migration yang sama, diisi data
sintetis sejumlah baseline produksi. Tidak ada data atau file produksi yang dipakai.
Data sengaja memuat: `employee_id` `00001`/`0001`/`1`, `pkp-0004` vs `PKP-0004`,
celah ID (max id employees 115 untuk 109 baris), supervisor yang menunjuk ke ID
lebih besar, timestamp ISO `2026-09-01T08:00:00.000000Z`, `hire_date` dengan
`00:00:00`, JSON dengan karakter non-ASCII, baris soft-deleted.

| langkah | hasil |
|---|---|
| Preflight (dry run) | lulus, 0 tulisan |
| `--execute` tanpa `--confirm-target` | ditolak, 0 tulisan |
| Impor | 68 tabel, 1,3 detik, semua baris cocok sebelum COMMIT, sha256 sumber tidak berubah |
| `db:verify-row-parity` dengan baseline | employees=109 buildings=5 doors=4 door_assignments=98 access_logs=4095 activity_logs=231 device_person_states=99, **All 68 tables match** |
| Identitas | `employees.employee_id` id 1/2/3 = `00001` / `0001` / `1`; `device_person_states.device_employee_no` = `00001`; `where employee_id = '00001'` → id 1, `= '1'` → id 3 |
| Sequence | employees 115 → insert berikutnya id 116; access_logs 4102 → 4103; buildings 9 → 10 |
| Impor kedua ke target yang sama | ditolak (target tidak kosong), 0 tulisan |
| Sumber rusak (enum `done`, FK yatim, JSON rusak, varchar 300, boolean 2, jam di kolom date) | 6 ERROR terdeteksi di preflight, 0 tulisan |
| Duplikat unique (index SQLite hilang, `nik` ganda) | ERROR di preflight |
| Kegagalan di tengah impor (CHECK tambahan di target) | ROLLBACK: employees=0, access_logs=0, migrations tetap 49 |

Test otomatis: `tests/Unit/PgsqlColumnValueTest.php` (kedua suite) dan
`tests/Feature/ImportSqliteToPgsqlCommandTest.php` (hanya suite PostgreSQL,
dilewati di SQLite).

Yang **belum** dibuktikan rehearsal ini: data produksi sungguhan. Karena itu
langkah R di bawah (rehearsal dengan salinan produksi di mesin non-produksi) wajib
sebelum cutover.

## 3. Prasyarat

- Image aplikasi dibangun dari commit yang memuat Phase 1 + Phase 2
  (Dockerfile sudah memasang `pdo_pgsql` dan `pdo_sqlite`).
- Server PostgreSQL 16 tersedia, database kosong `securegate`, role `securegate`
  pemilik database (tidak perlu superuser). Contoh service compose tambahan
  (file terpisah, misalnya `docker-compose.pgsql.yml`, bukan perubahan pada
  `docker-compose.yml`):

  ```yaml
  services:
    postgres:
      image: postgres:16-alpine
      restart: unless-stopped
      environment:
        POSTGRES_DB: securegate
        POSTGRES_USER: securegate
        POSTGRES_PASSWORD: ${PG_PASSWORD:?set PG_PASSWORD}
      volumes:
        - ./pgdata:/var/lib/postgresql/data
      networks:
        - securegate-net
  ```

- Password PostgreSQL hanya dari environment/secret store, tidak pernah di-commit.
- Ruang disk: salinan SQLite + backup + data PostgreSQL (± 3× ukuran file SQLite).
- Jendela waktu dengan lalu lintas rendah. Terminal Hikvision tetap membuka pintu
  secara lokal selama aplikasi berhenti; yang hilang hanya webhook/event yang
  dikirim saat downtime (lihat C8).

Variabel yang dipakai di bawah (bash di server):

```bash
APP_DIR=/home/infra/access-door-management
STAMP=$(date +%Y%m%d-%H%M%S)
CUT=$APP_DIR/database/cutover-$STAMP          # di dalam bind mount ./database
COMPOSE="docker compose -f docker-compose.yml -f docker-compose.pgsql.yml"
read -rs PG_PASSWORD && export PG_PASSWORD     # diketik, tidak tersimpan di history
export DB_PASSWORD=$PG_PASSWORD IMPORT_TARGET_PASSWORD=$PG_PASSWORD PARITY_TARGET_PASSWORD=$PG_PASSWORD
# -e NAMA tanpa nilai meneruskan nilai dari shell, jadi password tidak muncul di `ps`
PG_ENV="-e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=securegate -e DB_USERNAME=securegate -e DB_PASSWORD -e IMPORT_TARGET_PASSWORD -e PARITY_TARGET_PASSWORD"
EXPECT="--expect=employees=109 --expect=buildings=5 --expect=doors=4 --expect=door_assignments=98 --expect=access_logs=4095 --expect=activity_logs=231 --expect=device_person_states=99"
```

Catatan: angka `EXPECT` adalah baseline 2026-10-06. Saat cutover, angka
`access_logs`, `activity_logs` dan lainnya pasti sudah bertambah. Ambil angka
terbaru dari salinan beku (langkah C3) dan pakai itu sebagai `EXPECT`.

Semua perintah artisan dijalankan dengan `--entrypoint php` agar
`docker/entrypoint.sh` **tidak** ikut jalan (entrypoint menjalankan
`migrate --force` dan `chown` pada `database.sqlite`).

## R. Rehearsal dengan salinan produksi (T-1 hari, di mesin non-produksi)

1. Buat salinan SQLite produksi dengan cara yang sama seperti C2–C3, lalu pindahkan
   salinannya ke mesin staging. File asli tidak disentuh selain dibaca.
2. Di staging: PostgreSQL 16 kosong → `migrate --force` → preflight → `--execute` →
   `db:verify-row-parity` → smoke test UI (login, daftar karyawan, cari `00001`,
   log akses, dashboard).
3. Catat: durasi impor, semua WARN, jumlah baris per tabel. Kalau preflight memberi
   ERROR, **berhenti**: perbaiki datanya lewat keputusan bisnis terpisah (bukan oleh
   tool ini) dan ulangi rehearsal.
4. Hapus database staging setelah selesai.

## C. Cutover

| # | langkah | perintah | lanjut jika |
|---|---|---|---|
| C1 | Umumkan jendela maintenance. Catat jumlah job antrian | `$COMPOSE exec app php artisan queue:monitor database:default` atau cek tabel `jobs` | jobs = 0 (tunggu worker menghabiskan antrian) |
| C2 | Bekukan semua penulis SQLite | `$COMPOSE stop app` dan `docker stop pkp_securegate_alertstream_door_b` | `docker ps` tidak menampilkan keduanya |
| C3 | Salinan beku + checksum | lihat blok C3 | tidak ada file `-wal`/`-journal`; `integrity_check` = ok |
| C4 | Bangun skema kosong di PostgreSQL | `$COMPOSE run --rm --no-deps --entrypoint php $PG_ENV app artisan migrate --force` | 49 migration DONE |
| C5 | Preflight lalu impor | lihat blok C5 | "every row matched the source before COMMIT" dan parity OK |
| C6 | Alihkan aplikasi ke PostgreSQL | ubah `.env` server: `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_DATABASE=securegate`, `DB_USERNAME=securegate`, `DB_PASSWORD=...` lalu `$COMPOSE up -d app` | container healthy |
| C7 | Smoke test baca-saja, keputusan go/no-go | login admin, cari karyawan dengan identitas berawalan nol (mis. `00001`), daftar pintu, log akses terbaru, dashboard. Jangan jalankan `doors:smoke-webhook` di produksi: perintah itu menulis event uji ke `access_logs` | semua normal |
| C8 | Nyalakan alertstream dan catat titik awal | `docker start pkp_securegate_alertstream_door_b`; simpan output blok C8 | — |
| C9 | Backup emas | `$COMPOSE exec postgres pg_dump -U securegate -Fc securegate > $CUT/securegate-after-import.dump` | file ada |

Blok C3 (salinan beku):

```bash
mkdir -p "$CUT"
ls -la $APP_DIR/database/ | grep -E 'database.sqlite(-wal|-shm|-journal)?$'
# Harus hanya database.sqlite. Kalau ada -wal/-journal: JANGAN cp; nyalakan lagi app,
# hentikan dengan bersih, atau buat salinan dengan sqlite3 ".backup".
cp -p $APP_DIR/database/database.sqlite "$CUT/source.sqlite"
cp -p $APP_DIR/database/database.sqlite "$CUT/rollback-original.sqlite"
sha256sum $APP_DIR/database/database.sqlite "$CUT"/*.sqlite | tee "$CUT/sha256.txt"
$COMPOSE run --rm --no-deps --entrypoint php app -r '
  $p = new PDO("sqlite:/var/www/html/database/'"$(basename $CUT)"'/source.sqlite");
  echo $p->query("PRAGMA integrity_check")->fetchColumn(), PHP_EOL;
  foreach (["employees","buildings","doors","door_assignments","access_logs","activity_logs","device_person_states"] as $t)
    echo $t, "=", $p->query("select count(*) from $t")->fetchColumn(), PHP_EOL;' | tee "$CUT/frozen-counts.txt"
```

Ketiga sha256 harus sama. Pakai angka di `frozen-counts.txt` untuk `EXPECT`.

Blok C5 (impor):

```bash
SRC=/var/www/html/database/$(basename $CUT)/source.sqlite
$COMPOSE run --rm --no-deps --entrypoint php $PG_ENV app artisan db:import-sqlite-to-pgsql \
  --source-path=$SRC | tee "$CUT/preflight.txt"
# Baca semua WARN. Ada ERROR → berhenti, lanjut ke Rollback A.
$COMPOSE run --rm --no-deps --entrypoint php $PG_ENV app artisan db:import-sqlite-to-pgsql \
  --source-path=$SRC --execute --confirm-target=securegate | tee "$CUT/import.txt"
$COMPOSE run --rm --no-deps --entrypoint php $PG_ENV app artisan db:verify-row-parity \
  --source=sqlite --source-path=$SRC --target=pgsql $EXPECT | tee "$CUT/parity.txt"
sha256sum $APP_DIR/database/database.sqlite "$CUT/source.sqlite"   # harus sama dengan sha256.txt
```

Blok C8 (titik awal data baru, untuk rollback B):

```bash
$COMPOSE exec postgres psql -U securegate -d securegate -Atc "
  select 'employees', max(id) from employees union all
  select 'access_logs', max(id) from access_logs union all
  select 'activity_logs', max(id) from activity_logs union all
  select 'door_assignments', max(id) from door_assignments union all
  select 'device_person_states', max(id) from device_person_states" | tee "$CUT/high-water-marks.txt"
```

## Rollback

Prinsip: file `database.sqlite` asli tidak pernah ditulis oleh proses ini, dan
`$CUT/rollback-original.sqlite` adalah salinannya. Rollback selalu kembali ke file
itu.

**Rollback A – sebelum C6 (aplikasi belum memakai PostgreSQL).**
Tidak ada data baru di mana pun.

1. Biarkan `.env` tetap `DB_CONNECTION=sqlite`.
2. `$COMPOSE up -d app` dan `docker start pkp_securegate_alertstream_door_b`.
3. Cek `sha256sum $APP_DIR/database/database.sqlite` sama dengan `sha256.txt` sebelum start.
4. Database PostgreSQL boleh di-drop dan dibuat ulang; impor gagal sudah otomatis
   ROLLBACK, tapi sequence PostgreSQL tidak ikut transaksi, jadi buat ulang database
   sebelum percobaan berikutnya.

**Rollback B – setelah C6 (aplikasi sudah menulis ke PostgreSQL).**

1. Hentikan app dan alertstream (seperti C2).
2. Ambil data yang lahir setelah cutover, untuk dimasukkan ulang secara manual
   (tidak ada sinkronisasi balik otomatis):

   ```bash
   # contoh untuk access_logs; ulangi untuk tabel di high-water-marks.txt
   $COMPOSE exec postgres psql -U securegate -d securegate -c \
     "\copy (select * from access_logs where id > <max_id_dari_C8>) to '/tmp/access_logs_after_cutover.csv' csv header"
   $COMPOSE exec postgres pg_dump -U securegate -Fc securegate > "$CUT/securegate-at-rollback.dump"
   ```

3. Kembalikan `.env` ke `DB_CONNECTION=sqlite` dan `DB_DATABASE=/var/www/html/database/database.sqlite`.
4. Pastikan `database.sqlite` masih sama dengan `sha256.txt`; kalau tidak, salin balik
   `$CUT/rollback-original.sqlite` (setelah menyimpan file yang berbeda itu di samping).
5. `$COMPOSE up -d app`, start alertstream, smoke test.
6. Perubahan admin setelah cutover (karyawan baru, assignment) dimasukkan ulang lewat UI
   berdasarkan CSV di langkah 2. Event akses dari perangkat bisa diambil ulang lewat
   rekonsiliasi.

Keputusan go/no-go diambil di C7, segera setelah C6. Sejak C6 webhook perangkat
sudah masuk ke PostgreSQL, jadi rollback setelah C6 selalu Rollback B; semakin cepat
keputusannya, semakin sedikit baris yang perlu dibawa kembali.

## Risiko yang tersisa

| risiko | penanganan |
|---|---|
| Data produksi melanggar CHECK/FK/JSON/panjang | Preflight menolak sebelum menulis; diputuskan di rehearsal R, bukan saat cutover |
| Event perangkat saat downtime | Pintu tetap bekerja (otorisasi lokal di terminal). Webhook saat downtime bisa hilang; tarik ulang lewat rekonsiliasi perangkat |
| Format teks tanggal | `timestamp without time zone` menyimpan jam dinding apa adanya (Asia/Jakarta); akhiran `Z`/offset diabaikan PostgreSQL. Kolom `date` membuang `00:00:00`. Jumlahnya tercetak di kolom "Reformatted values". Jangan ubah kolom ke `timestamptz` |
| `findOrFail` dengan id non-numerik → 500 di PostgreSQL (Phase 1 §2) | Rendah; ditangani terpisah, tidak memblokir cutover |
| `migrate:rollback` tidak bisa dipakai di PostgreSQL | Rollback cutover memakai file SQLite, bukan `migrate:rollback` |
