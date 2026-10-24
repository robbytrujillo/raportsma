# Raportku — Sistem Manajemen Raport Sekolah

Aplikasi web untuk mengelola nilai dan mencetak raport siswa, berbasis **PHP 8 + MySQL + Tailwind CSS + Vanilla JavaScript**.

---

## 🚀 Cara Setup (Instalasi)

### 1. Persyaratan
- PHP 8.0 atau lebih baru
- MySQL 5.7+ / MariaDB
- Web server: Apache / Nginx (atau Laragon / XAMPP)
- Composer (untuk PhpSpreadsheet — sudah include, tinggal jalankan)

### 2. Letakkan file
Copy folder proyek ke direktori web server Anda.  
Contoh: `C:\laragon\www\raportku23102026\`

### 3. Install dependensi PHP
```bash
composer install
```

### 4. Buat database
Di **phpMyAdmin** atau terminal MySQL:
1. Jalankan `database.sql` — membuat database `db_raport` lengkap dengan semua tabel dan data awal.
2. *(Jika sudah punya database lama)* Jalankan `update_db_v3.sql` → lalu `update_db_v4.sql`.

### 5. Konfigurasi
Edit `config.php` sesuai kebutuhan:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'db_raport');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', 'http://localhost/raportku23102026');
```

### 6. Buat direktori upload
Pastikan direktori `uploads/` beserta sub-direktorinya dapat ditulis (writable):
```
uploads/
├── signatures/   ← tanda tangan digital
├── photos/       ← foto profil
└── logos/        ← logo sekolah
```

### 7. Buka di browser
```
http://localhost/raportku23102026
```

---

## 👤 Akun Default

| Username | Password | Role           |
|----------|----------|----------------|
| admin    | password | Admin          |
| kepsek   | password | Kepala Sekolah |

> Ganti password setelah login pertama melalui **Profil**.

---

## 🏗️ Struktur Direktori

```
raportku23102026/
├── index.php              Router utama
├── config.php             Konfigurasi DB & aplikasi
├── db.php                 PDO helper + sekolah_info()
├── auth.php               Autentikasi, session, CSRF, hitung nilai
├── excel.php              PhpSpreadsheet helper (xlsx template + parse)
├── style.css              Custom CSS (komplemen Tailwind)
├── script.js              Vanilla JS (sidebar, modal, toast, kalkulasi nilai)
├── database.sql           ⭐ Setup awal — jalankan ini di phpMyAdmin
├── update_db_v3.sql       Update schema v3 (untuk database lama)
├── update_db_v4.sql       Update schema v4 — tambah kolom bidang_studi guru
├── composer.json          Dependensi PHP (PhpSpreadsheet)
│
├── layout/
│   ├── header.php         HTML head + Tailwind CDN + favicon dinamis
│   ├── sidebar.php        Navigasi sidebar (role-aware) + logo sekolah + topbar
│   └── footer.php         Footer bar — copyright + link developer
│
├── pages/
│   ├── login.php          Halaman login (standalone HTML)
│   ├── dashboard.php      Dashboard per role (stat card, aktivitas terbaru)
│   ├── profil.php         Profil akun + upload/draw tanda tangan digital
│   ├── 403.php / 404.php
│   ├── users/             Manajemen akun pengguna (admin)
│   ├── guru/              Manajemen guru + bidang studi + import/export xlsx (admin)
│   ├── kelas/             Manajemen kelas + wali kelas + import/export xlsx (admin)
│   ├── mapel/             Mata pelajaran + sub-mapel + import/export xlsx (admin)
│   ├── penugasan/         Assign guru → mapel → kelas (admin)
│   ├── siswa/             Data siswa + import/export xlsx (admin)
│   ├── nilai/
│   │   ├── index.php      Daftar nilai (filter, export csv)
│   │   ├── form.php       Input / edit nilai per siswa
│   │   ├── bulk.php       Input nilai massal per kelas ⭐
│   │   └── import.php     Import nilai dari xlsx
│   ├── absensi/           Data kehadiran siswa (sakit/izin/alpa)
│   ├── tahun_ajaran/      Kelola tahun ajaran & set aktif
│   ├── sekolah/           Info sekolah + logo + kriteria nilai A/B/C/D
│   └── raport/
│       ├── index.php      Daftar raport per kelas/filter (siswa: milik sendiri)
│       ├── view.php       Preview raport dalam app
│       ├── print.php      Cetak / Save PDF (standalone, tanpa layout)
│       ├── raport_data.php Loader data raport (shared oleh view + print)
│       └── raport_template.php Template HTML raport A4 (TTD kepsek + walas)
│
└── uploads/
    ├── signatures/        Tanda tangan digital (PNG)
    ├── photos/            Foto profil
    └── logos/             Logo sekolah
```

---

## 👥 Role & Hak Akses

| Role               | Hak Akses |
|--------------------|-----------|
| **admin**          | Semua fitur: CRUD semua data, input nilai, cetak raport, pengaturan sekolah |
| **kepala_sekolah** | Lihat raport semua kelas, upload tanda tangan (tampil di raport) |
| **wali_kelas**     | Input nilai semua mapel di kelas yang dipimpin + mapel yang diajar, absensi |
| **guru**           | Input nilai mapel yang ditugaskan (via Penugasan Guru) di kelas tertentu |
| **siswa**          | Lihat raport milik sendiri saja, peringkat kelas |

---

## 📄 Jenis Raport & Formula Nilai

| Jenis        | Komponen Nilai Akhir                              |
|--------------|---------------------------------------------------|
| **Bulanan**  | `(Rata-rata NH1+NH2+NH3 × 70%) + (Tugas × 30%)` |
| **STS**      | Nilai STS saja                                    |
| **Semester** | Nilai SAS (Sumatif Akhir Semester) saja           |

### Predikat Default
| Predikat | Rentang              |
|----------|----------------------|
| A        | ≥ 92 (dapat diubah)  |
| B        | 83 – 91              |
| C        | 76 – 82              |
| D        | < 76                 |

> Ubah kriteria di **Pengaturan Sekolah → Kriteria Nilai**.

---

## 📥 Import / Export Data

Semua halaman manajemen (guru, siswa, kelas, mapel, nilai) mendukung:
- **Export CSV** — download data tabel sesuai filter aktif
- **Import Excel (.xlsx)** — upload file template yang didownload dari aplikasi  
  Template sudah include: judul, dropdown validasi, freeze panes, sheet Referensi

---

## 📝 Catatan Teknis

- **Input nilai massal** (halaman "Input Nilai Kelas"): guru memilih kelas dan mata pelajaran → semua siswa ditampilkan dalam satu tabel → input nilai sekaligus. Nilai akhir dihitung otomatis di browser.
- **Deskripsi nilai**: panel kriteria A/B/C/D di bulk input — isi satu template, otomatis ditetapkan ke semua siswa sesuai predikatnya, atau custom per siswa.
- **Tanda tangan digital**: guru, wali kelas, kepala sekolah dapat upload gambar TTD **atau menggambar langsung** di browser via kanvas — tampil di raport saat cetak.
- **Sub-mapel**: mapel bisa memiliki induk (contoh: Tauhid = sub dari Pendidikan Agama Islam) — diatur di halaman Mata Pelajaran.
- **Raport berisi**: identitas siswa, tabel nilai + angka + predikat + deskripsi, keterangan nilai, data ketidakhadiran, catatan wali kelas, tanda tangan kepala sekolah & wali kelas.

---

## 🎨 Teknologi

- **Backend**: PHP 8 + PDO MySQL (MySQL 5.7 / MariaDB compatible)
- **Frontend**: Tailwind CSS CDN + Vanilla JavaScript (tanpa jQuery / library lain)
- **Excel**: PhpSpreadsheet 5.x via Composer
- **Autentikasi**: Session PHP + CSRF token + bcrypt (MD5 fallback untuk data lama)
- **Upload**: PHP native `move_uploaded_file` → direktori `uploads/`
- **Tanda Tangan**: HTML5 Canvas API → export PNG base64
