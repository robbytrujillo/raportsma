<?php
/**
 * pages/raport/index.php
 * Daftar raport &mdash; tampilan sesuai role.
 * Admin/super_admin: toggle publikasi per kelas+periode.
 * Siswa: hanya tampil raport yang sudah dipublikasi.
 */
require_login();

$user     = current_user();
$role     = $user['role'];
$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_id    = (int)($_GET['ta'] ?? ($ta_aktif['id'] ?? 0));
$kelas_id = (int)($_GET['kelas'] ?? 0);
$jenis    = $_GET['jenis'] ?? 'semester';
$bulan    = (int)($_GET['bulan'] ?? 0);
$ta_list  = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');

// -- AJAX: toggle publikasi raport (hanya admin / super_admin) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_publikasi') {
    header('Content-Type: application/json');
    if (!is_admin_or_super()) {
        echo json_encode(['ok'=>false,'msg'=>'Akses ditolak.']); exit;
    }
    if (!verify_csrf()) {
        echo json_encode(['ok'=>false,'msg'=>'Token tidak valid.']); exit;
    }
    $p_kelas = (int)($_POST['kelas_id'] ?? 0);
    $p_ta    = (int)($_POST['ta_id'] ?? 0);
    $p_jenis = in_array($_POST['jenis'] ?? '', ['bulanan','sts','semester']) ? $_POST['jenis'] : '';
    $p_bulan = (int)($_POST['bulan'] ?? 0) ?: null;
    $p_pub   = (int)($_POST['is_published'] ?? 0); // 1 atau 0

    if (!$p_kelas || !$p_ta || !$p_jenis) {
        echo json_encode(['ok'=>false,'msg'=>'Parameter tidak lengkap.']); exit;
    }

    // Upsert raport_publikasi
    $existing = db_row(
        'SELECT id FROM raport_publikasi WHERE kelas_id=:k AND tahun_ajaran_id=:t AND jenis_raport=:j AND (bulan=:b OR (bulan IS NULL AND :b2 IS NULL))',
        [':k'=>$p_kelas,':t'=>$p_ta,':j'=>$p_jenis,':b'=>$p_bulan,':b2'=>$p_bulan]
    );

    if ($existing) {
        db_execute(
            'UPDATE raport_publikasi SET is_published=:p, published_by=:u, published_at=IF(:p2=1,NOW(),NULL), updated_at=NOW() WHERE id=:id',
            [':p'=>$p_pub,':p2'=>$p_pub,':u'=>$user['id'],':id'=>$existing['id']]
        );
    } else {
        db_execute(
            'INSERT INTO raport_publikasi (kelas_id,tahun_ajaran_id,jenis_raport,bulan,is_published,published_by,published_at) VALUES (:k,:t,:j,:b,:p,:u,IF(:p2=1,NOW(),NULL))',
            [':k'=>$p_kelas,':t'=>$p_ta,':j'=>$p_jenis,':b'=>$p_bulan,':p'=>$p_pub,':p2'=>$p_pub,':u'=>$user['id']]
        );
    }
    echo json_encode(['ok'=>true,'is_published'=>$p_pub]); exit;
}

// -- SISWA: hanya raport miliknya yang sudah dipublikasi --------
if ($role === 'siswa') {
    $siswa_data = db_row(
        'SELECT s.*, k.nama_kelas, k.id as kelas_id FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id WHERE s.user_id = :uid',
        [':uid'=>$user['id']]
    );

    // Ambil raport yang tersedia DAN sudah dipublikasi
    $raport_list = db_query(
        'SELECT n.jenis_raport, n.bulan, n.tahun_ajaran_id,
                ta.nama as ta_nama, ta.semester,
                ROUND(AVG(n.nilai_akhir),2) as avg_nilai,
                COUNT(n.id) as jml_mapel
         FROM nilai n
         LEFT JOIN tahun_ajaran ta ON ta.id = n.tahun_ajaran_id
         LEFT JOIN siswa sv ON sv.user_id = n.siswa_user_id
         LEFT JOIN raport_publikasi rp
               ON  rp.kelas_id = sv.kelas_id
               AND rp.tahun_ajaran_id = n.tahun_ajaran_id
               AND rp.jenis_raport = n.jenis_raport
               AND (rp.bulan = n.bulan OR (rp.bulan IS NULL AND n.bulan IS NULL))
         WHERE n.siswa_user_id = :uid
           AND COALESCE(rp.is_published, 0) = 1
         GROUP BY n.jenis_raport, n.bulan, n.tahun_ajaran_id, ta.nama, ta.semester
         ORDER BY n.tahun_ajaran_id DESC, n.jenis_raport, n.bulan',
        [':uid'=>$user['id']]
    );

    // Cek apakah ada nilai tapi belum dipublikasi (untuk pesan info)
    $ada_nilai_belum_rilis = db_row(
        'SELECT COUNT(*) as n FROM nilai n
         LEFT JOIN siswa sv ON sv.user_id = n.siswa_user_id
         LEFT JOIN raport_publikasi rp
               ON  rp.kelas_id = sv.kelas_id
               AND rp.tahun_ajaran_id = n.tahun_ajaran_id
               AND rp.jenis_raport = n.jenis_raport
               AND (rp.bulan = n.bulan OR (rp.bulan IS NULL AND n.bulan IS NULL))
         WHERE n.siswa_user_id = :uid AND COALESCE(rp.is_published, 0) = 0',
        [':uid'=>$user['id']]
    );
    $ada_belum_rilis = (int)($ada_nilai_belum_rilis['n'] ?? 0) > 0;

    // Hitung peringkat kelas
    $rank = '-';
    if ($siswa_data) {
        $rank_data = db_query(
            'SELECT sv.user_id, ROUND(AVG(n.nilai_akhir),2) as avg
             FROM siswa sv INNER JOIN nilai n ON n.siswa_user_id = sv.user_id
             WHERE sv.kelas_id = :kid AND n.tahun_ajaran_id = :ta
             GROUP BY sv.user_id ORDER BY avg DESC',
            [':kid'=>$siswa_data['kelas_id'], ':ta'=>$ta_id]
        );
        foreach ($rank_data as $i => $r) {
            if ($r['user_id'] == $user['id']) { $rank = ($i+1) . ' / ' . count($rank_data); break; }
        }
    }
    ?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Rapor Saya</h1>
            <p class="page-subtitle">Nilai akademik per periode</p>
        </div>
    </div>

    <!-- Info Siswa -->
    <?php if ($siswa_data): ?>
    <div class="card mb-5">
        <div class="card-body">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <div class="text-xs font-semibold text-gray-400 uppercase mb-1">Nama</div>
                    <div class="font-semibold"><?= e($user['nama_lengkap']) ?></div>
                </div>
                <div>
                    <div class="text-xs font-semibold text-gray-400 uppercase mb-1">Kelas</div>
                    <div class="font-semibold"><?= e($siswa_data['nama_kelas'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="text-xs font-semibold text-gray-400 uppercase mb-1">NIS</div>
                    <div class="font-semibold"><?= e($siswa_data['nis'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="text-xs font-semibold text-gray-400 uppercase mb-1">Peringkat</div>
                    <div class="font-bold text-orange-600"><?= $rank ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pemberitahuan raport belum rilis -->
    <?php if ($ada_belum_rilis): ?>
    <div class="alert alert-info mb-4">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
            style="flex-shrink:0">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
        <span>Ada rapor yang belum dirilis. Silakan tunggu pengumuman dari sekolah.</span>
    </div>
    <?php endif; ?>

    <!-- Daftar Rapor Tersedia -->
    <?php if (empty($raport_list)): ?>
    <div class="card">
        <div class="card-body empty-state">
            <?php if ($ada_belum_rilis): ?>
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            <p style="font-weight:600;font-size:1.1rem;margin-bottom:4px">Rapor Belum Dirilis</p>
            <p style="color:#6b7280">Rapor Anda sedang dalam proses penerbitan oleh sekolah.<br>Silakan cek kembali
                nanti.</p>
            <?php else: ?>
            <p>Belum ada nilai yang tersedia.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($raport_list as $r): ?>
        <?php
          $rj = $r['jenis_raport'];
          $rjBadge = $rj==='bulanan' ? 'badge-blue' : ($rj==='sts' ? 'badge-purple' : 'badge-orange');
          $rjLabel = $rj==='bulanan' ? 'Bulanan' : ($rj==='sts' ? 'ASTS' : 'Semester');
          $bulan_nama = (in_array($rj,['bulanan','sts']) && $r['bulan']) ? (BULAN_ID[(int)$r['bulan']] ?? '') : '';
          $avg = (float)($r['avg_nilai'] ?? 0);
          $pred_avg = $avg > 0 ? get_predikat($avg) : '-';
          $pred_color = $pred_avg==='A' ? 'text-green-600' : ($pred_avg==='B' ? 'text-blue-600' : ($pred_avg==='C' ? 'text-yellow-600' : 'text-red-500'));
        ?>
        <div class="card hover:shadow-md transition-all">
            <div class="card-body">
                <div class="flex items-start justify-between mb-2">
                    <div>
                        <div class="font-bold text-gray-800"><?= e($r['ta_nama']) ?></div>
                        <div class="text-xs text-gray-500">Semester <?= $r['semester'] == 1 ? 'Ganjil' : 'Genap' ?>
                        </div>
                    </div>
                    <span class="badge <?= $rjBadge ?>">
                        <?= $rjLabel ?>
                        <?php if ($bulan_nama): ?>&mdash; <?= $bulan_nama ?><?php endif; ?>
                    </span>
                </div>
                <?php if ($avg > 0): ?>
                <div class="flex items-center gap-3 mt-3 p-2 rounded-lg" style="background:#fff7ed">
                    <div>
                        <div class="text-xs text-gray-400">Rata-rata</div>
                        <div class="font-bold text-lg text-orange-600"><?= number_format($avg, 2) ?></div>
                    </div>
                    <div class="ml-auto">
                        <div class="text-xs text-gray-400">Mapel</div>
                        <div class="font-semibold text-gray-700"><?= $r['jml_mapel'] ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="flex gap-2 mt-3">
                    <a href="/raport.view?siswa=<?= $user['id'] ?>&ta=<?= $r['tahun_ajaran_id'] ?>&jenis=<?= $r['jenis_raport'] ?>&bulan=<?= $r['bulan']??0 ?>"
                        class="btn btn-secondary btn-sm flex-1 justify-center" style="border-radius: 30px;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        Lihat
                    </a>
                    <a href="/raport.print?siswa=<?= $user['id'] ?>&ta=<?= $r['tahun_ajaran_id'] ?>&jenis=<?= $r['jenis_raport'] ?>&bulan=<?= $r['bulan']??0 ?>"
                        target="_blank" class="btn btn-primary btn-sm flex-1 justify-center"
                        style="border-radius: 30px;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <polyline points="6 9 6 2 18 2 18 9" />
                            <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" />
                            <rect x="6" y="14" width="12" height="8" />
                        </svg>
                        Cetak
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php
    return;
}

// -- Untuk Admin, Guru, Wali Kelas, Kepsek --------------------
$guru_detail = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user['id']]);
$guru_status = $guru_detail['status'] ?? 'guru';

$_unit_f   = get_user_unit_filter($user);
$_unit_sql = $_unit_f['sql'];

if (in_array($role, ['admin','super_admin','kepala_sekolah'])) {
    $kelas_list = db_query(
        "SELECT * FROM kelas k WHERE k.tahun_ajaran_id = :ta {$_unit_sql} ORDER BY k.nama_kelas",
        [':ta' => $ta_id]
    );
} elseif ($guru_status === 'wali_kelas') {
    $kelas_list = db_query(
        "SELECT DISTINCT k.* FROM kelas k
         LEFT JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id AND gmk.guru_user_id = :uid
         WHERE k.tahun_ajaran_id = :ta
           AND (k.wali_kelas_id = :uid2 OR gmk.id IS NOT NULL)
           {$_unit_sql}
         ORDER BY k.nama_kelas",
        [':uid' => $user['id'], ':uid2' => $user['id'], ':ta' => $ta_id]
    );
} else {
    $kelas_list = db_query(
        "SELECT DISTINCT k.* FROM kelas k
         INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id
         WHERE gmk.guru_user_id = :uid AND gmk.tahun_ajaran_id = :ta
           {$_unit_sql}
         ORDER BY k.nama_kelas",
        [':uid' => $user['id'], ':ta' => $ta_id]
    );
}

// Validasi kelas_id: pastikan kelas yang dipilih ada dalam daftar yang diizinkan untuk user ini
if ($kelas_id) {
    $allowed_kelas_ids = array_column($kelas_list, 'id');
    if (!in_array($kelas_id, array_map('intval', $allowed_kelas_ids))) {
        $kelas_id = 0; // reset jika kelas tidak diizinkan (mis. URL manipulation)
    }
}

// Daftar siswa di kelas yang dipilih beserta nilai rata-rata
$siswa_raport = [];
if ($kelas_id) {
    $bulan_cond  = '';
    $rank_params = [':kid'=>$kelas_id, ':kid2'=>$kelas_id, ':ta'=>$ta_id, ':jenis'=>$jenis];
    if (in_array($jenis, ['bulanan','sts']) && $bulan) {
        $bulan_cond = 'AND n.bulan = :bulan';
        $rank_params[':bulan'] = $bulan;
    }
    $siswa_raport = db_query(
        "SELECT u.id, u.nama_lengkap, s.nis,
                ROUND(AVG(n.nilai_akhir),2) as avg_nilai,
                COUNT(n.id) as jml_mapel
         FROM users u
         INNER JOIN siswa s ON s.user_id = u.id
         LEFT JOIN nilai n ON n.siswa_user_id = u.id
                           AND n.kelas_id = :kid
                           AND n.tahun_ajaran_id = :ta
                           AND n.jenis_raport = :jenis
                           $bulan_cond
         WHERE s.kelas_id = :kid2
         GROUP BY u.id, u.nama_lengkap, s.nis
         ORDER BY avg_nilai DESC",
        $rank_params
    );
}

// Cek apakah user boleh akses leger untuk kelas yang sedang dipilih
// - super_admin, admin, kepala_sekolah, wali_kelas, guru: selalu boleh
// - siswa: tidak boleh
$leger_boleh = in_array($role, ['super_admin','admin','kepala_sekolah','wali_kelas','guru']);

// Cek apakah user boleh cetak semua raport kelas
// - super_admin, admin, kepala_sekolah: selalu boleh
// - wali_kelas: hanya jika dia wali_kelas dari kelas yang dipilih (gunakan $kelas_row bila sudah ada)
// - guru biasa (status 'guru'): TIDAK boleh
if (in_array($role, ['super_admin','admin','kepala_sekolah'])) {
    $cetak_boleh = true;
} elseif ($kelas_id && ($guru_status === 'wali_kelas' || $role === 'wali_kelas')) {
    if (!isset($kelas_row)) {
        $kelas_row = db_row('SELECT wali_kelas_id FROM kelas WHERE id = :id', [':id' => $kelas_id]);
    }
    $cetak_boleh = ($kelas_row && (int)$kelas_row['wali_kelas_id'] === (int)$user['id']);
} else {
    $cetak_boleh = false;
}

// Cek apakah sudah ada nilai yang diinput untuk kelas+filter yang dipilih
// Dipakai sebagai penjaga tombol Leger agar tidak membuka halaman kosong
$ada_nilai = false;
if ($kelas_id && !empty($siswa_raport)) {
    foreach ($siswa_raport as $sr) {
        if ((int)($sr['jml_mapel'] ?? 0) > 0) { $ada_nilai = true; break; }
    }
}

// Status publikasi raport saat ini (untuk kelas+ta+jenis+bulan yang dipilih)
$pub_status = null;
if ($kelas_id && $ta_id && $jenis) {
    $pub_bulan = (in_array($jenis,['bulanan','sts']) && $bulan) ? $bulan : null;
    $pub_status = db_row(
        'SELECT * FROM raport_publikasi WHERE kelas_id=:k AND tahun_ajaran_id=:t AND jenis_raport=:j AND (bulan=:b OR (bulan IS NULL AND :b2 IS NULL))',
        [':k'=>$kelas_id,':t'=>$ta_id,':j'=>$jenis,':b'=>$pub_bulan,':b2'=>$pub_bulan]
    );
}
$is_published = (int)($pub_status['is_published'] ?? 0);

// Kriteria predikat untuk Bell Curve JS
$_sekolah_bc = sekolah_info();
$bc_krit_a   = (float)($_sekolah_bc['kriteria_a_min'] ?? 92);
$bc_krit_b   = (float)($_sekolah_bc['kriteria_b_min'] ?? 83);
$bc_krit_c   = (float)($_sekolah_bc['kriteria_c_min'] ?? 76);

// Nama kelas yang dipilih (untuk subtitle modal)
$bc_kelas_nama = '';
if ($kelas_id) {
    foreach ($kelas_list as $kl) {
        if ((int)$kl['id'] === $kelas_id) { $bc_kelas_nama = $kl['nama_kelas']; break; }
    }
}

// Data Bell Curve: per-siswa per-mapel + rata semua mapel
// Struktur BC_DATA: { mapel_list:[{id,nama},...], siswa:[{nama, by_mapel:{mapel_id:nilai,...}, avg:float|null},...] }
$bc_data = ['mapel_list' => [], 'siswa' => []];
if ($kelas_id && !empty($siswa_raport)) {
    // 1. Ambil mapel yang ada nilainya di kelas+periode ini
    $bc_bulan_cond = '';
    $bc_params     = [':kid'=>$kelas_id, ':ta'=>$ta_id, ':jenis'=>$jenis];
    if (in_array($jenis, ['bulanan','sts']) && $bulan) {
        $bc_bulan_cond = 'AND n.bulan = :bulan';
        $bc_params[':bulan'] = $bulan;
    }
    $bc_mapel_rows = db_query(
        "SELECT DISTINCT m.id, m.nama_mapel, m.kode_mapel, m.urutan
         FROM mapel m
         INNER JOIN nilai n ON n.mapel_id = m.id
         WHERE n.kelas_id = :kid AND n.tahun_ajaran_id = :ta AND n.jenis_raport = :jenis
           $bc_bulan_cond
         ORDER BY m.urutan, m.nama_mapel",
        $bc_params
    );
    foreach ($bc_mapel_rows as $bm) {
        $bc_data['mapel_list'][] = [
            'id'   => (int)$bm['id'],
            'nama' => ($bm['kode_mapel'] ? '['.$bm['kode_mapel'].'] ' : '') . $bm['nama_mapel'],
        ];
    }

    // 2. Ambil semua nilai per-siswa per-mapel sekaligus
    $bc_nilai_rows = db_query(
        "SELECT n.siswa_user_id, n.mapel_id, n.nilai_akhir, n.predikat
         FROM nilai n
         WHERE n.kelas_id = :kid AND n.tahun_ajaran_id = :ta AND n.jenis_raport = :jenis
           $bc_bulan_cond",
        $bc_params
    );
    // Index: [siswa_user_id][mapel_id] = nilai_akhir
    $bc_nilai_idx = [];
    foreach ($bc_nilai_rows as $bv) {
        $bc_nilai_idx[(int)$bv['siswa_user_id']][(int)$bv['mapel_id']] = (float)$bv['nilai_akhir'];
    }

    // 3. Susun data per siswa
    foreach ($siswa_raport as $sr) {
        $uid = (int)$sr['id'];
        $avg = $sr['avg_nilai'] !== null ? (float)$sr['avg_nilai'] : null;
        $by_mapel = [];
        foreach ($bc_data['mapel_list'] as $bm) {
            $mid = $bm['id'];
            $by_mapel[$mid] = isset($bc_nilai_idx[$uid][$mid]) ? $bc_nilai_idx[$uid][$mid] : null;
        }
        $bc_data['siswa'][] = [
            'nama'     => $sr['nama_lengkap'],
            'avg'      => $avg,
            'by_mapel' => $by_mapel,
        ];
    }
}
// bc_siswa_data masih dipakai sebagai penjaga kondisi PHP endif
$bc_siswa_data = $bc_data['siswa'];
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Rapor Murid</h1>
            <p class="page-subtitle">Lihat dan cetak rapor murid</p>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" action="index.php" class="card mb-5">
        <div class="card-body">
            <input type="hidden" name="page" value="raport">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                <div>
                    <label class="form-label">Tahun Ajaran</label>
                    <select name="ta" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($ta_list as $ta): ?>
                        <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_id?'selected':'' ?>>
                            <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Kelas</label>
                    <select name="kelas" class="form-control">
                        <option value="">&mdash; Pilih Kelas &mdash;</option>
                        <?php foreach ($kelas_list as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $k['id']==$kelas_id?'selected':'' ?>>
                            <?= e($k['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Jenis Rapor</label>
                    <select name="jenis" class="form-control" onchange="this.form.submit()">
                        <option value="bulanan" <?= $jenis==='bulanan' ?'selected':'' ?>>Bulanan</option>
                        <option value="sts" <?= $jenis==='sts'     ?'selected':'' ?>>ASTS &mdash; Tengah Semester
                        </option>
                        <option value="semester" <?= $jenis==='semester'?'selected':'' ?>>Semester (SAS)</option>
                    </select>
                </div>
                <?php if (in_array($jenis, ['bulanan','sts'])): ?>
                <div>
                    <label class="form-label">Bulan</label>
                    <select name="bulan" class="form-control">
                        <option value="">&mdash; Pilih Bulan &mdash;</option>
                        <?php foreach (BULAN_ID as $bk => $bv): ?>
                        <option value="<?= $bk ?>" <?= $bk==$bulan?'selected':'' ?>><?= $bv ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-100" style="border-radius:30px">Tampilkan</button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($kelas_id && !empty($siswa_raport)): ?>
    <div class="card">
        <div class="card-header">
            <span>Daftar Murid &mdash; Peringkat Kelas</span>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="badge badge-orange"><?= ucfirst($jenis) ?></span>

                <!-- Toggle Publikasi &mdash; hanya admin / super_admin -->
                <?php if (is_admin_or_super()): ?>
                <div class="flex items-center gap-2" style="margin-left:4px">
                    <span class="text-sm font-medium text-gray-600">Terbitkan ke Murid:</span>
                    <label class="toggle-switch"
                        title="<?= $is_published ? 'Klik untuk mencabut publikasi' : 'Klik untuk menerbitkan rapor ini ke murid' ?>">
                        <input type="checkbox" id="togglePublikasi" <?= $is_published ? 'checked' : '' ?>
                            <?= (!$kelas_id || !$ta_id) ? 'disabled' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <span id="pubLabel"
                        class="text-sm font-semibold <?= $is_published ? 'text-green-600' : 'text-gray-400' ?>">
                        <?= $is_published ? 'Sudah Rilis' : 'Belum Rilis' ?>
                    </span>
                </div>
                <?php endif; ?>

                <?php if (!in_array($role, ['siswa']) && $kelas_id && !empty($siswa_raport)): ?>
                <button type="button" onclick="bukaBellCurve()" class="btn btn-sm btn-secondary"
                    style="border-radius:30px;border-color:#0891b2;color:#0891b2"
                    title="Lihat distribusi nilai rata-rata kelas dalam Bell Curve">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M22 20H2"/><path d="M12 4c-4 0-7 5-7 8s3 5 7 5 7-2 7-5-3-8-7-8z"/>
                    </svg>
                    Bell Curve
                </button>
                <?php endif; ?>
                <?php if (!in_array($role, ['siswa'])): ?>
                <?php if ($leger_boleh): ?>
                <?php
                    $leger_url = '/raport.leger?ta='.$ta_id.'&kelas='.$kelas_id.'&jenis='.$jenis.'&bulan='.$bulan;
                ?>
                <button type="button"
                    onclick="<?= $ada_nilai ? "window.open('".htmlspecialchars($leger_url)."','_blank')" : "document.getElementById('modalLegerBelumNilai').style.display='flex'" ?>"
                    class="btn btn-sm btn-secondary" title="Lihat Leger Rapor kelas ini" style="border-radius:30px">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M3 9h18M3 15h18M9 3v18" />
                    </svg>
                    Leger
                </button>
                <?php else: ?>
                <button type="button" onclick="document.getElementById('modalLegerDitolak').style.display='flex'"
                    class="btn btn-sm btn-secondary" title="Lihat Leger Rapor kelas ini" style="border-radius:30px">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M3 9h18M3 15h18M9 3v18" />
                    </svg>
                    Leger
                </button>
                <?php endif; ?>
                <?php if ($cetak_boleh): ?>
                <a href="/raport.print_kelas?ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
                    target="_blank" class="btn btn-sm btn-primary" title="Buka halaman cetak semua rapor kelas ini"
                    style="border-radius:30px">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="6 9 6 2 18 2 18 9" />
                        <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" />
                        <rect x="6" y="14" width="12" height="8" />
                    </svg>
                    Cetak Semua (<?= count($siswa_raport) ?> Murid)
                </a>
                <?php else: ?>
                <button type="button" onclick="document.getElementById('modalCetakDitolak').style.display='flex'"
                    class="btn btn-sm btn-primary" title="Cetak semua rapor kelas ini"
                    style="border-radius:30px;opacity:.75">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="6 9 6 2 18 2 18 9" />
                        <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" />
                        <rect x="6" y="14" width="12" height="8" />
                    </svg>
                    Cetak Semua (<?= count($siswa_raport) ?> Murid)
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status bar publikasi -->
        <?php if (is_admin_or_super()): ?>
        <div id="pubStatusBar" style="padding:8px 16px;font-size:.82rem;font-weight:600;
             background:<?= $is_published ? '#f0fdf4' : '#fff7ed' ?>;
             border-bottom:1px solid <?= $is_published ? '#bbf7d0' : '#fed7aa' ?>;
             color:<?= $is_published ? '#166534' : '#9a3412' ?>;
             display:flex;align-items:center;gap:6px">
            <?php if ($is_published): ?>
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M20 6L9 17l-5-5" />
            </svg>
            Rapor ini <strong>sudah diterbitkan</strong> &mdash; murid dapat melihat dan mencetak rapor mereka.
            <?php else: ?>
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            Rapor ini <strong>belum diterbitkan</strong> &mdash; murid tidak dapat melihat rapor mereka.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Peringkat</th>
                        <th>Nama Murid</th>
                        <th>NIS</th>
                        <th>Mapel Dinilai</th>
                        <th>Rata-rata</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($siswa_raport as $i => $s): ?>
                    <?php $pred = $s['avg_nilai'] ? get_predikat((float)$s['avg_nilai']) : '-'; ?>
                    <tr>
                        <td>
                            <?php if ($i === 0): ?>
                            <span class="badge badge-orange">&#127942; 1</span>
                            <?php elseif ($i === 1): ?>
                            <span class="badge badge-gray">&#129352; 2</span>
                            <?php elseif ($i === 2): ?>
                            <span class="badge badge-gray">&#129353; 3</span>
                            <?php else: ?>
                            <span class="font-bold text-gray-400"><?= $i+1 ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="font-semibold"><?= e($s['nama_lengkap']) ?></td>
                        <td class="text-gray-500"><?= e($s['nis']) ?></td>
                        <td class="text-center"><?= $s['jml_mapel'] ?></td>
                        <td class="font-bold text-orange-600"><?= number_format($s['avg_nilai'] ?? 0, 2) ?></td>
                        <td>
                            <div class="flex gap-1">
                                <a href="/raport.view?siswa=<?= $s['id'] ?>&ta=<?= $ta_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
                                    class="btn btn-sm btn-secondary" style="border-radius:30px">Lihat</a>
                                <a href="/raport.print?siswa=<?= $s['id'] ?>&ta=<?= $ta_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
                                    target="_blank" class="btn btn-sm btn-primary" style="border-radius:30px">Cetak</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($kelas_id): ?>
    <div class="card">
        <div class="card-body empty-state">
            <p>Belum ada data nilai untuk filter ini.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                <polyline points="14 2 14 8 20 8" />
            </svg>
            <p>Pilih kelas dan jenis rapor untuk menampilkan data.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$leger_boleh && $role !== 'siswa'): ?>
<?php
// Tentukan pesan modal sesuai kondisi
$kelas_dipilih_nama = '';
if ($kelas_id) {
    $kelas_nama_row = db_row('SELECT nama_kelas FROM kelas WHERE id = :id', [':id' => $kelas_id]);
    $kelas_dipilih_nama = $kelas_nama_row['nama_kelas'] ?? '';
}
if ($role === 'wali_kelas') {
    $modal_pesan = 'Anda bukan Wali Kelas dari kelas <strong>' . htmlspecialchars($kelas_dipilih_nama) . '</strong>.<br><br>Leger hanya dapat dibuka oleh Wali Kelas dari kelas yang bersangkutan, serta Admin dan Kepala Sekolah.';
} elseif ($role === 'guru') {
    $modal_pesan = 'Anda tidak mengajar di kelas <strong>' . htmlspecialchars($kelas_dipilih_nama) . '</strong>.<br><br>Silakan pilih kelas yang Anda ajar untuk membuka Leger Rapor.';
} else {
    $modal_pesan = 'Halaman <strong>Leger Rapor</strong> tidak dapat diakses untuk role Anda.';
}
?>
<!-- Modal peringatan leger -->
<div id="modalLegerDitolak" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);
            align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:14px;padding:36px 32px;max-width:440px;width:90%;
                text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.2);position:relative">
        <button onclick="document.getElementById('modalLegerDitolak').style.display='none'" style="position:absolute;top:12px;right:14px;background:none;border:none;
                       font-size:1.3rem;color:#9ca3af;cursor:pointer;line-height:1">&times;</button>
        <div style="width:60px;height:60px;background:#fff3e0;border-radius:50%;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
            <svg width="30" height="30" fill="none" stroke="#f97316" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="8" x2="12" y2="12" />
                <circle cx="12" cy="16" r=".6" fill="#f97316" />
            </svg>
        </div>
        <div style="font-size:1.05rem;font-weight:700;color:#1f2328;margin-bottom:8px">
            Akses Tidak Diizinkan
        </div>
        <div style="font-size:.87rem;color:#57606a;line-height:1.65;margin-bottom:22px">
            <?= $modal_pesan ?>
        </div>
        <button onclick="document.getElementById('modalLegerDitolak').style.display='none'" style="background:#f97316;color:#fff;border:none;border-radius:30px;
                       padding:10px 28px;font-size:.88rem;font-weight:700;cursor:pointer">
            Mengerti
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Modal peringatan cetak semua � tampil untuk guru yang bukan walas kelas ini -->
<?php if (!$cetak_boleh && $role !== 'siswa' && $kelas_id): ?>
<?php
$kelas_cetak_nama = '';
if ($kelas_id) {
    $kelas_cetak_row  = db_row('SELECT k.nama_kelas, u.nama_lengkap as walas_nama
                                 FROM kelas k
                                 LEFT JOIN users u ON u.id = k.wali_kelas_id
                                 WHERE k.id = :id', [':id' => $kelas_id]);
    $kelas_cetak_nama = $kelas_cetak_row['nama_kelas'] ?? '';
    $walas_nama_cetak = $kelas_cetak_row['walas_nama'] ?? '';
}
?>
<div id="modalCetakDitolak" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);
            align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:14px;padding:36px 32px;max-width:460px;width:90%;
                text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.2);position:relative">
        <button onclick="document.getElementById('modalCetakDitolak').style.display='none'" style="position:absolute;top:12px;right:14px;background:none;border:none;
                       font-size:1.3rem;color:#9ca3af;cursor:pointer;line-height:1">&times;</button>
        <!-- Ikon -->
        <div style="width:64px;height:64px;background:#fee2e2;border-radius:50%;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
            <svg width="32" height="32" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
        </div>
        <!-- Judul -->
        <div style="font-size:1.08rem;font-weight:700;color:#1f2328;margin-bottom:8px">
            Akses Tidak Diizinkan
        </div>
        <!-- Pesan -->
        <div style="font-size:.87rem;color:#57606a;line-height:1.7;margin-bottom:24px">
            <?php if ($guru_status === 'wali_kelas'): ?>
            Maaf, Anda tidak berhak mencetak semua rapor kelas
            <strong><?= htmlspecialchars($kelas_cetak_nama) ?></strong>.<br><br>
            Cetak semua rapor hanya dapat dilakukan oleh <strong>Wali Kelas</strong> dari kelas yang
            bersangkutan<?= $walas_nama_cetak ? ', yaitu <strong> Ust. '.htmlspecialchars($walas_nama_cetak).'</strong>' : '' ?>.
            <?php else: ?>
            Maaf, Anda tidak berhak mencetak semua rapor kelas
            <strong><?= htmlspecialchars($kelas_cetak_nama) ?></strong>.<br><br>
            Fitur cetak semua rapor hanya dapat digunakan oleh <strong>Wali Kelas</strong> terkait, serta
            <strong>Admin</strong> dan <strong>Kepala Sekolah</strong>.
            <?php endif; ?>
        </div>
        <!-- Aksi -->
        <button onclick="document.getElementById('modalCetakDitolak').style.display='none'" style="background:#ef4444;color:#fff;border:none;border-radius:30px;
                       padding:10px 28px;font-size:.88rem;font-weight:700;cursor:pointer">
            Mengerti
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Modal: belum ada nilai � tampil untuk semua role yang boleh akses leger -->
<?php if ($leger_boleh && !$ada_nilai && $kelas_id): ?>
<?php
    $jenis_label = ['bulanan'=>'Bulanan','sts'=>'ASTS (Tengah Semester)','semester'=>'Semester (SAS)'];
    $kelas_nama_leger = db_row('SELECT nama_kelas FROM kelas WHERE id = :id', [':id' => $kelas_id])['nama_kelas'] ?? '';
    $input_nilai_url  = '/nilai';
?>
<div id="modalLegerBelumNilai" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);
            align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:14px;padding:36px 32px;max-width:460px;width:90%;
                text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.2);position:relative">
        <button onclick="document.getElementById('modalLegerBelumNilai').style.display='none'" style="position:absolute;top:12px;right:14px;background:none;border:none;
                       font-size:1.3rem;color:#9ca3af;cursor:pointer;line-height:1">&times;</button>
        <!-- Ikon -->
        <div style="width:64px;height:64px;background:#fef3c7;border-radius:50%;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
            <svg width="32" height="32" fill="none" stroke="#d97706" stroke-width="2" viewBox="0 0 24 24">
                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
        </div>
        <!-- Judul -->
        <div style="font-size:1.08rem;font-weight:700;color:#1f2328;margin-bottom:8px">
            Nilai Belum Diinput
        </div>
        <!-- Pesan -->
        <div style="font-size:.87rem;color:#57606a;line-height:1.7;margin-bottom:24px">
            Leger tidak dapat ditampilkan karena <strong>belum ada nilai</strong> yang diinput
            untuk kelas <strong><?= htmlspecialchars($kelas_nama_leger) ?></strong>
            jenis rapor <strong><?= htmlspecialchars($jenis_label[$jenis] ?? $jenis) ?></strong>.<br><br>
            Silakan input nilai terlebih dahulu melalui menu <strong>Input Nilai</strong>,
            kemudian buka kembali halaman Leger.
        </div>
        <!-- Aksi -->
        <div style="display:flex;gap:10px;justify-content:center">
            <button onclick="document.getElementById('modalLegerBelumNilai').style.display='none'" style="background:#f3f4f6;color:#374151;border:none;border-radius:30px;
                           padding:10px 22px;font-size:.88rem;font-weight:600;cursor:pointer">
                Tutup
            </button>
            <a href="<?= $input_nilai_url ?>"
                style="background:#f97316;color:#fff;border:none;border-radius:30px;
                      padding:10px 22px;font-size:.88rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" />
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" />
                </svg>
                Input Nilai Sekarang
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (is_admin_or_super() && $kelas_id): ?>
<script>
(function() {
    const toggle = document.getElementById('togglePublikasi');
    const label = document.getElementById('pubLabel');
    const statusBar = document.getElementById('pubStatusBar');
    if (!toggle) return;

    toggle.addEventListener('change', function() {
        const newVal = this.checked ? 1 : 0;
        toggle.disabled = true;

        fetch('/raport', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'toggle_publikasi',
                    _token: '<?= csrf_token() ?>',
                    kelas_id: '<?= $kelas_id ?>',
                    ta_id: '<?= $ta_id ?>',
                    jenis: '<?= e($jenis) ?>',
                    bulan: '<?= $bulan ?>',
                    is_published: newVal
                })
            })
            .then(r => r.json())
            .then(data => {
                toggle.disabled = false;
                if (!data.ok) {
                    alert('Gagal: ' + data.msg);
                    toggle.checked = !toggle.checked;
                    return;
                }

                if (newVal === 1) {
                    label.textContent = 'Sudah Rilis';
                    label.className = 'text-sm font-semibold text-green-600';
                    if (statusBar) {
                        statusBar.style.background = '#f0fdf4';
                        statusBar.style.borderColor = '#bbf7d0';
                        statusBar.style.color = '#166534';
                        statusBar.innerHTML =
                            '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg> Rapor ini <strong>sudah diterbitkan</strong> &mdash; murid dapat melihat dan mencetak rapor mereka.';
                    }
                } else {
                    label.textContent = 'Belum Rilis';
                    label.className = 'text-sm font-semibold text-gray-400';
                    if (statusBar) {
                        statusBar.style.background = '#fff7ed';
                        statusBar.style.borderColor = '#fed7aa';
                        statusBar.style.color = '#9a3412';
                        statusBar.innerHTML =
                            '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Rapor ini <strong>belum diterbitkan</strong> &mdash; murid tidak dapat melihat rapor mereka.';
                    }
                }
            })
            .catch(() => {
                toggle.disabled = false;
                toggle.checked = !toggle.checked;
                alert('Terjadi kesalahan koneksi.');
            });
    });
})();
</script>
<?php endif; ?>

<!-- ══ Modal Bell Curve ═══════════════════════════════════════════════════ -->
<?php if (!in_array($role, ['siswa']) && $kelas_id && !empty($bc_siswa_data)): ?>
<div id="modalBellCurve" style="display:none;position:fixed;inset:0;z-index:1060;
    background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:920px;
        max-height:92vh;display:flex;flex-direction:column;
        box-shadow:0 24px 64px rgba(0,0,0,.25)">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;
            padding:16px 20px;border-bottom:1px solid #e5e7eb;flex-shrink:0">
            <div>
                <div style="font-weight:700;font-size:1rem;color:#1f2328;
                    display:flex;align-items:center;gap:8px">
                    <svg width="17" height="17" fill="none" stroke="#0891b2" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M22 20H2"/><path d="M12 4c-4 0-7 5-7 8s3 5 7 5 7-2 7-5-3-8-7-8z"/>
                    </svg>
                    Distribusi Nilai Rata-rata — Bell Curve
                </div>
                <div style="font-size:.78rem;color:#57606a;margin-top:3px">
                    <?= e($bc_kelas_nama) ?> &mdash; Rata-rata semua mata pelajaran
                </div>
            </div>
            <button onclick="tutupBellCurve()" style="background:none;border:none;cursor:pointer;
                color:#9ca3af;font-size:1.6rem;line-height:1;padding:4px 8px">&times;</button>
        </div>

        <!-- Statistik ringkas -->
        <div id="bcStats" style="display:flex;gap:8px;padding:10px 20px;flex-wrap:wrap;
            background:#f0f9ff;border-bottom:1px solid #e0f2fe;flex-shrink:0"></div>

        <!-- Filter mapel -->
        <div style="padding:8px 20px;border-bottom:1px solid #f3f4f6;
            display:flex;align-items:center;gap:10px;flex-shrink:0;background:#fafafa">
            <span style="font-size:.75rem;color:#57606a;white-space:nowrap;font-weight:600">Tampilkan:</span>
            <select id="bcMapelFilter" onchange="renderBellCurve()"
                style="font-size:.75rem;padding:5px 10px;border:1px solid #e5e7eb;
                border-radius:8px;background:#fff;color:#1f2328;cursor:pointer;flex:1;max-width:340px">
                <option value="">— Semua Mata Pelajaran (rata-rata) —</option>
                <?php foreach ($bc_data['mapel_list'] as $bm): ?>
                <option value="<?= $bm['id'] ?>"><?= e($bm['nama']) ?></option>
                <?php endforeach; ?>
            </select>
            <span id="bcJumlah" style="font-size:.72rem;color:#9ca3af;white-space:nowrap"></span>
        </div>

        <!-- Konten utama: nama murid kiri + kurva kanan -->
        <div style="display:flex;flex:1;overflow:hidden;min-height:0">

            <!-- Kolom kiri: daftar nama murid terurut -->
            <div style="width:188px;flex-shrink:0;border-right:1px solid #e5e7eb;overflow-y:auto">
                <div style="padding:5px 8px;background:#f7f8fa;border-bottom:1px solid #e5e7eb;
                    position:sticky;top:0;z-index:2">
                    <span style="font-size:.68rem;font-weight:700;color:#57606a;text-transform:uppercase;
                        letter-spacing:.04em">Rank &nbsp; Nama Murid</span>
                </div>
                <div id="bcSiswaList"></div>
            </div>

            <!-- Kolom kanan: kurva -->
            <div style="flex:1;overflow-x:auto;overflow-y:auto;padding:12px 14px 6px">
                <svg id="bcSvg" width="100%" viewBox="0 0 720 210"
                    style="display:block;min-width:360px" xmlns="http://www.w3.org/2000/svg"></svg>
            </div>
        </div>

        <!-- Footer -->
        <div style="padding:10px 20px;border-top:1px solid #e5e7eb;display:flex;
            justify-content:space-between;align-items:center;flex-shrink:0">
            <span style="font-size:.7rem;color:#9ca3af">
                * Hover titik pada kurva untuk melihat nama murid
            </span>
            <div style="display:flex;gap:8px">
                <button onclick="bukaPanduanBellCurve()" class="btn btn-sm btn-secondary"
                    style="border-radius:30px;border-color:#7c5cd8;color:#7c5cd8;font-size:.78rem"
                    title="Cara membaca Bell Curve">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
                    </svg>
                    Cara Membaca
                </button>
                <button onclick="tutupBellCurve()" class="btn btn-secondary" style="border-radius:30px">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ Modal Panduan Bell Curve ══════════════════════════════════════════ -->
<div id="modalPanduanBC" style="display:none;position:fixed;inset:0;z-index:1070;
    background:rgba(0,0,0,.55);align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:680px;
        max-height:92vh;display:flex;flex-direction:column;
        box-shadow:0 24px 64px rgba(0,0,0,.3)">

        <!-- Header panduan -->
        <div style="display:flex;align-items:center;justify-content:space-between;
            padding:16px 20px;border-bottom:1px solid #e5e7eb;flex-shrink:0">
            <div style="display:flex;align-items:center;gap:8px;font-weight:700;
                font-size:.95rem;color:#1f2328">
                <svg width="17" height="17" fill="none" stroke="#7c5cd8" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
                </svg>
                Cara Membaca Bell Curve
            </div>
            <button onclick="tutupPanduanBellCurve()" style="background:none;border:none;cursor:pointer;
                color:#9ca3af;font-size:1.6rem;line-height:1;padding:4px 8px">&times;</button>
        </div>

        <!-- Isi panduan -->
        <div style="overflow-y:auto;padding:20px;font-size:.82rem;line-height:1.7;color:#374151">

            <!-- Intro -->
            <p style="margin:0 0 14px">
                Bell Curve (kurva lonceng) adalah cara memvisualisasikan
                <strong>bagaimana nilai murid tersebar</strong> di dalam satu kelas.
                Disebut "lonceng" karena bentuknya seperti lonceng terbalik —
                paling tinggi di tengah, landai di kedua sisi.
            </p>

            <!-- μ -->
            <div style="background:#f0f9ff;border-left:4px solid #0891b2;border-radius:0 8px 8px 0;
                padding:12px 14px;margin-bottom:12px">
                <div style="font-weight:700;color:#0891b2;margin-bottom:4px">
                    μ (mu) — Rata-rata Kelas
                </div>
                <p style="margin:0">
                    Ini adalah nilai <strong>rata-rata seluruh murid di kelas</strong>.
                    Di kurva, μ adalah <strong>titik puncak</strong> (garis biru putus-putus vertikal).
                    Puncak kurva selalu tepat di atas μ karena di situlah nilai yang
                    paling banyak dimiliki murid.
                    <br><br>
                    <em>Contoh: μ = 73.9 artinya rata-rata nilai kelas adalah 73,9.
                    Kebanyakan murid nilainya berkumpul di sekitar angka itu.</em>
                </p>
            </div>

            <!-- σ -->
            <div style="background:#faf5ff;border-left:4px solid #7c5cd8;border-radius:0 8px 8px 0;
                padding:12px 14px;margin-bottom:12px">
                <div style="font-weight:700;color:#7c5cd8;margin-bottom:4px">
                    σ (sigma) — Standar Deviasi / Tingkat Penyebaran
                </div>
                <p style="margin:0 0 8px">
                    σ menunjukkan <strong>seberapa beragam nilai murid</strong> dibandingkan rata-rata.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.78rem">
                    <div style="background:#fff;border:1px solid #e9d5ff;border-radius:8px;padding:10px">
                        <div style="font-weight:700;color:#7c5cd8;margin-bottom:4px">σ Kecil (misal 2–4)</div>
                        <div>Kurva <strong>tinggi &amp; sempit</strong>.<br>
                        Artinya hampir semua murid nilainya <strong>mirip satu sama lain</strong>
                        — tidak ada yang jauh berbeda.</div>
                    </div>
                    <div style="background:#fff;border:1px solid #e9d5ff;border-radius:8px;padding:10px">
                        <div style="font-weight:700;color:#7c5cd8;margin-bottom:4px">σ Besar (misal 10–15)</div>
                        <div>Kurva <strong>pendek &amp; melebar</strong>.<br>
                        Artinya nilai sangat beragam — ada murid yang <strong>sangat tinggi</strong>
                        dan ada yang <strong>sangat rendah</strong>.</div>
                    </div>
                </div>
            </div>

            <!-- Titik murid -->
            <div style="background:#f0fdf4;border-left:4px solid #16a34a;border-radius:0 8px 8px 0;
                padding:12px 14px;margin-bottom:12px">
                <div style="font-weight:700;color:#16a34a;margin-bottom:4px">
                    Titik-titik Berwarna = Masing-masing Siswa
                </div>
                <p style="margin:0">
                    Setiap titik mewakili <strong>satu murid</strong>, diletakkan di atas kurva
                    sesuai dengan nilainya. Warna titik sesuai predikat:
                </p>
                <div style="display:flex;gap:12px;margin-top:8px;flex-wrap:wrap;font-size:.78rem">
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:50%;
                        background:#16a34a;vertical-align:middle;margin-right:4px"></span>Hijau = Predikat A</span>
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:50%;
                        background:#2563eb;vertical-align:middle;margin-right:4px"></span>Biru = Predikat B</span>
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:50%;
                        background:#ca8a04;vertical-align:middle;margin-right:4px"></span>Kuning = Predikat C</span>
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:50%;
                        background:#dc2626;vertical-align:middle;margin-right:4px"></span>Merah = Predikat D</span>
                </div>
                <p style="margin:8px 0 0;font-size:.78rem;color:#57606a">
                    💡 <em>Arahkan kursor ke titik untuk melihat nama murid beserta nilainya.</em>
                </p>
            </div>

            <!-- Zona warna -->
            <div style="background:#fff7ed;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;
                padding:12px 14px;margin-bottom:12px">
                <div style="font-weight:700;color:#b45309;margin-bottom:4px">
                    Zona Warna di Bawah Kurva
                </div>
                <p style="margin:0 0 6px">
                    Area berwarna di bawah kurva menunjukkan <strong>batas predikat</strong>
                    sesuai pengaturan sekolah:
                </p>
                <div style="font-size:.78rem;display:flex;flex-direction:column;gap:4px">
                    <div style="display:flex;align-items:center;gap:8px">
                        <span style="display:inline-block;width:28px;height:12px;border-radius:3px;
                            background:#fca5a5;flex-shrink:0"></span>
                        <span><strong>Merah muda</strong> = Zona D — nilai di bawah batas C</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span style="display:inline-block;width:28px;height:12px;border-radius:3px;
                            background:#fde68a;flex-shrink:0"></span>
                        <span><strong>Kuning muda</strong> = Zona C — antara batas C dan B</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span style="display:inline-block;width:28px;height:12px;border-radius:3px;
                            background:#93c5fd;flex-shrink:0"></span>
                        <span><strong>Biru muda</strong> = Zona B — antara batas B dan A</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span style="display:inline-block;width:28px;height:12px;border-radius:3px;
                            background:#6ee7b7;flex-shrink:0"></span>
                        <span><strong>Hijau muda</strong> = Zona A — nilai di atas batas A</span>
                    </div>
                </div>
            </div>

            <!-- Cara baca praktis -->
            <div style="background:#f7f8fa;border-radius:8px;padding:12px 14px;margin-bottom:4px">
                <div style="font-weight:700;color:#374151;margin-bottom:6px">
                    🎯 Cara Membaca Praktis untuk Guru
                </div>
                <div style="font-size:.78rem;display:flex;flex-direction:column;gap:6px">
                    <div style="display:flex;gap:8px">
                        <span style="flex-shrink:0;font-weight:700;color:#0891b2">1.</span>
                        <span><strong>Lihat posisi puncak (μ).</strong> Apakah rata-rata kelas sudah
                        masuk zona predikat yang diharapkan?</span>
                    </div>
                    <div style="display:flex;gap:8px">
                        <span style="flex-shrink:0;font-weight:700;color:#0891b2">2.</span>
                        <span><strong>Lihat lebar kurva (σ).</strong> Kurva sempit = murid merata,
                        kurva lebar = ada kesenjangan besar antar murid.</span>
                    </div>
                    <div style="display:flex;gap:8px">
                        <span style="flex-shrink:0;font-weight:700;color:#0891b2">3.</span>
                        <span><strong>Cari titik yang "sendiri" jauh di ujung kiri.</strong>
                        Itu murid yang perlu perhatian khusus — nilainya jauh di bawah teman-temannya.</span>
                    </div>
                    <div style="display:flex;gap:8px">
                        <span style="flex-shrink:0;font-weight:700;color:#0891b2">4.</span>
                        <span><strong>Gunakan filter Mata Pelajaran</strong> untuk membandingkan
                        penyebaran nilai antar mapel — mapel mana yang perlu perbaikan pengajaran.</span>
                    </div>
                    <div style="display:flex;gap:8px">
                        <span style="flex-shrink:0;font-weight:700;color:#0891b2">5.</span>
                        <span><strong>Jika kurva miring ke kiri</strong> (ekor panjang ke kiri),
                        banyak murid tertinggal. <strong>Miring ke kanan</strong> artinya mayoritas
                        sudah baik, hanya sedikit yang perlu dikejar.</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- Footer panduan -->
        <div style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;
            justify-content:flex-end;flex-shrink:0">
            <button onclick="tutupPanduanBellCurve()" class="btn btn-secondary" style="border-radius:30px">Tutup</button>
        </div>
    </div>
</div>

<script>
/* ── Data Bell Curve dari PHP ─────────────────────────────────────── */
const BC_DATA  = <?= json_encode($bc_data) ?>;
const BC_KRIT  = { A: <?= $bc_krit_a ?>, B: <?= $bc_krit_b ?>, C: <?= $bc_krit_c ?> };

/* ── Overlay click tutup ──────────────────────────────────────────── */
document.addEventListener('click', function(e) {
    const m = document.getElementById('modalBellCurve');
    if (m && e.target === m) tutupBellCurve();
    const mp = document.getElementById('modalPanduanBC');
    if (mp && e.target === mp) tutupPanduanBellCurve();
});

function bukaBellCurve() {
    const modal = document.getElementById('modalBellCurve');
    if (!modal) return;
    modal.style.display = 'flex';
    document.getElementById('bcMapelFilter').value = '';
    renderBellCurve();
}
function tutupBellCurve() {
    const modal = document.getElementById('modalBellCurve');
    if (modal) modal.style.display = 'none';
}
function bukaPanduanBellCurve() {
    const m = document.getElementById('modalPanduanBC');
    if (m) m.style.display = 'flex';
}
function tutupPanduanBellCurve() {
    const m = document.getElementById('modalPanduanBC');
    if (m) m.style.display = 'none';
}

/* Hitung mean, stdev, min, max */
function bcStat(values) {
    const n = values.length;
    if (n === 0) return { mean:0, stdev:0, min:0, max:0, n:0 };
    const mean  = values.reduce((a,b) => a+b, 0) / n;
    const stdev = Math.sqrt(values.reduce((a,v) => a+(v-mean)**2, 0) / n);
    return { mean, stdev, min:Math.min(...values), max:Math.max(...values), n };
}

/* PDF normal (Gauss) */
function bcPdf(x, mean, stdev) {
    if (stdev === 0) return x === mean ? 1 : 0;
    return Math.exp(-0.5*((x-mean)/stdev)**2) / (stdev*Math.sqrt(2*Math.PI));
}

/* Ambil nilai per-siswa berdasarkan filter mapel ('' = rata semua mapel) */
function bcKumpulkan(mapelId) {
    return BC_DATA.siswa.map(s => {
        let na;
        if (mapelId === '') {
            na = s.avg;                        // rata-rata semua mapel
        } else {
            const v = s.by_mapel[mapelId];
            na = (v !== undefined && v !== null) ? v : null;
        }
        // hitung predikat
        let pred = '-';
        if (na !== null) {
            if      (na >= BC_KRIT.A) pred = 'A';
            else if (na >= BC_KRIT.B) pred = 'B';
            else if (na >= BC_KRIT.C) pred = 'C';
            else                      pred = 'D';
        }
        return { nama: s.nama, na, pred };
    });
}

function renderBellCurve() {
    const mapelId = document.getElementById('bcMapelFilter')?.value ?? '';
    const semua   = bcKumpulkan(mapelId);
    const diisi   = semua.filter(s => s.na !== null);
    const values  = diisi.map(s => s.na);
    const stat    = bcStat(values);

    const PDC = { A:'#16a34a', B:'#2563eb', C:'#ca8a04', D:'#dc2626', '-':'#9ca3af' };
    const PC  = { A:'#16a34a', B:'#2563eb', C:'#ca8a04', D:'#dc2626' };
    const cp  = { A:0, B:0, C:0, D:0 };
    diisi.forEach(s => { if (cp[s.pred] !== undefined) cp[s.pred]++; });

    /* ── 1. Statistik ─────────────────────────────────────────── */
    const statsEl = document.getElementById('bcStats');
    const jmlEl   = document.getElementById('bcJumlah');
    if (statsEl) {
        const box = (lbl, val, col, sub='') =>
            `<div style="background:#fff;border:1px solid #bae6fd;border-radius:7px;
                padding:6px 12px;text-align:center;min-width:80px">
                <div style="font-size:1rem;font-weight:700;color:${col}">${val}</div>
                <div style="font-size:.66rem;color:#57606a;margin-top:1px">${lbl}</div>
                ${sub?`<div style="font-size:.62rem;color:#9ca3af">${sub}</div>`:''}
            </div>`;
        statsEl.innerHTML =
            box('Rata-rata (μ)', stat.n?stat.mean.toFixed(2):'—', '#0891b2') +
            box('Std. Dev. (σ)',  stat.n?stat.stdev.toFixed(2):'—', '#7c5cd8') +
            box('Tertinggi', stat.n?stat.max.toFixed(2):'—', '#16a34a') +
            box('Terendah',  stat.n?stat.min.toFixed(2):'—', '#dc2626') +
            box('Murid', stat.n, '#374151', semua.length+' total') +
            ['A','B','C','D'].map(p => box('Pred. '+p, cp[p], PC[p])).join('');
    }
    if (jmlEl) jmlEl.textContent = diisi.length + ' data';

    /* ── 2. List nama siswa di kiri ───────────────────────────── */
    const listEl = document.getElementById('bcSiswaList');
    if (listEl) {
        const sorted = [...semua].map((s,i)=>({...s,_i:i}))
                                 .sort((a,b)=>{ if(a.na===null&&b.na===null)return 0; if(a.na===null)return 1; if(b.na===null)return -1; return b.na-a.na; });
        listEl.innerHTML = sorted.map((s, rank) => {
            const isK  = s.na === null;
            const pc   = PDC[s.pred] ?? '#9ca3af';
            const naLbl = isK ? '<span style="color:#d1d5db">—</span>'
                              : `<span style="color:${pc};font-weight:700">${s.na.toFixed(1)}</span>`;
            const bg   = rank % 2 === 0 ? '#fff' : '#f9fafb';
            return `<div style="display:flex;align-items:center;gap:5px;padding:4px 8px;
                        border-bottom:1px solid #f3f4f6;background:${bg}">
                        <span style="font-size:.62rem;color:#9ca3af;width:16px;text-align:right;flex-shrink:0">${isK?'—':rank+1}</span>
                        <span style="font-size:.7rem;color:#374151;flex:1;overflow:hidden;white-space:nowrap;
                            text-overflow:ellipsis" title="${s.nama}">${s.nama}</span>
                        <span style="font-size:.68rem;flex-shrink:0">${naLbl}</span>
                    </div>`;
        }).join('');
    }

    /* ── 3. SVG Bell Curve ────────────────────────────────────── */
    const svg = document.getElementById('bcSvg');
    if (!svg) return;

    const W=720, H=210, padL=42, padR=16, padT=20, padB=34;
    const plotW = W-padL-padR, plotH = H-padT-padB;

    const xMin = stat.n ? Math.max(0,   Math.floor(Math.min(stat.min-5, stat.mean-3.5*stat.stdev))) : 0;
    const xMax = stat.n ? Math.min(100, Math.ceil( Math.max(stat.max+5, stat.mean+3.5*stat.stdev))) : 100;
    const xRange = xMax - xMin || 1;

    const steps=300, pdfPts=[];
    let pdfMax=0;
    for (let i=0; i<=steps; i++) {
        const x = xMin+(i/steps)*xRange;
        const y = bcPdf(x, stat.mean, stat.stdev||1);
        pdfPts.push({x,y});
        if(y>pdfMax) pdfMax=y;
    }
    if(pdfMax===0) pdfMax=1;

    const toX = x => padL+((x-xMin)/xRange)*plotW;
    const toY = y => padT+plotH-(y/pdfMax)*plotH;

    const zones=[
        {from:0,         to:BC_KRIT.C, color:'#fca5a5'},
        {from:BC_KRIT.C, to:BC_KRIT.B, color:'#fde68a'},
        {from:BC_KRIT.B, to:BC_KRIT.A, color:'#93c5fd'},
        {from:BC_KRIT.A, to:100,       color:'#6ee7b7'},
    ];
    const curvePath = pdfPts.map((p,i)=>(i===0?'M':'L')+toX(p.x).toFixed(2)+','+toY(p.y).toFixed(2)).join(' ');

    function zonePath(from,to) {
        const pts=pdfPts.filter(p=>p.x>=from&&p.x<=to);
        if(pts.length<2) return '';
        const by=(padT+plotH).toFixed(2);
        return `M${toX(pts[0].x).toFixed(2)},${by} L`+
               pts.map(p=>toX(p.x).toFixed(2)+','+toY(p.y).toFixed(2)).join(' L')+
               ` L${toX(pts[pts.length-1].x).toFixed(2)},${by} Z`;
    }

    // Titik per siswa — jitter vertikal jika nilai sama persis
    const valCount={};
    const dots = diisi.map(s=>{
        const key=s.na.toFixed(2);
        valCount[key]=(valCount[key]||0)+1;
        const jitter=(valCount[key]-1)*7; // geser ke atas 7px per siswa yang sama
        const cx=toX(s.na).toFixed(2);
        const cy=(toY(bcPdf(s.na,stat.mean,stat.stdev||1))-jitter).toFixed(2);
        const col=PDC[s.pred]??'#9ca3af';
        return `<circle cx="${cx}" cy="${cy}" r="4.5" fill="${col}"
                    stroke="#fff" stroke-width="1.5" opacity=".88" style="cursor:pointer">
                    <title>${s.nama}\n${s.na.toFixed(2)} · ${s.pred}</title></circle>`;
    }).join('');

    const baseY=(padT+plotH).toFixed(2);
    const meanX=toX(stat.mean).toFixed(2);
    const ticks=[...new Set([
        xMin, BC_KRIT.C, BC_KRIT.B, BC_KRIT.A, xMax,
        Math.round(stat.mean-stat.stdev), Math.round(stat.mean), Math.round(stat.mean+stat.stdev)
    ])].filter(v=>v>=xMin&&v<=xMax).sort((a,b)=>a-b);

    const xLabels=ticks.map(v=>{
        const sx=toX(v).toFixed(2), isk=[BC_KRIT.C,BC_KRIT.B,BC_KRIT.A].includes(v);
        return `<line x1="${sx}" y1="${baseY}" x2="${sx}" y2="${(padT+plotH+4).toFixed(2)}"
                    stroke="${isk?'#94a3b8':'#d1d5db'}" stroke-width="1"/>
                <text x="${sx}" y="${(padT+plotH+16).toFixed(2)}" text-anchor="middle"
                    font-size="10" fill="${isk?'#475569':'#9ca3af'}">${v}</text>`;
    }).join('');

    svg.innerHTML = `
        <line x1="${padL}" y1="${padT}" x2="${padL+plotW}" y2="${padT}" stroke="#f1f5f9" stroke-width="1"/>
        <line x1="${padL}" y1="${padT+plotH*.5}" x2="${padL+plotW}" y2="${padT+plotH*.5}" stroke="#f1f5f9" stroke-width="1"/>
        ${zones.map(z=>{const p=zonePath(Math.max(z.from,xMin),Math.min(z.to,xMax));return p?`<path d="${p}" fill="${z.color}" opacity=".5"/>`:'';}).join('')}
        <path d="${curvePath} L${toX(xMax).toFixed(2)},${baseY} L${padL},${baseY} Z" fill="#0891b2" opacity=".07"/>
        <path d="${curvePath}" fill="none" stroke="#0891b2" stroke-width="2.5" stroke-linejoin="round"/>
        <line x1="${meanX}" y1="${padT}" x2="${meanX}" y2="${baseY}"
            stroke="#0891b2" stroke-width="1.5" stroke-dasharray="5,3" opacity=".8"/>
        <text x="${meanX}" y="${(padT-5).toFixed(2)}" text-anchor="middle"
            font-size="10" fill="#0891b2" font-weight="600">μ=${stat.n?stat.mean.toFixed(1):'—'}</text>
        <line x1="${padL}" y1="${baseY}" x2="${padL+plotW}" y2="${baseY}" stroke="#94a3b8" stroke-width="1.5"/>
        ${[{v:BC_KRIT.C,l:'C/D'},{v:BC_KRIT.B,l:'B/C'},{v:BC_KRIT.A,l:'A/B'}].map(t=>{
            if(t.v<xMin||t.v>xMax) return '';
            const tx=toX(t.v).toFixed(2);
            return `<line x1="${tx}" y1="${padT}" x2="${tx}" y2="${baseY}"
                        stroke="#94a3b8" stroke-width="1" stroke-dasharray="3,3" opacity=".7"/>
                    <text x="${tx}" y="${(padT+9).toFixed(2)}" text-anchor="middle"
                        font-size="9" fill="#94a3b8">${t.l}</text>`;
        }).join('')}
        ${dots}
        ${xLabels}
        <text x="${padL-3}" y="${padT}" text-anchor="end" font-size="9" fill="#9ca3af">PDF</text>`;
}
</script>
<?php endif; ?>
