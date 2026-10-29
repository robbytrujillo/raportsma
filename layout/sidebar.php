<?php
/**
 * layout/sidebar.php
 * Sidebar navigasi &mdash; ditampilkan sesuai role
 * Mendukung collapse/expand (desktop: icon-only mode, mobile: slide overlay)
 */
$user = current_user();
$role = $user['role'];
$initials = '';
foreach (explode(' ', $user['nama_lengkap']) as $word) {
    $initials .= strtoupper(substr($word, 0, 1));
    if (strlen($initials) >= 2) break;
}
?>
<?php $sekolah_s = sekolah_info(); ?>
<!-- ── SIDEBAR ──────────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">

  <!-- Header: Logo + Tombol Toggle -->
  <div class="sidebar-header">
    <div class="sidebar-header-top">
      <!-- Logo / ikon -->
      <?php if (!empty($sekolah_s['logo'])): ?>
        <img src="<?= UPLOAD_URL . e($sekolah_s['logo']) ?>"
             alt="Logo"
             style="width:30px;height:30px;object-fit:cover;border-radius:50%;flex-shrink:0">
      <?php else: ?>
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" style="flex-shrink:0">
          <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/>
          <line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
      <?php endif; ?>

      <!-- Nama aplikasi &mdash; disembunyikan saat collapsed -->
      <span class="sidebar-logo-text" style="font-size:1.25rem;font-weight:800;
            letter-spacing:-.5px;white-space:nowrap;overflow:hidden;
            transition:opacity .25s,width .25s">
        <?= APP_NAME ?>
      </span>

      <!-- Tombol toggle collapse (desktop) -->
      <button class="sidebar-toggle-btn" id="sidebarToggleBtn"
              title="Sembunyikan / tampilkan sidebar" aria-label="Toggle sidebar">
        <span class="sidebar-toggle-icon">
          <!-- Ikon panah ke kiri &mdash; diputar 180° saat collapsed -->
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
               viewBox="0 0 24 24">
            <polyline points="15 18 9 12 15 6"/>
          </svg>
        </span>
      </button>
    </div>

    <!-- Info user -->
    <div class="sidebar-user-wrap">
      <div class="avatar" style="width:30px;height:30px;font-size:.7rem;flex-shrink:0">
        <?php if ($user['foto']): ?>
          <img src="<?= UPLOAD_URL . e($user['foto']) ?>" alt="foto">
        <?php else: ?>
          <?= e($initials) ?>
        <?php endif; ?>
      </div>
      <div class="sidebar-user-info" style="min-width:0;overflow:hidden;white-space:nowrap">
        <div class="text-white text-xs font-semibold leading-tight truncate"
             style="max-width:150px"><?= e($user['nama_lengkap']) ?></div>
        <?php
          $guru_det = (!in_array($role, ['super_admin','admin','siswa','kepala_sekolah']))
              ? db_row('SELECT status FROM guru WHERE user_id = :id', [':id'=>$user['id']])
              : null;
          $status_label = $guru_det
              ? str_replace('_',' ', ucfirst($guru_det['status']))
              : str_replace('_',' ', ucfirst($role));
        ?>
        <div class="text-orange-200 text-xs capitalize"><?= e($status_label) ?></div>
      </div>
    </div>
  </div><!-- /.sidebar-header -->

  <nav class="sidebar-nav">

    <!-- Dashboard -->
    <a href="index.php?page=dashboard" data-page="dashboard">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
        <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
      </svg>
      <span class="sidebar-nav-label">Dashboard</span>
      <span class="sidebar-tooltip">Dashboard</span>
    </a>

    <?php if (in_array($role, ['super_admin','admin','kepala_sekolah','wali_kelas','guru'])): ?>
    <!-- ── Akademik ─────────────────── -->
    <div class="sidebar-section-title">Akademik</div>

    <?php if (in_array($role, ['super_admin','admin','wali_kelas','guru'])): ?>
    <a href="index.php?page=nilai.bulk" data-page="nilai.bulk,nilai">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
      </svg>
      <span class="sidebar-nav-label">Input Nilai</span>
      <span class="sidebar-tooltip">Input Nilai</span>
    </a>
    <a href="index.php?page=absensi" data-page="absensi">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
      <span class="sidebar-nav-label">Absensi</span>
      <span class="sidebar-tooltip">Absensi</span>
    </a>
    <?php endif; ?>

    <?php if ($role === 'kepala_sekolah'): ?>
    <a href="index.php?page=nilai" data-page="nilai">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
      </svg>
      <span class="sidebar-nav-label">Lihat Nilai</span>
      <span class="sidebar-tooltip">Lihat Nilai</span>
    </a>
    <?php endif; ?>

    <a href="index.php?page=raport" data-page="raport">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
      <span class="sidebar-nav-label">Raport</span>
      <span class="sidebar-tooltip">Raport</span>
    </a>

    <?php if ($role === 'super_admin'): ?>
    <a href="index.php?page=raport.manage" data-page="raport.manage">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
      </svg>
      <span class="sidebar-nav-label">Manajemen Raport</span>
      <span class="sidebar-tooltip">Manajemen Raport</span>
    </a>
    <?php endif; ?>

    <?php endif; ?>

    <?php if ($role === 'siswa'): ?>
    <div class="sidebar-section-title">Saya</div>
    <a href="index.php?page=raport" data-page="raport">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
      <span class="sidebar-nav-label">Raport Saya</span>
      <span class="sidebar-tooltip">Raport Saya</span>
    </a>
    <?php endif; ?>

    <?php if (in_array($role, ['super_admin','admin'])): ?>
    <!-- ── Manajemen Data ──────────── -->
    <div class="sidebar-section-title">Manajemen Data</div>
    <a href="index.php?page=siswa" data-page="siswa">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
      </svg>
      <span class="sidebar-nav-label">Data Siswa</span>
      <span class="sidebar-tooltip">Data Siswa</span>
    </a>
    <a href="index.php?page=guru" data-page="guru">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 10-16 0"/>
      </svg>
      <span class="sidebar-nav-label">Data Guru</span>
      <span class="sidebar-tooltip">Data Guru</span>
    </a>
    <a href="index.php?page=kelas" data-page="kelas">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
        <polyline points="9 22 9 12 15 12 15 22"/>
      </svg>
      <span class="sidebar-nav-label">Data Kelas</span>
      <span class="sidebar-tooltip">Data Kelas</span>
    </a>
    <a href="index.php?page=mapel" data-page="mapel">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/>
        <path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>
      </svg>
      <span class="sidebar-nav-label">Mata Pelajaran</span>
      <span class="sidebar-tooltip">Mata Pelajaran</span>
    </a>
    <a href="index.php?page=penugasan" data-page="penugasan">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>
      </svg>
      <span class="sidebar-nav-label">Penugasan Guru</span>
      <span class="sidebar-tooltip">Penugasan Guru</span>
    </a>

    <!-- ── Pengaturan ────────────────── -->
    <div class="sidebar-section-title">Pengaturan</div>
    <?php if (in_array($role, ['super_admin','admin'])): ?>
    <a href="index.php?page=users" data-page="users">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
      <span class="sidebar-nav-label">Pengguna</span>
      <span class="sidebar-tooltip">Pengguna</span>
    </a>
    <?php endif; ?>
    <a href="index.php?page=tahun_ajaran" data-page="tahun_ajaran">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10"/>
        <polyline points="12 6 12 12 16 14"/>
      </svg>
      <span class="sidebar-nav-label">Tahun Ajaran</span>
      <span class="sidebar-tooltip">Tahun Ajaran</span>
    </a>
    <a href="index.php?page=sekolah" data-page="sekolah">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.07 4.93A10 10 0 1 0 4.93 19.07"/>
        <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42
                 M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
      </svg>
      <span class="sidebar-nav-label">Info Sekolah</span>
      <span class="sidebar-tooltip">Info Sekolah</span>
    </a>
    <?php endif; ?>

    <!-- ── Akun ──────────────────────── -->
    <div class="sidebar-section-title">Akun</div>
    <a href="index.php?page=profil" data-page="profil">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
      <span class="sidebar-nav-label">Profil</span>
      <span class="sidebar-tooltip">Profil</span>
    </a>

    <!-- Logout -->
    <form method="post" action="index.php" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="logout">
      <button type="submit"
        style="width:100%;display:flex;align-items:center;gap:12px;padding:10px 20px;
               color:rgba(255,255,255,0.85);background:transparent;border:none;
               cursor:pointer;font-size:.875rem;font-weight:500;white-space:nowrap;
               transition:background .2s;position:relative"
        onmouseover="this.style.background='rgba(255,255,255,0.15)'"
        onmouseout="this.style.background='transparent'"
        id="logoutBtn">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
             viewBox="0 0 24 24" style="flex-shrink:0;opacity:.85">
          <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        <span class="sidebar-nav-logout-label">Keluar</span>
        <!-- Tooltip keluar saat collapsed -->
        <span style="display:none" class="sidebar-tooltip">Keluar</span>
      </button>
    </form>

  </nav>
</aside>
<!-- ── END SIDEBAR ──────────────────────────────────────────── -->

<!-- Topbar -->
<header class="topbar">
  <div class="flex items-center gap-3">
    <!-- Hamburger: mobile = buka overlay, desktop = tidak dipakai (toggle ada di sidebar) -->
    <button class="hamburger" id="hamburgerBtn" aria-label="Menu">
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <line x1="3" y1="6" x2="21" y2="6"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
    </button>
    <div>
      <div class="topbar-title" id="pageTitle"><?= e($page_titles[$page] ?? 'Dashboard') ?></div>
    </div>
  </div>

  <div class="relative">
    <button id="userMenuBtn" class="topbar-user">
      <div class="avatar">
        <?php if ($user['foto']): ?>
          <img src="<?= UPLOAD_URL . e($user['foto']) ?>" alt="foto">
        <?php else: ?>
          <?= e($initials) ?>
        <?php endif; ?>
      </div>
      <div class="hidden sm:block text-right">
        <div class="text-sm font-semibold text-gray-800 leading-tight"><?= e($user['nama_lengkap']) ?></div>
        <div class="text-xs text-orange-500 capitalize font-medium"><?= str_replace('_',' ', $role) ?></div>
      </div>
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24" class="text-gray-400">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </button>

    <!-- Dropdown user menu -->
    <div id="userMenu"
         class="hidden absolute right-0 top-full mt-2 w-48 bg-white rounded-xl shadow-xl border border-gray-100 py-1 z-50">
      <a href="index.php?page=profil"
         class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-orange-50">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        Profil Saya
      </a>
      <div class="h-px bg-gray-100 mx-2 my-1"></div>
      <form method="post" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit"
                class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
          Keluar
        </button>
      </form>
    </div>
  </div>
</header>

<?php
// Flash message
$flash = get_flash();
if ($flash):
  $alertClass = $flash['type'] === 'success' ? 'alert-success'
              : ($flash['type'] === 'error'   ? 'alert-danger' : 'alert-info');
?>
<div class="mx-6 mt-4">
  <div class="alert <?= $alertClass ?>">
    <span><?= e($flash['msg']) ?></span>
  </div>
</div>
<?php endif; ?>
