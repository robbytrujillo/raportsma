<?php
/**
 * layout/header.php
 * HTML head + topbar — di-include di setiap halaman yang butuh layout
 */
$user = current_user();
$page = $_GET['page'] ?? 'dashboard';

// Map page -> judul topbar
$page_titles = [
    'dashboard'    => 'Dashboard',
    'users'        => 'Manajemen Pengguna',
    'users.create' => 'Tambah Pengguna',
    'users.edit'   => 'Edit Pengguna',
    'guru'         => 'Data Guru',
    'guru.create'  => 'Tambah Guru',
    'guru.edit'    => 'Edit Guru',
    'kelas'        => 'Data Kelas',
    'kelas.create' => 'Tambah Kelas',
    'kelas.edit'   => 'Edit Kelas',
    'mapel'        => 'Mata Pelajaran',
    'mapel.create' => 'Tambah Mata Pelajaran',
    'mapel.edit'   => 'Edit Mata Pelajaran',
    'penugasan'    => 'Penugasan Guru',
    'penugasan.create'=> 'Tambah Penugasan',
    'nilai'        => 'Daftar Nilai',
    'nilai.input'  => 'Form Nilai',
    'nilai.bulk'   => 'Input Nilai Kelas',
    'raport'       => 'Raport Siswa',
    'raport.view'  => 'Detail Raport',
    'siswa'        => 'Data Siswa',
    'siswa.create' => 'Tambah Siswa',
    'siswa.edit'   => 'Edit Siswa',
    'profil'       => 'Profil & Tanda Tangan',
    'absensi'      => 'Data Absensi',
    'tahun_ajaran' => 'Tahun Ajaran',
    'sekolah'      => 'Pengaturan Sekolah',
];
$page_title = $page_titles[$page] ?? 'Raportku';

// Inisial avatar dari nama lengkap
$initials = '';
foreach (explode(' ', $user['nama_lengkap']) as $word) {
    $initials .= strtoupper(substr($word, 0, 1));
    if (strlen($initials) >= 2) break;
}
?>
<?php $sekolah_h = sekolah_info(); ?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($page_title) ?> — <?= APP_NAME ?></title>
  <!-- Favicon: gunakan logo sekolah jika tersedia -->
  <?php if (!empty($sekolah_h['logo'])): ?>
  <link rel="icon" type="image/png" href="<?= UPLOAD_URL . e($sekolah_h['logo']) ?>">
  <link rel="shortcut icon" href="<?= UPLOAD_URL . e($sekolah_h['logo']) ?>">
  <?php else: ?>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='5' fill='%23f97316'/><path d='M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z' fill='none' stroke='white' stroke-width='2'/><polyline points='14 2 14 8 20 8' fill='none' stroke='white' stroke-width='2'/></svg>">
  <?php endif; ?>
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            orange: {
              50:  '#fff7ed',
              100: '#ffedd5',
              200: '#fed7aa',
              300: '#fdba74',
              400: '#fb923c',
              500: '#f97316',
              600: '#ea580c',
              700: '#c2410c',
              800: '#9a3412',
              900: '#7c2d12',
            }
          }
        }
      }
    }
  </script>
  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/style.css">
</head>
<body>
<div class="app-wrapper">

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
