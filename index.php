<?php
/**
 * index.php
 * Router utama aplikasi Raportku
 * Semua request masuk melalui file ini
 */

// Output buffering — memungkinkan header() dipanggil kapan saja
// bahkan setelah output HTML sudah dimulai
ob_start();

require_once __DIR__ . '/auth.php';

// ── Handle AJAX / API requests ────────────────────────────────
$page = $_GET['page'] ?? 'dashboard';

// Halaman yang dapat diakses tanpa login
$public_pages = ['login'];

if (!in_array($page, $public_pages, true)) {
    require_login();
}

// ── Handle POST actions (logout, dsb) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'logout') {
        do_logout();
    }
}

// ── Route mapping ke file halaman ─────────────────────────────
$routes = [
    'login'           => 'pages/login.php',
    'dashboard'       => 'pages/dashboard.php',

    // User management (admin)
    'users'           => 'pages/users/index.php',
    'users.create'    => 'pages/users/form.php',
    'users.edit'      => 'pages/users/form.php',

    // Guru
    'guru'            => 'pages/guru/index.php',
    'guru.create'     => 'pages/guru/form.php',
    'guru.edit'       => 'pages/guru/form.php',
    'guru.import'     => 'pages/guru/import.php',

    // Kelas
    'kelas'           => 'pages/kelas/index.php',
    'kelas.create'    => 'pages/kelas/form.php',
    'kelas.edit'      => 'pages/kelas/form.php',
    'kelas.import'    => 'pages/kelas/import.php',

    // Mata Pelajaran
    'mapel'           => 'pages/mapel/index.php',
    'mapel.create'    => 'pages/mapel/form.php',
    'mapel.edit'      => 'pages/mapel/form.php',
    'mapel.import'    => 'pages/mapel/import.php',

    // Penugasan Guru ke Mapel-Kelas
    'penugasan'       => 'pages/penugasan/index.php',
    'penugasan.create'=> 'pages/penugasan/form.php',

    // Nilai
    'nilai'           => 'pages/nilai/index.php',
    'nilai.input'     => 'pages/nilai/form.php',
    'nilai.edit'      => 'pages/nilai/form.php',   // alias — form.php deteksi via ?id=
    'nilai.bulk'      => 'pages/nilai/bulk.php',
    'nilai.import'    => 'pages/nilai/import.php',

    // Raport
    'raport'          => 'pages/raport/index.php',
    'raport.view'     => 'pages/raport/view.php',
    'raport.print'    => 'pages/raport/print.php',

    // Profil & Tanda Tangan
    'profil'          => 'pages/profil.php',

    // Absensi
    'absensi'         => 'pages/absensi/index.php',

    // Tahun Ajaran
    'tahun_ajaran'    => 'pages/tahun_ajaran/index.php',

    // Siswa
    'siswa'           => 'pages/siswa/index.php',
    'siswa.create'    => 'pages/siswa/form.php',
    'siswa.edit'      => 'pages/siswa/form.php',
    'siswa.import'    => 'pages/siswa/import.php',

    // Pengaturan Sekolah
    'sekolah'         => 'pages/sekolah/index.php',
];

// ── Tentukan file halaman yang akan di-include ─────────────────
$page_file = $routes[$page] ?? 'pages/404.php';

// ── Render: login tanpa layout, halaman lain dengan layout ─────
if ($page === 'login') {
    include __DIR__ . '/' . $page_file;
} elseif ($page === 'raport.print') {
    // Raport print tanpa layout (fullscreen PDF-like)
    include __DIR__ . '/' . $page_file;
} else {
    include __DIR__ . '/layout/header.php';
    // header.php membuka: <div class="app-wrapper">
    echo '<div class="main-content" id="mainContent">';
    include __DIR__ . '/layout/sidebar.php';
    include __DIR__ . '/' . $page_file;
    include __DIR__ . '/layout/footer.php'; // <footer> di dalam .main-content
    echo '</div><!-- /.main-content -->';
    echo '</div><!-- /.app-wrapper -->';
    echo '<script src="' . BASE_URL . '/script.js"></script>';
    echo '</body></html>';
}

// Kirim semua output yang sudah di-buffer ke browser
ob_end_flush();
