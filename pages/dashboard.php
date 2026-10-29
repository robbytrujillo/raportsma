<?php
/**
 * pages/dashboard.php
 * Dashboard utama &mdash; tampilan berbeda per role
 */
$user = current_user();
$role = $user['role'];

// ── Ambil data statistik sesuai role ─────────────────────────
$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');

if (in_array($role, ['admin','super_admin'])) {
    $total_siswa   = db_row('SELECT COUNT(*) as n FROM users WHERE role = "siswa" AND is_active = 1')['n'] ?? 0;
    $total_guru    = db_row('SELECT COUNT(*) as n FROM users WHERE role IN ("guru","wali_kelas") AND is_active = 1')['n'] ?? 0;
    $total_kelas   = db_row('SELECT COUNT(*) as n FROM kelas WHERE tahun_ajaran_id = :ta', [':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;
    $total_mapel   = db_row('SELECT COUNT(*) as n FROM mapel')['n'] ?? 0;
    $total_nilai   = db_row('SELECT COUNT(*) as n FROM nilai WHERE tahun_ajaran_id = :ta', [':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;

} elseif ($role === 'kepala_sekolah') {
    $total_siswa   = db_row('SELECT COUNT(*) as n FROM users WHERE role = "siswa" AND is_active = 1')['n'] ?? 0;
    $total_kelas   = db_row('SELECT COUNT(*) as n FROM kelas WHERE tahun_ajaran_id = :ta', [':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;
    $total_nilai   = db_row('SELECT COUNT(*) as n FROM nilai WHERE tahun_ajaran_id = :ta', [':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;

} elseif (in_array($role, ['guru','wali_kelas'])) {
    // Kelas & mapel yang diajar guru ini
    $total_kelas = db_row('SELECT COUNT(DISTINCT kelas_id) as n FROM guru_mapel_kelas WHERE guru_user_id = :uid AND tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;
    $total_mapel = db_row('SELECT COUNT(DISTINCT mapel_id) as n FROM guru_mapel_kelas WHERE guru_user_id = :uid AND tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;
    $total_nilai = db_row('SELECT COUNT(*) as n FROM nilai WHERE guru_user_id = :uid AND tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;

    // Jumlah siswa di kelas yang diajar
    $total_siswa = db_row('SELECT COUNT(DISTINCT s.user_id) as n FROM siswa s
        INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id = s.kelas_id
        WHERE gmk.guru_user_id = :uid AND gmk.tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;

} elseif ($role === 'siswa') {
    // Nilai siswa ini
    $siswa_data = db_row('SELECT s.*, k.nama_kelas FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id WHERE s.user_id = :uid', [':uid' => $user['id']]);
    $total_nilai = db_row('SELECT COUNT(*) as n FROM nilai WHERE siswa_user_id = :uid AND tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;

    // Rata-rata nilai akhir siswa
    $avg_nilai = db_row('SELECT ROUND(AVG(nilai_akhir),2) as avg FROM nilai WHERE siswa_user_id = :uid AND tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['avg'] ?? 0;

    // Peringkat di kelas
    if ($siswa_data) {
        $rank_data = db_query(
            'SELECT sv.user_id, ROUND(AVG(n.nilai_akhir),2) as avg_nilai
             FROM siswa sv
             INNER JOIN nilai n ON n.siswa_user_id = sv.user_id
             WHERE sv.kelas_id = :kelas AND n.tahun_ajaran_id = :ta
             GROUP BY sv.user_id
             ORDER BY avg_nilai DESC',
            [':kelas' => $siswa_data['kelas_id'], ':ta' => $ta_aktif['id'] ?? 0]
        );
        $peringkat = 1;
        foreach ($rank_data as $i => $r) {
            if ($r['user_id'] == $user['id']) {
                $peringkat = $i + 1;
                break;
            }
        }
        $total_siswa_kelas = count($rank_data);
    }
}

// Aktivitas nilai terbaru (admin/guru/walas)
$recent_nilai = [];
if (in_array($role, ['admin', 'super_admin', 'guru', 'wali_kelas'])) {
    $uid_filter = (in_array($role, ['admin','super_admin'])) ? '' : 'AND n.guru_user_id = ' . (int)$user['id'];
    $recent_nilai = db_query(
        "SELECT n.*, u.nama_lengkap as nama_siswa, m.nama_mapel, k.nama_kelas
         FROM nilai n
         LEFT JOIN users u ON u.id = n.siswa_user_id
         LEFT JOIN mapel m ON m.id = n.mapel_id
         LEFT JOIN kelas k ON k.id = n.kelas_id
         WHERE n.tahun_ajaran_id = :ta $uid_filter
         ORDER BY n.updated_at DESC LIMIT 5",
        [':ta' => $ta_aktif['id'] ?? 0]
    );
}
?>
<div class="page-content">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">Selamat Datang, <?= e(explode(' ', $user['nama_lengkap'])[0]) ?>!</h1>
      <p class="page-subtitle">
        <?php if ($ta_aktif): ?>
          Tahun Ajaran <?= e($ta_aktif['nama']) ?> &mdash; Semester <?= $ta_aktif['semester'] == 1 ? 'Ganjil' : 'Genap' ?>
        <?php else: ?>
          Belum ada tahun ajaran aktif
        <?php endif; ?>
      </p>
    </div>
    <span class="badge badge-orange text-sm"><?= str_replace('_',' ', ucfirst($role)) ?></span>
  </div>

  <!-- ── STAT CARDS ──────────────────────────────────────── -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">

    <?php if (in_array($role, ['admin', 'kepala_sekolah', 'guru', 'wali_kelas'])): ?>
    <!-- Siswa -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#fff7ed">
        <svg width="24" height="24" fill="none" stroke="#f97316" stroke-width="2" viewBox="0 0 24 24">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 00-3-3.87"/>
          <path d="M16 3.13a4 4 0 010 7.75"/>
        </svg>
      </div>
      <div>
        <div class="stat-value text-orange-600"><?= $total_siswa ?></div>
        <div class="stat-label">Total Siswa</div>
      </div>
    </div>

    <?php if (in_array($role, ['admin','super_admin'])): ?>
    <!-- Guru -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#eff6ff">
        <svg width="24" height="24" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="12" cy="8" r="4"/>
          <path d="M20 21a8 8 0 10-16 0"/>
        </svg>
      </div>
      <div>
        <div class="stat-value text-blue-600"><?= $total_guru ?></div>
        <div class="stat-label">Total Guru</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Kelas -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#f0fdf4">
        <svg width="24" height="24" fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24">
          <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </div>
      <div>
        <div class="stat-value text-green-600"><?= $total_kelas ?></div>
        <div class="stat-label"><?= in_array($role, ['admin','super_admin']) ? 'Total Kelas' : 'Kelas Diampu' ?></div>
      </div>
    </div>

    <!-- Mata Pelajaran / Nilai -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#fdf4ff">
        <svg width="24" height="24" fill="none" stroke="#a855f7" stroke-width="2" viewBox="0 0 24 24">
          <path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/>
          <path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>
        </svg>
      </div>
      <div>
        <div class="stat-value text-purple-600"><?= in_array($role, ['admin','super_admin']) ? $total_mapel : $total_nilai ?></div>
        <div class="stat-label"><?= in_array($role, ['admin','super_admin']) ? 'Mata Pelajaran' : 'Data Nilai' ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($role === 'siswa'): ?>
    <!-- Nilai siswa -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#fff7ed">
        <svg width="24" height="24" fill="none" stroke="#f97316" stroke-width="2" viewBox="0 0 24 24">
          <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
        </svg>
      </div>
      <div>
        <div class="stat-value text-orange-600"><?= $total_nilai ?></div>
        <div class="stat-label">Nilai Tercatat</div>
      </div>
    </div>

    <!-- Rata-rata -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#eff6ff">
        <svg width="24" height="24" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
        </svg>
      </div>
      <div>
        <div class="stat-value text-blue-600"><?= $avg_nilai ?? 0 ?></div>
        <div class="stat-label">Rata-rata Nilai</div>
      </div>
    </div>

    <!-- Peringkat -->
    <?php if (!empty($peringkat)): ?>
    <div class="stat-card">
      <div class="stat-icon" style="background:#fef9c3">
        <svg width="24" height="24" fill="none" stroke="#eab308" stroke-width="2" viewBox="0 0 24 24">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
      </div>
      <div>
        <div class="stat-value text-yellow-600"><?= $peringkat ?><span style="font-size:.9rem">/<?= $total_siswa_kelas ?? '-' ?></span></div>
        <div class="stat-label">Peringkat Kelas</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Info Kelas -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#f0fdf4">
        <svg width="24" height="24" fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24">
          <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
        </svg>
      </div>
      <div>
        <div class="stat-value text-green-600" style="font-size:1rem"><?= e($siswa_data['nama_kelas'] ?? '-') ?></div>
        <div class="stat-label">Kelas Saya</div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── RECENT ACTIVITY ─────────────────────────────────── -->
  <?php if (!empty($recent_nilai)): ?>
  <div class="card">
    <div class="card-header">
      <span>Aktivitas Nilai Terbaru</span>
      <a href="index.php?page=nilai" class="btn btn-sm btn-secondary">Lihat Semua</a>
    </div>
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>Siswa</th>
            <th>Kelas</th>
            <th>Mata Pelajaran</th>
            <th>Jenis</th>
            <th>Nilai Akhir</th>
            <th>Predikat</th>
            <th>Diperbarui</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent_nilai as $n): ?>
          <tr>
            <td class="font-semibold"><?= e($n['nama_siswa']) ?></td>
            <td><?= e($n['nama_kelas']) ?></td>
            <td><?= e($n['nama_mapel']) ?></td>
            <td>
              <span class="badge <?= $n['jenis_raport'] === 'bulanan' ? 'badge-blue' : 'badge-orange' ?>">
                <?= ucfirst($n['jenis_raport']) ?>
              </span>
            </td>
            <td class="font-bold"><?= number_format($n['nilai_akhir'], 2) ?></td>
            <td>
              <?php $pred = $n['predikat'] ?? get_predikat((float)$n['nilai_akhir']); ?>
              <span class="badge <?= $pred==='A'?'badge-green':($pred==='B'?'badge-blue':($pred==='C'?'badge-orange':'badge-red')) ?>">
                <?= $pred ?>
              </span>
            </td>
            <td class="text-gray-400 text-xs"><?= date('d M Y H:i', strtotime($n['updated_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── QUICK LINKS untuk Admin ────────────────────────── -->
  <?php if (in_array($role, ['admin','super_admin'])): ?>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
    <?php
    $quick = [
      ['page'=>'siswa.create','label'=>'Tambah Siswa','icon'=>'<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>','color'=>'bg-orange-50 text-orange-600'],
      ['page'=>'guru.create','label'=>'Tambah Guru','icon'=>'<circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 10-16 0"/>','color'=>'bg-blue-50 text-blue-600'],
      ['page'=>'kelas.create','label'=>'Tambah Kelas','icon'=>'<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>','color'=>'bg-green-50 text-green-600'],
      ['page'=>'mapel.create','label'=>'Tambah Mapel','icon'=>'<path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>','color'=>'bg-purple-50 text-purple-600'],
    ];
    ?>
    <?php foreach ($quick as $q): ?>
    <a href="index.php?page=<?= $q['page'] ?>" class="card flex flex-col items-center justify-center p-5 hover:shadow-md transition-all text-center" style="text-decoration:none">
      <div class="<?= $q['color'] ?> rounded-xl p-3 mb-3">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <?= $q['icon'] ?>
        </svg>
      </div>
      <span class="text-sm font-semibold text-gray-700"><?= $q['label'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
