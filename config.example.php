<?php
/**
 * config.example.php
 * Template konfigurasi — salin file ini menjadi config.php
 * dan sesuaikan nilainya. JANGAN edit file ini langsung.
 *
 * cp config.example.php config.php
 */

// -- Database --------------------------------------------------
define('DB_HOST',    'localhost');
define('DB_NAME',    'db_raport');
define('DB_USER',    'root');
define('DB_PASS',    '');          // Ganti dengan password MySQL Anda
define('DB_CHARSET', 'utf8mb4');

// -- Aplikasi --------------------------------------------------
define('APP_NAME',    'Raportku');
define('APP_VERSION', '1.0.0');
define('BASE_URL',    'http://localhost/raportku23102026'); // Sesuaikan URL

// -- Upload Path -----------------------------------------------
define('UPLOAD_DIR',   __DIR__ . '/uploads/');
define('UPLOAD_URL',   BASE_URL . '/uploads/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2 MB
define('ALLOWED_IMG',   ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// -- Session ---------------------------------------------------
define('SESSION_LIFETIME', 3600); // 1 jam

// -- Error reporting (matikan di production) -------------------
ini_set('display_errors', 1);
error_reporting(E_ALL);

// -- Timezone -------------------------------------------------
date_default_timezone_set('Asia/Jakarta');

// -- Nama bulan Indonesia --------------------------------------
define('BULAN_ID', [
    1=>'Januari', 2=>'Februari', 3=>'Maret',    4=>'April',
    5=>'Mei',     6=>'Juni',     7=>'Juli',      8=>'Agustus',
    9=>'September',10=>'Oktober',11=>'November',12=>'Desember',
]);
