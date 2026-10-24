<?php
/**
 * pages/nilai/form.php
 * Form input/edit nilai siswa
 */
require_role(['admin', 'guru', 'wali_kelas']);

$user        = current_user();
$role        = $user['role'];
$id          = (int)($_GET['id'] ?? 0);
$is_edit     = ($id > 0);
$nilai_data  = null;
$ta_aktif    = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');

// Ambil status dari tabel guru
$guru_detail = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user['id']]);
$guru_status = $guru_detail['status'] ?? 'guru';

// Kelas yang dipimpin (untuk wali kelas)
$wali_kelas_ids = [];
if ($guru_status === 'wali_kelas') {
    $wk_rows = db_query('SELECT id FROM kelas WHERE wali_kelas_id = :uid', [':uid' => $user['id']]);
    $wali_kelas_ids = array_column($wk_rows, 'id');
}

if ($is_edit) {
    $nilai_data = db_row('SELECT * FROM nilai WHERE id = :id', [':id' => $id]);
    // Cek akses edit:
    // admin: boleh semua
    // wali_kelas: boleh jika nilai di kelasnya ATAU dia yang input
    // guru biasa: hanya yang dia input
    $boleh_edit = false;
    if ($role === 'admin') {
        $boleh_edit = true;
    } elseif ($guru_status === 'wali_kelas') {
        $boleh_edit = ($nilai_data['guru_user_id'] == $user['id'])
                   || in_array($nilai_data['kelas_id'], $wali_kelas_ids);
    } else {
        $boleh_edit = ($nilai_data['guru_user_id'] == $user['id']);
    }
    if (!$nilai_data || !$boleh_edit) {
        flash('error','Data tidak ditemukan atau akses ditolak.');
        header('Location: index.php?page=nilai'); exit;
    }
}

$errors = [];

// ── Handle Submit ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'Token tidak valid.'; }
    else {
        $siswa_id = (int)$_POST['siswa_user_id'];
        $mapel_id = (int)$_POST['mapel_id'];
        $kelas_id = (int)$_POST['kelas_id'];
        $ta_id    = (int)$_POST['tahun_ajaran_id'];
        $jenis    = $_POST['jenis_raport'] ?? 'semester';
        $bulan    = ($jenis === 'bulanan') ? (int)$_POST['bulan'] : null;

        $h1   = (float)($_POST['nilai_harian_1'] ?? 0);
        $h2   = (float)($_POST['nilai_harian_2'] ?? 0);
        $h3   = (float)($_POST['nilai_harian_3'] ?? 0);
        $tug  = (float)($_POST['nilai_tugas'] ?? 0);
        $sts  = (float)($_POST['nilai_sts'] ?? 0);
        $sas  = (float)($_POST['nilai_sas'] ?? 0);
        $catatan  = trim($_POST['catatan'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');

        // Hitung nilai akhir sesuai jenis raport
        if ($jenis === 'semester') {
            $nilai_akhir = hitung_nilai_semester($sas);
        } elseif ($jenis === 'sts') {
            $nilai_akhir = hitung_nilai_sts($sts);
        } else {
            // bulanan: hanya NH1+NH2+NH3 + Tugas (tanpa STS)
            $nilai_akhir = hitung_nilai_bulanan($h1, $h2, $h3, $tug);
        }
        $predikat = get_predikat($nilai_akhir);

        if (!$siswa_id || !$mapel_id || !$kelas_id || !$ta_id) {
            $errors[] = 'Siswa, Mapel, Kelas, dan Tahun Ajaran wajib dipilih.';
        }

        if (empty($errors)) {
            $p = [
                ':siswa'=>$siswa_id, ':mapel'=>$mapel_id, ':kelas'=>$kelas_id, ':ta'=>$ta_id,
                ':guru'=>$user['id'], ':jenis'=>$jenis, ':bulan'=>$bulan,
                ':h1'=>$h1, ':h2'=>$h2, ':h3'=>$h3, ':tug'=>$tug, ':sts'=>$sts, ':sas'=>$sas,
                ':na'=>$nilai_akhir, ':pred'=>$predikat, ':cat'=>$catatan, ':desk'=>$deskripsi,
            ];

            if ($is_edit) {
                db_execute(
                    'UPDATE nilai SET siswa_user_id=:siswa, mapel_id=:mapel, kelas_id=:kelas,
                     tahun_ajaran_id=:ta, guru_user_id=:guru, jenis_raport=:jenis, bulan=:bulan,
                     nilai_harian_1=:h1, nilai_harian_2=:h2, nilai_harian_3=:h3,
                     nilai_tugas=:tug, nilai_sts=:sts, nilai_sas=:sas,
                     nilai_akhir=:na, predikat=:pred, catatan=:cat, deskripsi=:desk
                     WHERE id=:id',
                    array_merge($p, [':id'=>$id])
                );
                flash('success','Nilai berhasil diperbarui.');
            } else {
                try {
                    db_execute(
                        'INSERT INTO nilai (siswa_user_id,mapel_id,kelas_id,tahun_ajaran_id,guru_user_id,
                          jenis_raport,bulan,nilai_harian_1,nilai_harian_2,nilai_harian_3,
                          nilai_tugas,nilai_sts,nilai_sas,nilai_akhir,predikat,catatan,deskripsi)
                         VALUES (:siswa,:mapel,:kelas,:ta,:guru,:jenis,:bulan,:h1,:h2,:h3,:tug,:sts,:sas,:na,:pred,:cat,:desk)',
                        $p
                    );
                    flash('success','Nilai berhasil disimpan.');
                } catch (\PDOException $e) {
                    $errors[] = 'Nilai untuk siswa-mapel-kelas-periode tersebut sudah ada. Silakan edit.';
                }
            }
            if (empty($errors)) {
                header('Location: index.php?page=nilai'); exit;
            }
        }
    }
}

$ta_list   = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$ta_sel    = (int)($_POST['tahun_ajaran_id'] ?? $nilai_data['tahun_ajaran_id'] ?? ($ta_aktif['id'] ?? 0));
// kelas_sel: dari POST (saat submit form), dari GET ?kelas= (saat filter), atau dari data nilai (saat edit)
$kelas_sel = (int)($_POST['kelas_id'] ?? $_GET['kelas'] ?? $nilai_data['kelas_id'] ?? 0);

// ── Kelas yang bisa dipilih ─────────────────────────────────────
if ($role === 'admin') {
    // Admin: semua kelas
    $kelas_list = db_query(
        'SELECT k.* FROM kelas k ORDER BY k.nama_kelas'
    );
} elseif ($guru_status === 'wali_kelas') {
    // Wali kelas: kelas yang dipimpin + kelas yang diajar
    // :uid2 = nilai sama dengan :uid — PDO tidak boleh pakai named param duplikat
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         LEFT JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id AND gmk.guru_user_id = :uid
         WHERE k.wali_kelas_id = :uid2 OR gmk.id IS NOT NULL
         ORDER BY k.nama_kelas',
        [':uid' => $user['id'], ':uid2' => $user['id']]
    );
} else {
    // Guru biasa: hanya kelas yang diajar
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id
         WHERE gmk.guru_user_id = :uid ORDER BY k.nama_kelas',
        [':uid' => $user['id']]
    );
}

// ── Mapel berdasarkan kelas yang dipilih & hak akses ───────────
if (!$kelas_sel) {
    // Belum pilih kelas — kosongkan
    $mapel_list = [];
} elseif ($role === 'admin') {
    // Admin: semua mapel yang ada di kelas itu
    $mapel_list = db_query(
        'SELECT DISTINCT m.* FROM mapel m
         INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id = m.id
         WHERE gmk.kelas_id = :kid ORDER BY m.urutan, m.nama_mapel',
        [':kid' => $kelas_sel]
    );
} elseif ($guru_status === 'wali_kelas' && in_array($kelas_sel, $wali_kelas_ids)) {
    // Wali kelas di kelas yang dipimpinnya → semua mapel di kelas tersebut
    $mapel_list = db_query(
        'SELECT DISTINCT m.* FROM mapel m
         INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id = m.id
         WHERE gmk.kelas_id = :kid ORDER BY m.urutan, m.nama_mapel',
        [':kid' => $kelas_sel]
    );
} else {
    // Guru biasa (atau wali kelas di kelas lain): hanya mapel yang diajarnya
    $mapel_list = db_query(
        'SELECT DISTINCT m.* FROM mapel m
         INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id = m.id
         WHERE gmk.guru_user_id = :uid AND gmk.kelas_id = :kid
         ORDER BY m.urutan, m.nama_mapel',
        [':uid' => $user['id'], ':kid' => $kelas_sel]
    );
}

// Siswa berdasarkan kelas
$siswa_list = $kelas_sel
    ? db_query('SELECT u.id, u.nama_lengkap, s.nis FROM users u INNER JOIN siswa s ON s.user_id = u.id
                WHERE s.kelas_id = :kid ORDER BY u.nama_lengkap', [':kid' => $kelas_sel])
    : [];

$bulan_opts = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
               7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
$n = $nilai_data ?? [];
?>
<div class="page-content" style="max-width:760px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title"><?= $is_edit ? 'Edit Nilai' : 'Input Nilai' ?></h1>
      <p class="page-subtitle">Masukkan nilai siswa per mata pelajaran</p>
    </div>
    <a href="index.php?page=nilai" class="btn btn-secondary">← Kembali</a>
  </div>

  <?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if (!$is_edit): ?>
  <!-- Filter Kelas — GET form, terpisah dari form POST nilai -->
  <div class="card mb-4">
    <div class="card-body py-3">
      <form method="GET" action="index.php" class="flex flex-wrap gap-3 items-end">
        <input type="hidden" name="page" value="nilai.input">
        <div class="form-group mb-0">
          <label class="form-label">Kelas</label>
          <select name="kelas" class="form-control" onchange="this.form.submit()">
            <option value="">— Pilih Kelas —</option>
            <?php foreach ($kelas_list as $k): ?>
            <option value="<?= $k['id'] ?>" <?= $k['id']==$kelas_sel?'selected':'' ?>>
              <?= e($k['nama_kelas']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group mb-0">
          <button type="submit" class="btn btn-secondary">Muat Siswa & Mapel</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">Form Nilai</div>
    <div class="card-body">
      <form method="POST" action="index.php?page=nilai.input<?= $is_edit?'&id='.$id:'' ?>" id="formNilai">
        <?= csrf_field() ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

          <!-- Tahun Ajaran -->
          <div class="form-group">
            <label class="form-label">Tahun Ajaran <span class="text-red-500">*</span></label>
            <select name="tahun_ajaran_id" class="form-control" required>
              <?php foreach ($ta_list as $ta): ?>
              <option value="<?= $ta['id'] ?>" <?= ($n['tahun_ajaran_id']??$ta_aktif['id']??0)==$ta['id']?'selected':'' ?>>
                <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Kelas (hidden di form POST, sudah dipilih via GET di atas) -->
          <div class="form-group">
            <label class="form-label">Kelas <span class="text-red-500">*</span></label>
            <?php if ($is_edit): ?>
            <select name="kelas_id" class="form-control" required>
              <option value="">— Pilih Kelas —</option>
              <?php foreach ($kelas_list as $k): ?>
              <option value="<?= $k['id'] ?>" <?= ($n['kelas_id']??0)==$k['id']?'selected':'' ?>>
                <?= e($k['nama_kelas']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="hidden" name="kelas_id" value="<?= $kelas_sel ?>">
            <div class="form-control" style="background:#f9fafb;cursor:not-allowed">
              <?= $kelas_sel ? e(array_column($kelas_list,'nama_kelas',null)[array_search($kelas_sel, array_column($kelas_list,'id'))] ?? '—') : '— Pilih kelas di atas —' ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- Mata Pelajaran -->
          <div class="form-group">
            <label class="form-label">Mata Pelajaran <span class="text-red-500">*</span></label>
            <select name="mapel_id" class="form-control" required>
              <option value="">— Pilih Mapel —</option>
              <?php foreach ($mapel_list as $m): ?>
              <option value="<?= $m['id'] ?>" <?= ($n['mapel_id']??0)==$m['id']?'selected':'' ?>>
                <?= e($m['nama_mapel']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Siswa -->
          <div class="form-group">
            <label class="form-label">Siswa <span class="text-red-500">*</span></label>
            <select name="siswa_user_id" class="form-control" required>
              <option value="">— Pilih Siswa —</option>
              <?php foreach ($siswa_list as $s): ?>
              <option value="<?= $s['id'] ?>" <?= ($n['siswa_user_id']??0)==$s['id']?'selected':'' ?>>
                <?= e($s['nama_lengkap']) ?> (<?= e($s['nis']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Jenis Raport -->
          <div class="form-group">
            <label class="form-label">Jenis Raport <span class="text-red-500">*</span></label>
            <select name="jenis_raport" class="form-control" required id="jenisRaportSelect">
              <option value="bulanan" <?= ($n['jenis_raport']??'bulanan')==='bulanan'?'selected':'' ?>>
                Bulanan (NH1 + NH2 + NH3 + Tugas)
              </option>
              <option value="sts" <?= ($n['jenis_raport']??'')==='sts'?'selected':'' ?>>
                STS — Sumatif Tengah Semester
              </option>
              <option value="semester" <?= ($n['jenis_raport']??'')==='semester'?'selected':'' ?>>
                Semester / SAS — Sumatif Akhir Semester
              </option>
            </select>
          </div>

          <!-- Bulan (bulanan & STS) -->
          <div class="form-group" id="fieldBulanWrap">
            <label class="form-label">Bulan</label>
            <select name="bulan" class="form-control">
              <option value="">— Pilih Bulan —</option>
              <?php foreach ($bulan_opts as $bk => $bv): ?>
              <option value="<?= $bk ?>" <?= ($n['bulan']??0)==$bk?'selected':'' ?>><?= $bv ?></option>
              <?php endforeach; ?>
            </select>
          </div>

        </div>

        <div class="divider"></div>

        <!-- ── FIELD BULANAN ───────────────────────────────────── -->
        <div id="groupBulanan">
          <p class="text-sm font-semibold text-gray-600 mb-3">
            Komponen Nilai Bulanan
            <span class="text-xs text-gray-400 font-normal ml-1">— Nilai Akhir = (Rata-rata NH × 70%) + (Tugas × 30%)</span>
          </p>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="form-group">
              <label class="form-label">Nilai Harian 1</label>
              <input type="number" name="nilai_harian_1" class="form-control" min="0" max="100" step="0.01"
                value="<?= $n['nilai_harian_1'] ?? 0 ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Nilai Harian 2</label>
              <input type="number" name="nilai_harian_2" class="form-control" min="0" max="100" step="0.01"
                value="<?= $n['nilai_harian_2'] ?? 0 ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Nilai Harian 3</label>
              <input type="number" name="nilai_harian_3" class="form-control" min="0" max="100" step="0.01"
                value="<?= $n['nilai_harian_3'] ?? 0 ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Nilai Tugas</label>
              <input type="number" name="nilai_tugas" class="form-control" min="0" max="100" step="0.01"
                value="<?= $n['nilai_tugas'] ?? 0 ?>">
            </div>
          </div>
        </div>

        <!-- ── FIELD STS ───────────────────────────────────────── -->
        <div id="groupSts">
          <p class="text-sm font-semibold text-gray-600 mb-3">
            Komponen Nilai STS
            <span class="text-xs text-gray-400 font-normal ml-1">— Nilai Akhir = Nilai STS</span>
          </p>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div class="form-group">
              <label class="form-label">Nilai STS</label>
              <input type="number" name="nilai_sts" class="form-control" min="0" max="100" step="0.01"
                value="<?= $n['nilai_sts'] ?? 0 ?>">
            </div>
          </div>
        </div>

        <!-- ── FIELD SEMESTER ──────────────────────────────────── -->
        <div id="groupSemester">
          <p class="text-sm font-semibold text-gray-600 mb-3">
            Komponen Nilai Semester
            <span class="text-xs text-gray-400 font-normal ml-1">— Nilai Akhir = Nilai SAS</span>
          </p>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div class="form-group">
              <label class="form-label">Nilai SAS (Sumatif Akhir Semester)</label>
              <input type="number" name="nilai_sas" class="form-control" min="0" max="100" step="0.01"
                value="<?= $n['nilai_sas'] ?? 0 ?>">
            </div>
          </div>
        </div>

        <!-- Nilai Akhir & Predikat (readonly, dihitung JS) -->
        <div class="grid grid-cols-2 gap-4 mt-2">
          <div class="form-group">
            <label class="form-label">Nilai Akhir (Otomatis)</label>
            <input type="number" name="nilai_akhir" id="nilai_akhir" class="form-control"
              style="background:#f9fafb;font-weight:700" readonly
              value="<?= $n['nilai_akhir'] ?? 0 ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Predikat</label>
            <div class="form-control flex items-center" style="background:#f9fafb;height:40px">
              <span id="predikatDisplay" class="font-bold text-xl">
                <?= $n['predikat'] ?? '—' ?>
              </span>
            </div>
          </div>
        </div>

        <!-- Deskripsi (tampil di raport) -->
        <div class="form-group mt-2">
          <label class="form-label">
            Deskripsi Nilai
            <span class="text-xs text-orange-500 font-normal ml-1">— tampil di raport</span>
          </label>
          <textarea name="deskripsi" class="form-control" rows="3"
            placeholder="Contoh: Murid memiliki kompetensi yang baik dalam memahami Konsep Akidah Islam"><?= e($n['deskripsi'] ?? '') ?></textarea>
        </div>

        <!-- Catatan internal -->
        <div class="form-group">
          <label class="form-label">
            Catatan Internal
            <span class="text-xs text-gray-400 font-normal ml-1">— tidak tampil di raport</span>
          </label>
          <textarea name="catatan" class="form-control" rows="2"
            placeholder="Catatan internal guru (opsional)"><?= e($n['catatan'] ?? '') ?></textarea>
        </div>

        <div class="flex gap-3 mt-4">
          <button type="submit" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
              <polyline points="17 21 17 13 7 13 7 21"/>
            </svg>
            Simpan Nilai
          </button>
          <a href="index.php?page=nilai" class="btn btn-secondary">Batal</a>
        </div>
      </form>
    </div>
  </div>
</div>
