<?php
/**
 * pages/nilai/bulk.php
 * Input nilai bulk — semua siswa dalam 1 kelas × 1 mapel sekaligus
 * Tampilan seperti format Excel: header info + tabel siswa + tabel deskripsi kriteria
 */
require_role(['admin', 'guru', 'wali_kelas']);

$user         = current_user();
$role         = $user['role'];
$ta_aktif     = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$guru_detail  = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user['id']]);
$guru_status  = $guru_detail['status'] ?? 'guru';

// Kelas wali
$wali_kelas_ids = [];
if ($guru_status === 'wali_kelas') {
    $wk = db_query('SELECT id FROM kelas WHERE wali_kelas_id = :uid', [':uid' => $user['id']]);
    $wali_kelas_ids = array_column($wk, 'id');
}

$ta_id     = (int)($_GET['ta']    ?? $ta_aktif['id'] ?? 0);
$kelas_id  = (int)($_GET['kelas'] ?? 0);
$mapel_id  = (int)($_GET['mapel'] ?? 0);
$jenis     = $_GET['jenis'] ?? 'bulanan';
$bulan     = (int)($_GET['bulan'] ?? 0);

/* ── Deskripsi default per kriteria (CRUD) ─────────────────────
   Disimpan di tabel nilai_deskripsi_kriteria (dibuat runtime jika belum ada)
   Struktur: mapel_id, kelas_id, tahun_ajaran_id, jenis_raport, bulan, kriteria, deskripsi
*/
// Buat tabel jika belum ada (one-time)
db()->exec("CREATE TABLE IF NOT EXISTS nilai_deskripsi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mapel_id INT NOT NULL,
    kelas_id INT NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    jenis_raport ENUM('bulanan','sts','semester') NOT NULL DEFAULT 'bulanan',
    bulan TINYINT DEFAULT NULL,
    kriteria CHAR(1) NOT NULL,
    deskripsi TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dk (mapel_id, kelas_id, tahun_ajaran_id, jenis_raport, bulan, kriteria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Handle POST: simpan nilai bulk + deskripsi ─────────────────*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $p_ta    = (int)$_POST['tahun_ajaran_id'];
    $p_kelas = (int)$_POST['kelas_id'];
    $p_mapel = (int)$_POST['mapel_id'];
    $p_jenis = $_POST['jenis_raport'] ?? 'bulanan';
    $p_bulan = ($p_jenis !== 'semester') ? ((int)$_POST['bulan'] ?: null) : null;
    $sekolah = sekolah_info();
    $krit_a  = (float)($sekolah['kriteria_a_min'] ?? 92);
    $krit_b  = (float)($sekolah['kriteria_b_min'] ?? 83);
    $krit_c  = (float)($sekolah['kriteria_c_min'] ?? 76);

    // 1. Simpan deskripsi per kriteria
    foreach (['A','B','C','D'] as $kr) {
        $desk = trim($_POST['desk_' . $kr] ?? '');
        if ($desk !== '') {
            db_execute(
                'INSERT INTO nilai_deskripsi
                    (mapel_id, kelas_id, tahun_ajaran_id, jenis_raport, bulan, kriteria, deskripsi)
                 VALUES (:m,:k,:ta,:j,:b,:kr,:d)
                 ON DUPLICATE KEY UPDATE deskripsi = VALUES(deskripsi)',
                [':m'=>$p_mapel,':k'=>$p_kelas,':ta'=>$p_ta,
                 ':j'=>$p_jenis,':b'=>$p_bulan,':kr'=>$kr,':d'=>$desk]
            );
        }
    }

    // 2. Simpan nilai per siswa
    $siswa_ids = $_POST['siswa_ids'] ?? [];
    foreach ($siswa_ids as $sid) {
        $sid = (int)$sid;

        $h1   = (float)($_POST["h1_$sid"]    ?? 0);
        $h2   = (float)($_POST["h2_$sid"]    ?? 0);
        $h3   = (float)($_POST["h3_$sid"]    ?? 0);
        $tug  = (float)($_POST["tugas_$sid"] ?? 0);
        $sts  = (float)($_POST["sts_$sid"]   ?? 0);
        $sas  = (float)($_POST["sas_$sid"]   ?? 0);
        $desk_siswa = trim($_POST["deskripsi_$sid"] ?? '');
        $catatan    = trim($_POST["catatan_$sid"]   ?? '');

        // Hitung nilai akhir
        if ($p_jenis === 'semester') {
            $na = round($sas, 2);
        } elseif ($p_jenis === 'sts') {
            $na = round($sts, 2);
        } else {
            $rata = ($h1 + $h2 + $h3) / 3;
            $na   = round(($rata * 0.7) + ($tug * 0.3), 2);
        }

        // Predikat berdasarkan kriteria sekolah
        if ($na >= $krit_a)      $pred = 'A';
        elseif ($na >= $krit_b)  $pred = 'B';
        elseif ($na >= $krit_c)  $pred = 'C';
        else                     $pred = 'D';

        // Auto-isi deskripsi siswa dari deskripsi kriteria jika kosong
        if ($desk_siswa === '') {
            $dk = db_row(
                'SELECT deskripsi FROM nilai_deskripsi
                 WHERE mapel_id=:m AND kelas_id=:k AND tahun_ajaran_id=:ta
                   AND jenis_raport=:j AND (bulan=:b OR bulan IS NULL) AND kriteria=:kr
                 LIMIT 1',
                [':m'=>$p_mapel,':k'=>$p_kelas,':ta'=>$p_ta,
                 ':j'=>$p_jenis,':b'=>$p_bulan,':kr'=>$pred]
            );
            $desk_siswa = $dk['deskripsi'] ?? '';
        }

        db_execute(
            'INSERT INTO nilai
                (siswa_user_id, mapel_id, kelas_id, tahun_ajaran_id, guru_user_id,
                 jenis_raport, bulan,
                 nilai_harian_1, nilai_harian_2, nilai_harian_3,
                 nilai_tugas, nilai_sts, nilai_sas,
                 nilai_akhir, predikat, deskripsi, catatan)
             VALUES
                (:sid,:m,:k,:ta,:guru,:j,:b,:h1,:h2,:h3,:tug,:sts,:sas,:na,:pred,:desk,:cat)
             ON DUPLICATE KEY UPDATE
                guru_user_id    = VALUES(guru_user_id),
                nilai_harian_1  = VALUES(nilai_harian_1),
                nilai_harian_2  = VALUES(nilai_harian_2),
                nilai_harian_3  = VALUES(nilai_harian_3),
                nilai_tugas     = VALUES(nilai_tugas),
                nilai_sts       = VALUES(nilai_sts),
                nilai_sas       = VALUES(nilai_sas),
                nilai_akhir     = VALUES(nilai_akhir),
                predikat        = VALUES(predikat),
                deskripsi       = VALUES(deskripsi),
                catatan         = VALUES(catatan)',
            [':sid'=>$sid, ':m'=>$p_mapel, ':k'=>$p_kelas, ':ta'=>$p_ta,
             ':guru'=>$user['id'], ':j'=>$p_jenis, ':b'=>$p_bulan,
             ':h1'=>$h1,':h2'=>$h2,':h3'=>$h3,':tug'=>$tug,':sts'=>$sts,':sas'=>$sas,
             ':na'=>$na, ':pred'=>$pred, ':desk'=>$desk_siswa, ':cat'=>$catatan]
        );
    }

    flash('success', 'Nilai berhasil disimpan.');
    header("Location: index.php?page=nilai.bulk&ta=$p_ta&kelas=$p_kelas&mapel=$p_mapel&jenis=$p_jenis&bulan=$p_bulan");
    exit;
}

/* ── Data pendukung ─────────────────────────────────────────────*/
$ta_list   = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$sekolah   = sekolah_info();
$krit_a    = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b    = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c    = (float)($sekolah['kriteria_c_min'] ?? 76);

// Kelas sesuai hak akses
if ($role === 'admin') {
    $kelas_list = db_query('SELECT * FROM kelas WHERE tahun_ajaran_id=:ta ORDER BY nama_kelas', [':ta'=>$ta_id]);
} elseif ($guru_status === 'wali_kelas') {
    // :uid2 = nilai sama dengan :uid — PDO tidak boleh pakai named param duplikat
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         LEFT JOIN guru_mapel_kelas gmk ON gmk.kelas_id=k.id AND gmk.guru_user_id=:uid
         WHERE k.tahun_ajaran_id=:ta AND (k.wali_kelas_id=:uid2 OR gmk.id IS NOT NULL)
         ORDER BY k.nama_kelas',
        [':uid'=>$user['id'],':uid2'=>$user['id'],':ta'=>$ta_id]
    );
} else {
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id=k.id
         WHERE gmk.guru_user_id=:uid AND gmk.tahun_ajaran_id=:ta ORDER BY k.nama_kelas',
        [':uid'=>$user['id'],':ta'=>$ta_id]
    );
}

// Mapel sesuai kelas & hak akses
$mapel_list = [];
if ($kelas_id) {
    if ($role === 'admin') {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':kid'=>$kelas_id]
        );
    } elseif ($guru_status === 'wali_kelas' && in_array($kelas_id, $wali_kelas_ids)) {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':kid'=>$kelas_id]
        );
    } else {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.guru_user_id=:uid AND gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':uid'=>$user['id'],':kid'=>$kelas_id]
        );
    }
}

// Info kelas & mapel terpilih
$kelas_info = $kelas_id ? db_row('SELECT * FROM kelas WHERE id=:id', [':id'=>$kelas_id]) : null;
$mapel_info = $mapel_id ? db_row('SELECT * FROM mapel WHERE id=:id', [':id'=>$mapel_id]) : null;

// Nama guru pengampu mapel ini di kelas ini
$guru_pengampu = null;
if ($kelas_id && $mapel_id) {
    $guru_pengampu = db_row(
        'SELECT u.nama_lengkap FROM users u
         INNER JOIN guru_mapel_kelas gmk ON gmk.guru_user_id=u.id
         WHERE gmk.kelas_id=:kid AND gmk.mapel_id=:mid AND gmk.tahun_ajaran_id=:ta LIMIT 1',
        [':kid'=>$kelas_id,':mid'=>$mapel_id,':ta'=>$ta_id]
    );
}

// Daftar siswa di kelas
$siswa_list = [];
if ($kelas_id) {
    $siswa_list = db_query(
        'SELECT u.id, u.nama_lengkap, s.nis
         FROM users u INNER JOIN siswa s ON s.user_id=u.id
         WHERE s.kelas_id=:kid ORDER BY u.nama_lengkap',
        [':kid'=>$kelas_id]
    );
}

// Nilai yang sudah ada
$existing_nilai = [];
if ($kelas_id && $mapel_id) {
    $rows = db_query(
        'SELECT * FROM nilai
         WHERE kelas_id=:k AND mapel_id=:m AND tahun_ajaran_id=:ta
           AND jenis_raport=:j AND (bulan=:b OR (:b2 IS NULL AND bulan IS NULL))',
        [':k'=>$kelas_id,':m'=>$mapel_id,':ta'=>$ta_id,
         ':j'=>$jenis,':b'=>$bulan?:null,':b2'=>$bulan?:null]
    );
    foreach ($rows as $r) $existing_nilai[$r['siswa_user_id']] = $r;
}

// Deskripsi kriteria yang sudah tersimpan
$deskripsi_kriteria = [];
if ($kelas_id && $mapel_id) {
    $drows = db_query(
        'SELECT * FROM nilai_deskripsi
         WHERE mapel_id=:m AND kelas_id=:k AND tahun_ajaran_id=:ta
           AND jenis_raport=:j AND (bulan=:b OR (:b2 IS NULL AND bulan IS NULL))',
        [':m'=>$mapel_id,':k'=>$kelas_id,':ta'=>$ta_id,
         ':j'=>$jenis,':b'=>$bulan?:null,':b2'=>$bulan?:null]
    );
    foreach ($drows as $d) $deskripsi_kriteria[$d['kriteria']] = $d['deskripsi'];
}

$bulan_opts = BULAN_ID;
$jenis_opts = [
    'bulanan'  => 'Bulanan (NH + Tugas)',
    'sts'      => 'STS — Sumatif Tengah Semester',
    'semester' => 'Semester / SAS',
];
$ready = $kelas_id && $mapel_id && $ta_id;
?>

<div class="page-content">

  <!-- ── PAGE HEADER ─────────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <h1 class="page-title">Input Nilai Kelas</h1>
      <p class="page-subtitle">Input nilai seluruh siswa dalam satu kelas sekaligus</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="index.php?page=nilai.import" class="btn btn-secondary">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        Import CSV
      </a>
      <a href="index.php?page=nilai" class="btn btn-secondary">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        Kembali ke Daftar
      </a>
    </div>
  </div>

  <!-- ── STEP 1: PILIH FILTER ────────────────────────────────── -->
  <div class="card mb-5">
    <div class="card-header">
      <span class="flex items-center gap-2">
        <span style="background:#f97316;color:#fff;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700">1</span>
        Pilih Mata Pelajaran, Kelas &amp; Periode
      </span>
    </div>
    <div class="card-body">
      <form method="GET" action="index.php" id="filterForm">
        <input type="hidden" name="page" value="nilai.bulk">
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
          <!-- Tahun Ajaran -->
          <div class="form-group mb-0">
            <label class="form-label">Tahun Ajaran</label>
            <select name="ta" class="form-control" onchange="this.form.submit()">
              <?php foreach ($ta_list as $ta): ?>
              <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_id?'selected':'' ?>>
                <?= e($ta['nama']) ?>/<?= $ta['semester'] ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <!-- Kelas -->
          <div class="form-group mb-0">
            <label class="form-label">Kelas</label>
            <select name="kelas" class="form-control" onchange="this.form.submit()">
              <option value="">— Pilih —</option>
              <?php foreach ($kelas_list as $k): ?>
              <option value="<?= $k['id'] ?>" <?= $k['id']==$kelas_id?'selected':'' ?>><?= e($k['nama_kelas']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <!-- Mapel -->
          <div class="form-group mb-0">
            <label class="form-label">Mata Pelajaran</label>
            <select name="mapel" class="form-control" onchange="this.form.submit()">
              <option value="">— Pilih —</option>
              <?php foreach ($mapel_list as $m): ?>
              <option value="<?= $m['id'] ?>" <?= $m['id']==$mapel_id?'selected':'' ?>><?= e($m['nama_mapel']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <!-- Jenis -->
          <div class="form-group mb-0">
            <label class="form-label">Jenis</label>
            <select name="jenis" class="form-control" onchange="this.form.submit()">
              <?php foreach ($jenis_opts as $jk => $jv): ?>
              <option value="<?= $jk ?>" <?= $jenis===$jk?'selected':'' ?>><?= $jv ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <!-- Bulan (kecuali semester) -->
          <?php if ($jenis !== 'semester'): ?>
          <div class="form-group mb-0">
            <label class="form-label">Bulan</label>
            <select name="bulan" class="form-control" onchange="this.form.submit()">
              <option value="">— Pilih —</option>
              <?php foreach ($bulan_opts as $bk => $bv): ?>
              <option value="<?= $bk ?>" <?= $bk==$bulan?'selected':'' ?>><?= $bv ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
          <input type="hidden" name="bulan" value="0">
          <?php endif; ?>
          <!-- Tombol -->
          <div class="form-group mb-0 flex items-end">
            <button type="submit" class="btn btn-primary w-full">Tampilkan</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php if (!$kelas_id || !$mapel_id): ?>
  <!-- Belum pilih filter -->
  <div class="card">
    <div class="card-body empty-state">
      <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" class="mx-auto mb-3">
        <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
      </svg>
      <p>Pilih <strong>Kelas</strong> dan <strong>Mata Pelajaran</strong> untuk mulai input nilai.</p>
    </div>
  </div>

  <?php else: ?>

  <!-- ── INFO HEADER (seperti format Excel) ──────────────────── -->
  <div class="card mb-4" style="border-left:4px solid #f97316">
    <div class="card-body py-3">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
        <div class="flex gap-2">
          <span class="text-gray-400 w-36 flex-shrink-0">Mata Pelajaran</span>
          <span class="font-bold text-gray-800">: <?= e($mapel_info['nama_mapel'] ?? '-') ?></span>
        </div>
        <div class="flex gap-2">
          <span class="text-gray-400 w-36 flex-shrink-0">Kelas</span>
          <span class="font-bold text-gray-800">: <?= e($kelas_info['nama_kelas'] ?? '-') ?></span>
        </div>
        <div class="flex gap-2">
          <span class="text-gray-400 w-36 flex-shrink-0">Guru Mata Pelajaran</span>
          <span class="font-bold text-orange-600">: <?= e($guru_pengampu['nama_lengkap'] ?? $user['nama_lengkap']) ?></span>
        </div>
        <div class="flex gap-2">
          <span class="text-gray-400 w-36 flex-shrink-0">Jenis Raport</span>
          <span class="font-semibold">: <?= e($jenis_opts[$jenis] ?? $jenis) ?></span>
        </div>
        <div class="flex gap-2">
          <span class="text-gray-400 w-36 flex-shrink-0">Periode</span>
          <span class="font-semibold">: <?= $jenis==='semester' ? 'Akhir Semester' : e($bulan_opts[$bulan] ?? '—') ?></span>
        </div>
        <div class="flex gap-2">
          <span class="text-gray-400 w-36 flex-shrink-0">Jumlah Siswa</span>
          <span class="font-semibold">: <?= count($siswa_list) ?> siswa</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ── STEP 2: FORM INPUT ──────────────────────────────────── -->
  <form method="POST" action="index.php?page=nilai.bulk" id="formBulk">
    <?= csrf_field() ?>
    <input type="hidden" name="tahun_ajaran_id" value="<?= $ta_id ?>">
    <input type="hidden" name="kelas_id"        value="<?= $kelas_id ?>">
    <input type="hidden" name="mapel_id"        value="<?= $mapel_id ?>">
    <input type="hidden" name="jenis_raport"    value="<?= e($jenis) ?>">
    <input type="hidden" name="bulan"           value="<?= $bulan ?>">
    <?php foreach ($siswa_list as $s): ?>
    <input type="hidden" name="siswa_ids[]" value="<?= $s['id'] ?>">
    <?php endforeach; ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

      <!-- ── KIRI: Tabel Nilai Siswa ─────────────────────────── -->
      <div class="xl:col-span-2">
        <div class="card">
          <div class="card-header">
            <span class="flex items-center gap-2">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
              </svg>
              Nilai Siswa
            </span>
            <div class="flex gap-2">
              <button type="button" onclick="isiSemuaRata()" class="btn btn-sm btn-secondary">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
                </svg>
                Isi Rata-rata
              </button>
              <button type="submit" class="btn btn-sm btn-primary">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                  <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                  <polyline points="17 21 17 13 7 13 7 21"/>
                </svg>
                Simpan Semua
              </button>
            </div>
          </div>

          <div class="table-container">
            <table class="data-table" id="tblNilai">
              <thead>
                <tr>
                  <th style="width:32px">No</th>
                  <th>Nama Siswa</th>
                  <?php if ($jenis === 'bulanan'): ?>
                  <th style="width:68px">NH1</th>
                  <th style="width:68px">NH2</th>
                  <th style="width:68px">NH3</th>
                  <th style="width:68px">Tugas</th>
                  <?php elseif ($jenis === 'sts'): ?>
                  <th style="width:90px">Nilai STS</th>
                  <?php else: ?>
                  <th style="width:90px">Nilai SAS</th>
                  <?php endif; ?>
                  <th style="width:72px">
                    <span class="text-orange-600">Nilai</span>
                  </th>
                  <th style="width:42px">Pred.</th>
                  <th style="width:44px" title="Aksi">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>
                    </svg>
                  </th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($siswa_list)): ?>
                <tr><td colspan="10" class="text-center py-8 text-gray-400">Tidak ada siswa di kelas ini.</td></tr>
                <?php endif; ?>
                <?php foreach ($siswa_list as $i => $s): ?>
                <?php
                  $nv  = $existing_nilai[$s['id']] ?? null;
                  $na  = (float)($nv['nilai_akhir'] ?? 0);
                  $pred = $nv['predikat'] ?? '';
                  $predClass = $pred==='A'?'text-green-600':($pred==='B'?'text-blue-600':($pred==='C'?'text-yellow-600':'text-red-500'));
                ?>
                <tr id="row_<?= $s['id'] ?>" class="hover:bg-orange-50">
                  <td class="text-gray-400 text-xs text-center"><?= $i+1 ?></td>
                  <td>
                    <div class="font-semibold text-sm"><?= e($s['nama_lengkap']) ?></div>
                    <div class="text-xs text-gray-400"><?= e($s['nis']) ?></div>
                  </td>

                  <?php if ($jenis === 'bulanan'): ?>
                  <td>
                    <input type="number" name="h1_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                      value="<?= $nv['nilai_harian_1'] ?? 0 ?>"
                      class="nilai-input form-control text-center px-1" style="padding:4px 2px;font-size:.8rem"
                      data-sid="<?= $s['id'] ?>" data-field="h1" onchange="hitungBaris(<?= $s['id'] ?>)">
                  </td>
                  <td>
                    <input type="number" name="h2_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                      value="<?= $nv['nilai_harian_2'] ?? 0 ?>"
                      class="nilai-input form-control text-center px-1" style="padding:4px 2px;font-size:.8rem"
                      data-sid="<?= $s['id'] ?>" data-field="h2" onchange="hitungBaris(<?= $s['id'] ?>)">
                  </td>
                  <td>
                    <input type="number" name="h3_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                      value="<?= $nv['nilai_harian_3'] ?? 0 ?>"
                      class="nilai-input form-control text-center px-1" style="padding:4px 2px;font-size:.8rem"
                      data-sid="<?= $s['id'] ?>" data-field="h3" onchange="hitungBaris(<?= $s['id'] ?>)">
                  </td>
                  <td>
                    <input type="number" name="tugas_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                      value="<?= $nv['nilai_tugas'] ?? 0 ?>"
                      class="nilai-input form-control text-center px-1" style="padding:4px 2px;font-size:.8rem"
                      data-sid="<?= $s['id'] ?>" data-field="tugas" onchange="hitungBaris(<?= $s['id'] ?>)">
                  </td>

                  <?php elseif ($jenis === 'sts'): ?>
                  <td>
                    <input type="number" name="sts_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                      value="<?= $nv['nilai_sts'] ?? 0 ?>"
                      class="nilai-input form-control text-center" style="padding:4px 2px;font-size:.8rem"
                      data-sid="<?= $s['id'] ?>" data-field="sts" onchange="hitungBaris(<?= $s['id'] ?>)">
                  </td>

                  <?php else: ?>
                  <td>
                    <input type="number" name="sas_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                      value="<?= $nv['nilai_sas'] ?? 0 ?>"
                      class="nilai-input form-control text-center" style="padding:4px 2px;font-size:.8rem"
                      data-sid="<?= $s['id'] ?>" data-field="sas" onchange="hitungBaris(<?= $s['id'] ?>)">
                  </td>
                  <?php endif; ?>

                  <!-- Nilai Akhir (readonly, dihitung JS) -->
                  <td>
                    <input type="number" id="na_<?= $s['id'] ?>"
                      value="<?= $na > 0 ? $na : '' ?>"
                      class="form-control text-center font-bold text-orange-600"
                      style="padding:4px 2px;font-size:.85rem;background:#fff7ed;border-color:#fed7aa"
                      readonly tabindex="-1">
                  </td>

                  <!-- Predikat -->
                  <td class="text-center">
                    <span id="pred_<?= $s['id'] ?>"
                      class="font-bold <?= $predClass ?>"
                      style="font-size:.9rem">
                      <?= $pred ?: '—' ?>
                    </span>
                  </td>

                  <!-- Tombol deskripsi -->
                  <td class="text-center">
                    <button type="button"
                      onclick="toggleDeskSiswa(<?= $s['id'] ?>)"
                      class="btn btn-sm btn-secondary" style="padding:3px 7px"
                      title="Deskripsi">
                      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                      </svg>
                    </button>
                  </td>
                </tr>
                <!-- Baris deskripsi siswa (tersembunyi, expand via tombol) -->
                <tr id="deskRow_<?= $s['id'] ?>" style="display:none;background:#fffbf5">
                  <td colspan="<?= $jenis==='bulanan'?10:8 ?>" style="padding:6px 12px">
                    <div class="flex items-start gap-2">
                      <label class="text-xs font-semibold text-gray-500 mt-1 whitespace-nowrap">Deskripsi Siswa:</label>
                      <textarea name="deskripsi_<?= $s['id'] ?>" rows="2"
                        class="form-control text-sm flex-1"
                        placeholder="Kosongkan untuk menggunakan deskripsi dari kriteria predikat secara otomatis"><?= e($nv['deskripsi'] ?? '') ?></textarea>
                      <input type="hidden" name="catatan_<?= $s['id'] ?>" value="<?= e($nv['catatan'] ?? '') ?>">
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <?php if (!empty($siswa_list)): ?>
              <tfoot>
                <tr style="background:#fff7ed;font-weight:700">
                  <td colspan="<?= $jenis==='bulanan'?6:3 ?>" class="text-right px-3 py-2 text-sm">Rata-rata Kelas:</td>
                  <td class="text-center text-orange-600" id="rataKelas">—</td>
                  <td colspan="3"></td>
                </tr>
              </tfoot>
              <?php endif; ?>
            </table>
          </div>

          <!-- Tombol simpan bawah -->
          <div class="p-4 border-t border-gray-100 flex justify-end">
            <button type="submit" class="btn btn-primary">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                <polyline points="17 21 17 13 7 13 7 21"/>
              </svg>
              Simpan Semua Nilai
            </button>
          </div>
        </div>
      </div>

      <!-- ── KANAN: Deskripsi Kriteria ───────────────────────── -->
      <div class="xl:col-span-1">
        <div class="card" style="position:sticky;top:80px">
          <div class="card-header">
            <span class="flex items-center gap-2">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
              </svg>
              Deskripsi per Kriteria
            </span>
            <span class="text-xs text-gray-400">Otomatis ke deskripsi siswa</span>
          </div>
          <div class="card-body" style="padding:12px">
            <p class="text-xs text-gray-400 mb-3">
              Isi deskripsi untuk setiap predikat. Saat simpan, siswa akan otomatis mendapat
              deskripsi sesuai predikatnya (jika deskripsi siswa dikosongkan).
            </p>

            <?php
            $krit_data = [
              'A' => ['label'=>'Predikat A', 'range'=>"≥{$krit_a}", 'color'=>'green', 'border'=>'#22c55e', 'bg'=>'#f0fdf4'],
              'B' => ['label'=>'Predikat B', 'range'=>"≥{$krit_b}", 'color'=>'blue',  'border'=>'#3b82f6', 'bg'=>'#eff6ff'],
              'C' => ['label'=>'Predikat C', 'range'=>"≥{$krit_c}", 'color'=>'yellow','border'=>'#eab308', 'bg'=>'#fefce8'],
              'D' => ['label'=>'Predikat D', 'range'=>"<{$krit_c}", 'color'=>'red',   'border'=>'#ef4444', 'bg'=>'#fef2f2'],
            ];
            foreach ($krit_data as $kr => $kd):
            ?>
            <div style="border-left:3px solid <?= $kd['border'] ?>;background:<?= $kd['bg'] ?>;
                        border-radius:0 8px 8px 0;padding:10px 12px;margin-bottom:10px">
              <div class="flex items-center justify-between mb-1">
                <span class="font-bold text-sm" style="color:<?= $kd['border'] ?>"><?= $kd['label'] ?></span>
                <span class="text-xs text-gray-400"><?= $kd['range'] ?></span>
              </div>
              <textarea name="desk_<?= $kr ?>" rows="3"
                class="form-control text-xs" style="font-size:.8rem;line-height:1.5"
                placeholder="Masukkan deskripsi untuk siswa dengan predikat <?= $kr ?>..."
                oninput="updateDeskPreview('<?= $kr ?>', this.value)"><?= e($deskripsi_kriteria[$kr] ?? '') ?></textarea>
              <button type="button" class="btn btn-sm btn-secondary mt-1 w-full text-xs"
                onclick="applyDeskToAll('<?= $kr ?>', this.previousElementSibling.value)">
                Terapkan ke semua siswa predikat <?= $kr ?>
              </button>
            </div>
            <?php endforeach; ?>

            <div class="mt-2 p-2 rounded-lg bg-gray-50 border border-gray-200 text-xs text-gray-500">
              <strong>Kriteria nilai:</strong><br>
              A: ≥<?= (int)$krit_a ?> &nbsp; B: ≥<?= (int)$krit_b ?> &nbsp;
              C: ≥<?= (int)$krit_c ?> &nbsp; D: &lt;<?= (int)$krit_c ?>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /.grid -->
  </form>
  <?php endif; ?>
</div>

<script>
/* ── Konfigurasi kriteria dari server ───────────────────────── */
const KRIT = { A: <?= $krit_a ?>, B: <?= $krit_b ?>, C: <?= $krit_c ?> };
const JENIS = '<?= e($jenis) ?>';

/**
 * Hitung nilai akhir satu baris siswa
 */
function hitungBaris(sid) {
  let na = 0;
  if (JENIS === 'bulanan') {
    const h1  = parseFloat(document.querySelector(`[name="h1_${sid}"]`)?.value)    || 0;
    const h2  = parseFloat(document.querySelector(`[name="h2_${sid}"]`)?.value)    || 0;
    const h3  = parseFloat(document.querySelector(`[name="h3_${sid}"]`)?.value)    || 0;
    const tug = parseFloat(document.querySelector(`[name="tugas_${sid}"]`)?.value) || 0;
    const rata = (h1 + h2 + h3) / 3;
    na = (rata * 0.7) + (tug * 0.3);
  } else if (JENIS === 'sts') {
    na = parseFloat(document.querySelector(`[name="sts_${sid}"]`)?.value) || 0;
  } else {
    na = parseFloat(document.querySelector(`[name="sas_${sid}"]`)?.value) || 0;
  }
  na = Math.round(na * 100) / 100;

  // Tampilkan nilai akhir
  const naEl = document.getElementById(`na_${sid}`);
  if (naEl) naEl.value = na > 0 ? na.toFixed(2) : '';

  // Predikat
  let pred = 'D';
  if      (na >= KRIT.A) pred = 'A';
  else if (na >= KRIT.B) pred = 'B';
  else if (na >= KRIT.C) pred = 'C';

  const predEl = document.getElementById(`pred_${sid}`);
  if (predEl) {
    predEl.textContent = na > 0 ? pred : '—';
    predEl.className = 'font-bold ' + {
      A:'text-green-600', B:'text-blue-600', C:'text-yellow-600', D:'text-red-500'
    }[pred];
  }

  // Highlight baris jika 100
  const row = document.getElementById(`row_${sid}`);
  if (row) {
    row.style.color = na === 100 ? '#ea580c' : '';
    row.style.fontWeight = na === 100 ? '700' : '';
  }

  hitungRataKelas();
}

/**
 * Hitung rata-rata kelas dari semua nilai akhir
 */
function hitungRataKelas() {
  const inputs = document.querySelectorAll('[id^="na_"]');
  let sum = 0, count = 0;
  inputs.forEach(el => {
    const v = parseFloat(el.value);
    if (!isNaN(v) && v > 0) { sum += v; count++; }
  });
  const rataEl = document.getElementById('rataKelas');
  if (rataEl) rataEl.textContent = count > 0 ? (sum/count).toFixed(2) : '—';
}

/**
 * Expand/collapse baris deskripsi siswa
 */
function toggleDeskSiswa(sid) {
  const row = document.getElementById(`deskRow_${sid}`);
  if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
}

/**
 * Auto-fill deskripsi siswa yang predikatnya sama (hanya yang masih kosong)
 */
function updateDeskPreview(kr, val) {
  document.querySelectorAll('[id^="pred_"]').forEach(predEl => {
    if (predEl.textContent.trim() !== kr) return;
    const sid = predEl.id.replace('pred_', '');
    const deskEl = document.querySelector(`[name="deskripsi_${sid}"]`);
    if (deskEl && deskEl.value.trim() === '') {
      deskEl.value = val;
    }
  });
}

/**
 * Terapkan deskripsi ke SEMUA siswa dengan predikat tertentu (paksa overwrite)
 */
function applyDeskToAll(kr, val) {
  if (!val.trim()) { alert('Isi deskripsi terlebih dahulu.'); return; }
  let count = 0;
  document.querySelectorAll('[id^="pred_"]').forEach(predEl => {
    if (predEl.textContent.trim() !== kr) return;
    const sid = predEl.id.replace('pred_', '');
    const deskEl = document.querySelector(`[name="deskripsi_${sid}"]`);
    if (deskEl) { deskEl.value = val; count++; }
    // Otomatis tampilkan baris deskripsi
    const deskRow = document.getElementById(`deskRow_${sid}`);
    if (deskRow) deskRow.style.display = '';
  });
  if (count > 0) showToast(`Deskripsi diterapkan ke ${count} siswa predikat ${kr}`, 'success');
}

/**
 * Isi field dengan rata-rata (misalnya semua NH = 0 lalu set ke 80 default)
 * Digunakan tombol "Isi Rata-rata" untuk testing
 */
function isiSemuaRata() {
  const val = prompt('Isi nilai default untuk semua siswa (0-100):', '85');
  if (val === null) return;
  const num = parseFloat(val);
  if (isNaN(num) || num < 0 || num > 100) { alert('Nilai tidak valid!'); return; }

  document.querySelectorAll('[id^="row_"]').forEach(row => {
    const sid = row.id.replace('row_', '');
    if (JENIS === 'bulanan') {
      ['h1','h2','h3','tugas'].forEach(f => {
        const el = document.querySelector(`[name="${f}_${sid}"]`);
        if (el) el.value = num;
      });
    } else if (JENIS === 'sts') {
      const el = document.querySelector(`[name="sts_${sid}"]`);
      if (el) el.value = num;
    } else {
      const el = document.querySelector(`[name="sas_${sid}"]`);
      if (el) el.value = num;
    }
    hitungBaris(parseInt(sid));
  });
}

// Init: hitung semua baris yang sudah ada nilai
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[id^="row_"]').forEach(row => {
    const sid = row.id.replace('row_', '');
    hitungBaris(parseInt(sid));
  });
});
</script>
