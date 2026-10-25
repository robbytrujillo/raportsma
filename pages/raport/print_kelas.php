<?php
/**
 * pages/raport/print_kelas.php
 * Cetak raport seluruh siswa dalam satu kelas — setiap siswa = 1 halaman.
 * Akses: admin, kepala_sekolah, wali_kelas (kelasnya sendiri), guru (kelasnya sendiri).
 * Siswa tidak boleh akses halaman ini.
 */
require_once __DIR__ . '/../../auth.php';
require_login();

$user_ses = current_user();
$role     = $user_ses['role'];

// Siswa tidak boleh cetak kelas
if ($role === 'siswa') {
    http_response_code(403); echo 'Akses ditolak.'; exit;
}

$ta_id    = (int)($_GET['ta']    ?? 0);
$kelas_id = (int)($_GET['kelas'] ?? 0);
$jenis    = $_GET['jenis'] ?? 'semester';
$bulan    = (int)($_GET['bulan']  ?? 0);

if (!$ta_id || !$kelas_id) {
    http_response_code(400); echo 'Parameter ta dan kelas wajib diisi.'; exit;
}

require_once __DIR__ . '/../../db.php';

// Verifikasi akses: guru/wali_kelas hanya bisa cetak kelas yang diajar/dipimpin
if (!in_array($role, ['admin','kepala_sekolah'])) {
    $guru_detail = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user_ses['id']]);
    $is_walas    = ($guru_detail['status'] ?? '') === 'wali_kelas';

    if ($is_walas) {
        $kelas_check = db_row('SELECT id FROM kelas WHERE id = :kid AND wali_kelas_id = :uid',
            [':kid' => $kelas_id, ':uid' => $user_ses['id']]);
    } else {
        $kelas_check = db_row(
            'SELECT DISTINCT k.id FROM kelas k
             INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id
             WHERE k.id = :kid AND gmk.guru_user_id = :uid AND gmk.tahun_ajaran_id = :ta',
            [':kid' => $kelas_id, ':uid' => $user_ses['id'], ':ta' => $ta_id]
        );
    }
    if (!$kelas_check) {
        http_response_code(403); echo 'Anda tidak memiliki akses ke kelas ini.'; exit;
    }
}

// Ambil data kelas & TA
$kelas = db_row('SELECT * FROM kelas WHERE id = :id', [':id' => $kelas_id]);
$ta    = db_row('SELECT * FROM tahun_ajaran WHERE id = :id', [':id' => $ta_id]);

if (!$kelas || !$ta) {
    http_response_code(404); echo 'Kelas atau Tahun Ajaran tidak ditemukan.'; exit;
}

// Daftar siswa di kelas, diurutkan peringkat (rata-rata DESC)
$bulan_cond_rank  = '';
$rank_params      = [':kid' => $kelas_id, ':ta' => $ta_id, ':jenis' => $jenis];
if (in_array($jenis, ['bulanan','sts']) && $bulan) {
    $bulan_cond_rank = 'AND n.bulan = :bulan';
    $rank_params[':bulan'] = $bulan;
}

$siswa_list = db_query(
    "SELECT u.id as user_id, u.nama_lengkap, s.nis,
            ROUND(AVG(n.nilai_akhir),2) as avg_nilai
     FROM users u
     INNER JOIN siswa s ON s.user_id = u.id
     LEFT JOIN nilai n ON n.siswa_user_id = u.id
                       AND n.kelas_id = :kid
                       AND n.tahun_ajaran_id = :ta
                       AND n.jenis_raport = :jenis
                       $bulan_cond_rank
     WHERE s.kelas_id = :kid
     GROUP BY u.id, u.nama_lengkap, s.nis
     ORDER BY avg_nilai DESC",
    $rank_params
);

if (empty($siswa_list)) {
    http_response_code(404);
    echo 'Tidak ada siswa atau nilai di kelas ini untuk filter yang dipilih.'; exit;
}

$total_siswa = count($siswa_list);

// Label jenis & bulan untuk judul toolbar
$jenis_label = ['bulanan'=>'Bulanan','sts'=>'STS','semester'=>'Semester'][$jenis] ?? $jenis;
$bulan_nama  = (in_array($jenis,['bulanan','sts']) && $bulan) ? (BULAN_ID[$bulan] ?? '') : '';
$semester_lbl = ($ta['semester'] ?? 1) == 1 ? 'Ganjil' : 'Genap';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cetak Raport Kelas <?= htmlspecialchars($kelas['nama_kelas']) ?> — <?= htmlspecialchars($ta['nama']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/style.css">
  <style>
    body { background: #f3f4f6; margin: 0; padding-top: 60px; font-family: 'Poppins', sans-serif; }

    /* ── Toolbar ── */
    .print-toolbar {
      position: fixed; top: 0; left: 0; right: 0; z-index: 999;
      background: #f97316; color: #fff; padding: 8px 24px;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      gap: 12px;
    }
    .print-toolbar .tb-title { font-weight: 700; font-size: .95rem; white-space: nowrap; }
    .print-toolbar .tb-meta  { font-size: .78rem; opacity: .85; }
    .print-toolbar .tb-btns  { display: flex; gap: 8px; flex-shrink: 0; }
    .print-toolbar a, .print-toolbar button {
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.5);
      color: #fff; padding: 6px 14px; border-radius: 8px;
      cursor: pointer; font-size: .82rem; font-weight: 600;
      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
      white-space: nowrap;
    }
    .print-toolbar a:hover, .print-toolbar button:hover { background: rgba(255,255,255,0.35); }
    .print-toolbar button.btn-print {
      background: rgba(255,255,255,0.9); color: #f97316;
    }
    .print-toolbar button.btn-print:hover { background: #fff; }

    /* ── Progress indicator ── */
    .print-progress {
      position: fixed; bottom: 16px; right: 16px; z-index: 998;
      background: #1f2937; color: #fff;
      padding: 10px 18px; border-radius: 12px; font-size: .82rem;
      box-shadow: 0 4px 16px rgba(0,0,0,0.3);
      display: flex; align-items: center; gap: 8px;
    }

    /* ── Wrapper tiap raport siswa ── */
    .student-raport-wrap {
      /* Tiap siswa mulai di halaman baru saat print */
      page-break-after: always;
      break-after: page;
    }
    .student-raport-wrap:last-child {
      page-break-after: avoid;
      break-after: avoid;
    }

    /* Override margin raport untuk cetak kelas */
    .raport-page {
      margin: 24px auto !important;
      box-shadow: 0 4px 24px rgba(0,0,0,0.12) !important;
    }

    /* ── @media print ── */
    @media print {
      body { background: #fff !important; padding-top: 0 !important; }
      .print-toolbar, .print-progress { display: none !important; }
      .raport-page { margin: 0 !important; box-shadow: none !important; }
      .student-raport-wrap {
        page-break-after: always;
        break-after: page;
      }
      .student-raport-wrap:last-child {
        page-break-after: avoid;
        break-after: avoid;
      }
    }
  </style>
</head>
<body>

<!-- ── Toolbar ──────────────────────────────────────────── -->
<div class="print-toolbar">
  <div>
    <div class="tb-title">
      Cetak Raport Kelas <?= htmlspecialchars($kelas['nama_kelas']) ?>
    </div>
    <div class="tb-meta">
      <?= htmlspecialchars($ta['nama']) ?> · Sem. <?= $semester_lbl ?>
      · <?= $jenis_label ?><?= $bulan_nama ? " — $bulan_nama" : '' ?>
      · <strong><?= $total_siswa ?> siswa</strong>
    </div>
  </div>
  <div class="tb-btns">
    <a href="javascript:history.back()">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <polyline points="15 18 9 12 15 6"/>
      </svg>
      Kembali
    </a>
    <button class="btn-print" onclick="cetakSemua()">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polyline points="6 9 6 2 18 2 18 9"/>
        <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
        <rect x="6" y="14" width="12" height="8"/>
      </svg>
      Cetak Semua (<?= $total_siswa ?> Siswa)
    </button>
  </div>
</div>

<!-- ── Progress indicator (saat halaman dimuat) ── -->
<div class="print-progress" id="loadProgress">
  <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4"/>
  </svg>
  Memuat raport <?= $total_siswa ?> siswa...
</div>

<!-- ── Raport setiap siswa ──────────────────────────────── -->
<?php
// Kepala sekolah (shared, sama untuk semua)
$kepsek_shared = db_row('SELECT * FROM users WHERE role = "kepala_sekolah" LIMIT 1');
// Wali kelas (shared)
$wali_kelas_shared = $kelas['wali_kelas_id']
    ? db_row('SELECT * FROM users WHERE id = :id', [':id' => $kelas['wali_kelas_id']])
    : null;

// Peringkat: sudah terurut dari query, index = peringkat-1
foreach ($siswa_list as $rank_idx => $sw):
    $siswa_uid         = (int)$sw['user_id'];
    $peringkat         = $rank_idx + 1;
    $total_siswa_kelas = $total_siswa;

    // Load data siswa lengkap
    $siswa = db_row(
        'SELECT u.*, s.nis, s.nisn, s.kelas_id, s.tanggal_lahir, s.tempat_lahir, s.alamat,
                s.nama_ayah, s.nama_ibu, k.nama_kelas, k.wali_kelas_id,
                ta.nama as ta_nama, ta.semester
         FROM users u
         INNER JOIN siswa s ON s.user_id = u.id
         INNER JOIN kelas k ON k.id = s.kelas_id
         INNER JOIN tahun_ajaran ta ON ta.id = :ta_id
         WHERE u.id = :uid',
        [':uid' => $siswa_uid, ':ta_id' => $ta_id]
    );
    if (!$siswa) continue;

    // Nilai
    $nilai_params = [':uid' => $siswa_uid, ':ta' => $ta_id, ':jenis' => $jenis];
    $bulan_cond   = '';
    if (in_array($jenis, ['bulanan','sts']) && $bulan) {
        $bulan_cond = 'AND n.bulan = :bulan';
        $nilai_params[':bulan'] = $bulan;
    }
    $nilai_list = db_query(
        "SELECT n.*, m.nama_mapel, m.kelompok, m.kode_mapel, m.parent_id, m.sub_label, m.urutan
         FROM nilai n
         LEFT JOIN mapel m ON m.id = n.mapel_id
         WHERE n.siswa_user_id = :uid
           AND n.tahun_ajaran_id = :ta
           AND n.jenis_raport = :jenis
           $bulan_cond
         ORDER BY m.kelompok, m.urutan, m.nama_mapel",
        $nilai_params
    );

    // Absensi
    $absensi = db_row(
        'SELECT * FROM absensi WHERE siswa_user_id = :uid AND kelas_id = :kid AND tahun_ajaran_id = :ta',
        [':uid' => $siswa_uid, ':kid' => $siswa['kelas_id'], ':ta' => $ta_id]
    ) ?? ['sakit' => 0, 'izin' => 0, 'alpa' => 0];

    // Wali kelas & kepsek dari shared (sudah diambil di atas)
    $wali_kelas = $wali_kelas_shared;
    $kepsek     = $kepsek_shared;

    // Rata-rata nilai
    $avg_nilai = count($nilai_list) > 0
        ? round(array_sum(array_column($nilai_list, 'nilai_akhir')) / count($nilai_list), 2)
        : 0;
?>
<div class="student-raport-wrap" data-siswa="<?= $rank_idx + 1 ?>">
  <?php include __DIR__ . '/raport_template.php'; ?>
</div>
<?php endforeach; ?>

<script>
/**
 * Tunggu semua gambar (logo, tanda tangan) selesai dimuat sebelum hapus indikator.
 */
document.addEventListener('DOMContentLoaded', function () {
  const imgs = document.querySelectorAll('img');
  const progress = document.getElementById('loadProgress');
  let loaded = 0;
  const total = imgs.length;

  function checkDone() {
    loaded++;
    if (loaded >= total && progress) {
      progress.innerHTML = '✓ Semua raport siap dicetak';
      setTimeout(() => { if (progress) progress.style.display = 'none'; }, 2000);
    }
  }

  if (total === 0) {
    if (progress) progress.style.display = 'none';
  } else {
    imgs.forEach(img => {
      if (img.complete) { checkDone(); }
      else { img.addEventListener('load', checkDone); img.addEventListener('error', checkDone); }
    });
  }
});

/**
 * Cetak semua siswa — jeda kecil agar browser render sempurna.
 */
function cetakSemua() {
  const progress = document.getElementById('loadProgress');
  if (progress) {
    progress.style.display = 'flex';
    progress.innerHTML = '🖨 Menyiapkan cetak...';
  }
  setTimeout(() => {
    window.print();
    if (progress) progress.style.display = 'none';
  }, 400);
}
</script>

</body>
</html>
