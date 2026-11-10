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

// ── AJAX: toggle publikasi raport (hanya admin / super_admin) ──
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

// ── SISWA: hanya raport miliknya yang sudah dipublikasi ────────
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
            <h1 class="page-title">Raport Saya</h1>
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
        <span>Ada raport yang belum dirilis. Silakan tunggu pengumuman dari sekolah.</span>
    </div>
    <?php endif; ?>

    <!-- Daftar Raport Tersedia -->
    <?php if (empty($raport_list)): ?>
    <div class="card">
        <div class="card-body empty-state">
            <?php if ($ada_belum_rilis): ?>
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            <p style="font-weight:600;font-size:1.1rem;margin-bottom:4px">Raport Belum Dirilis</p>
            <p style="color:#6b7280">Raport Anda sedang dalam proses penerbitan oleh sekolah.<br>Silakan cek kembali
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
          $rjLabel = $rj==='bulanan' ? 'Bulanan' : ($rj==='sts' ? 'STS' : 'Semester');
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
                    <div>
                        <div class="text-xs text-gray-400">Predikat</div>
                        <div class="font-bold text-lg <?= $pred_color ?>"><?= $pred_avg ?></div>
                    </div>
                    <div class="ml-auto">
                        <div class="text-xs text-gray-400">Mapel</div>
                        <div class="font-semibold text-gray-700"><?= $r['jml_mapel'] ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="flex gap-2 mt-3">
                    <a href="index.php?page=raport.view&siswa=<?= $user['id'] ?>&ta=<?= $r['tahun_ajaran_id'] ?>&jenis=<?= $r['jenis_raport'] ?>&bulan=<?= $r['bulan']??0 ?>"
                        class="btn btn-secondary btn-sm flex-1 justify-center" style="border-radius: 30px;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        Lihat
                    </a>
                    <a href="index.php?page=raport.print&siswa=<?= $user['id'] ?>&ta=<?= $r['tahun_ajaran_id'] ?>&jenis=<?= $r['jenis_raport'] ?>&bulan=<?= $r['bulan']??0 ?>"
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

// ── Untuk Admin, Guru, Wali Kelas, Kepsek ────────────────────
$guru_detail = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user['id']]);
$guru_status = $guru_detail['status'] ?? 'guru';

if (in_array($role, ['admin','super_admin','kepala_sekolah'])) {
    $kelas_list = db_query(
        'SELECT * FROM kelas WHERE tahun_ajaran_id = :ta ORDER BY nama_kelas',
        [':ta' => $ta_id]
    );
} elseif ($guru_status === 'wali_kelas') {
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         LEFT JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id AND gmk.guru_user_id = :uid
         WHERE k.tahun_ajaran_id = :ta
           AND (k.wali_kelas_id = :uid2 OR gmk.id IS NOT NULL)
         ORDER BY k.nama_kelas',
        [':uid' => $user['id'], ':uid2' => $user['id'], ':ta' => $ta_id]
    );
} else {
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id
         WHERE gmk.guru_user_id = :uid AND gmk.tahun_ajaran_id = :ta
         ORDER BY k.nama_kelas',
        [':uid' => $user['id'], ':ta' => $ta_id]
    );
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
// - super_admin, admin, kepala_sekolah: selalu boleh
// - wali_kelas: hanya jika dia wali_kelas dari kelas yang dipilih
// - guru biasa: tidak boleh
if (in_array($role, ['super_admin','admin','kepala_sekolah'])) {
    $leger_boleh = true;
} elseif ($role === 'wali_kelas' && $kelas_id) {
    $kelas_row   = db_row('SELECT wali_kelas_id FROM kelas WHERE id = :id', [':id' => $kelas_id]);
    $leger_boleh = ($kelas_row && (int)$kelas_row['wali_kelas_id'] === (int)$user['id']);
} else {
    $leger_boleh = false;
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
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Raport Siswa</h1>
            <p class="page-subtitle">Lihat dan cetak raport siswa</p>
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
                    <label class="form-label">Jenis Raport</label>
                    <select name="jenis" class="form-control" onchange="this.form.submit()">
                        <option value="bulanan" <?= $jenis==='bulanan' ?'selected':'' ?>>Bulanan</option>
                        <option value="sts" <?= $jenis==='sts'     ?'selected':'' ?>>STS &mdash; Tengah Semester
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
            <span>Daftar Siswa &mdash; Peringkat Kelas</span>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="badge badge-orange"><?= ucfirst($jenis) ?></span>

                <!-- Toggle Publikasi &mdash; hanya admin / super_admin -->
                <?php if (is_admin_or_super()): ?>
                <div class="flex items-center gap-2" style="margin-left:4px">
                    <span class="text-sm font-medium text-gray-600">Terbitkan ke Siswa:</span>
                    <label class="toggle-switch"
                        title="<?= $is_published ? 'Klik untuk mencabut publikasi' : 'Klik untuk menerbitkan raport ini ke siswa' ?>">
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

                <?php if (!in_array($role, ['siswa'])): ?>
                <?php if ($leger_boleh): ?>
                <?php
                    $leger_url = 'index.php?page=raport.leger&ta='.$ta_id.'&kelas='.$kelas_id.'&jenis='.$jenis.'&bulan='.$bulan;
                ?>
                <button type="button"
                    onclick="<?= $ada_nilai ? "window.open('".htmlspecialchars($leger_url)."','_blank')" : "document.getElementById('modalLegerBelumNilai').style.display='flex'" ?>"
                    class="btn btn-sm btn-secondary" title="Lihat Leger Raport kelas ini"
                    style="border-radius:30px">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M3 9h18M3 15h18M9 3v18" />
                    </svg>
                    Leger
                </button>
                <?php else: ?>
                <button type="button" onclick="document.getElementById('modalLegerDitolak').style.display='flex'"
                    class="btn btn-sm btn-secondary" title="Lihat Leger Raport kelas ini" style="border-radius:30px">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M3 9h18M3 15h18M9 3v18" />
                    </svg>
                    Leger
                </button>
                <?php endif; ?>
                <a href="index.php?page=raport.print_kelas&ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
                    target="_blank" class="btn btn-sm btn-primary" title="Buka halaman cetak semua raport kelas ini"
                    style="border-radius:30px">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="6 9 6 2 18 2 18 9" />
                        <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" />
                        <rect x="6" y="14" width="12" height="8" />
                    </svg>
                    Cetak Semua (<?= count($siswa_raport) ?> Siswa)
                </a>
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
            Raport ini <strong>sudah diterbitkan</strong> &mdash; siswa dapat melihat dan mencetak raport mereka.
            <?php else: ?>
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            Raport ini <strong>belum diterbitkan</strong> &mdash; siswa tidak dapat melihat raport mereka.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Peringkat</th>
                        <th>Nama Siswa</th>
                        <th>NIS</th>
                        <th>Mapel Dinilai</th>
                        <th>Rata-rata</th>
                        <th>Predikat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($siswa_raport as $i => $s): ?>
                    <?php $pred = $s['avg_nilai'] ? get_predikat((float)$s['avg_nilai']) : '-'; ?>
                    <tr>
                        <td>
                            <?php if ($i === 0): ?>
                            <span class="badge badge-orange">🥇 1</span>
                            <?php elseif ($i === 1): ?>
                            <span class="badge badge-gray">🥈 2</span>
                            <?php elseif ($i === 2): ?>
                            <span class="badge badge-gray">🥉 3</span>
                            <?php else: ?>
                            <span class="font-bold text-gray-400"><?= $i+1 ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="font-semibold"><?= e($s['nama_lengkap']) ?></td>
                        <td class="text-gray-500"><?= e($s['nis']) ?></td>
                        <td class="text-center"><?= $s['jml_mapel'] ?></td>
                        <td class="font-bold text-orange-600"><?= number_format($s['avg_nilai'] ?? 0, 2) ?></td>
                        <td>
                            <span
                                class="badge <?= $pred==='A'?'badge-green':($pred==='B'?'badge-blue':($pred==='C'?'badge-orange':'badge-red')) ?>">
                                <?= $pred ?>
                            </span>
                        </td>
                        <td>
                            <div class="flex gap-1">
                                <a href="index.php?page=raport.view&siswa=<?= $s['id'] ?>&ta=<?= $ta_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
                                    class="btn btn-sm btn-secondary" style="border-radius:30px">Lihat</a>
                                <a href="index.php?page=raport.print&siswa=<?= $s['id'] ?>&ta=<?= $ta_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
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
            <p>Pilih kelas dan jenis raport untuk menampilkan data.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$leger_boleh && $role !== 'siswa'): ?>
<?php
// Tentukan pesan modal sesuai kondisi
$kelas_dipilih_nama = '';
if ($kelas_id && isset($kelas_row)) {
    // Ambil nama kelas untuk pesan modal wali kelas
    $kelas_nama_row = db_row('SELECT nama_kelas FROM kelas WHERE id = :id', [':id' => $kelas_id]);
    $kelas_dipilih_nama = $kelas_nama_row['nama_kelas'] ?? '';
}
$modal_pesan = ($role === 'wali_kelas')
    ? 'Anda bukan Wali Kelas dari kelas <strong>' . htmlspecialchars($kelas_dipilih_nama) . '</strong>.<br><br>Leger hanya dapat dibuka oleh Wali Kelas dari kelas yang bersangkutan, serta Admin dan Kepala Sekolah.'
    : 'Halaman <strong>Leger Raport</strong> hanya dapat diakses oleh<br><strong>Super Admin, Admin, Kepala Sekolah,</strong> dan <strong>Wali Kelas</strong>.<br><br>Akun Anda sebagai <strong>Guru</strong> tidak memiliki izin untuk membuka halaman ini.';
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

<!-- Modal: belum ada nilai — tampil untuk semua role yang boleh akses leger -->
<?php if ($leger_boleh && !$ada_nilai && $kelas_id): ?>
<?php
    $jenis_label = ['bulanan'=>'Bulanan','sts'=>'STS (Tengah Semester)','semester'=>'Semester (SAS)'];
    $kelas_nama_leger = db_row('SELECT nama_kelas FROM kelas WHERE id = :id', [':id' => $kelas_id])['nama_kelas'] ?? '';
    $input_nilai_url  = 'index.php?page=nilai';
?>
<div id="modalLegerBelumNilai" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);
            align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:14px;padding:36px 32px;max-width:460px;width:90%;
                text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.2);position:relative">
        <button onclick="document.getElementById('modalLegerBelumNilai').style.display='none'"
                style="position:absolute;top:12px;right:14px;background:none;border:none;
                       font-size:1.3rem;color:#9ca3af;cursor:pointer;line-height:1">&times;</button>
        <!-- Ikon -->
        <div style="width:64px;height:64px;background:#fef3c7;border-radius:50%;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
            <svg width="32" height="32" fill="none" stroke="#d97706" stroke-width="2" viewBox="0 0 24 24">
                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
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
            jenis raport <strong><?= htmlspecialchars($jenis_label[$jenis] ?? $jenis) ?></strong>.<br><br>
            Silakan input nilai terlebih dahulu melalui menu <strong>Input Nilai</strong>,
            kemudian buka kembali halaman Leger.
        </div>
        <!-- Aksi -->
        <div style="display:flex;gap:10px;justify-content:center">
            <button onclick="document.getElementById('modalLegerBelumNilai').style.display='none'"
                    style="background:#f3f4f6;color:#374151;border:none;border-radius:30px;
                           padding:10px 22px;font-size:.88rem;font-weight:600;cursor:pointer">
                Tutup
            </button>
            <a href="<?= $input_nilai_url ?>"
               style="background:#f97316;color:#fff;border:none;border-radius:30px;
                      padding:10px 22px;font-size:.88rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
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

        fetch('index.php?page=raport', {
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
                            '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg> Raport ini <strong>sudah diterbitkan</strong> &mdash; siswa dapat melihat dan mencetak raport mereka.';
                    }
                } else {
                    label.textContent = 'Belum Rilis';
                    label.className = 'text-sm font-semibold text-gray-400';
                    if (statusBar) {
                        statusBar.style.background = '#fff7ed';
                        statusBar.style.borderColor = '#fed7aa';
                        statusBar.style.color = '#9a3412';
                        statusBar.innerHTML =
                            '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Raport ini <strong>belum diterbitkan</strong> &mdash; siswa tidak dapat melihat raport mereka.';
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