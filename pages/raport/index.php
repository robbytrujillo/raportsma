<?php
/**
 * pages/raport/index.php
 * Daftar raport yang dapat dilihat berdasarkan role
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

// ── Untuk SISWA: hanya raport miliknya ────────────────────────
if ($role === 'siswa') {
    $siswa_data = db_row('SELECT s.*, k.nama_kelas, k.id as kelas_id FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id WHERE s.user_id = :uid', [':uid'=>$user['id']]);

    // Ambil daftar periode raport yang tersedia untuk siswa ini, beserta rata-rata nilai
    $raport_list = db_query(
        'SELECT n.jenis_raport, n.bulan, n.tahun_ajaran_id,
                ta.nama as ta_nama, ta.semester,
                ROUND(AVG(n.nilai_akhir),2) as avg_nilai,
                COUNT(n.id) as jml_mapel
         FROM nilai n
         LEFT JOIN tahun_ajaran ta ON ta.id = n.tahun_ajaran_id
         WHERE n.siswa_user_id = :uid
         GROUP BY n.jenis_raport, n.bulan, n.tahun_ajaran_id, ta.nama, ta.semester
         ORDER BY n.tahun_ajaran_id DESC, n.jenis_raport, n.bulan',
        [':uid'=>$user['id']]
    );

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

    <!-- Daftar Raport Tersedia -->
    <?php if (empty($raport_list)): ?>
    <div class="card">
        <div class="card-body empty-state">
            <p>Belum ada nilai yang tersedia.</p>
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
                        <?php if ($bulan_nama): ?>— <?= $bulan_nama ?><?php endif; ?>
                    </span>
                </div>
                <!-- Rata-rata nilai -->
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
                        class="btn btn-secondary btn-sm flex-1 justify-center">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        Lihat
                    </a>
                    <a href="index.php?page=raport.print&siswa=<?= $user['id'] ?>&ta=<?= $r['tahun_ajaran_id'] ?>&jenis=<?= $r['jenis_raport'] ?>&bulan=<?= $r['bulan']??0 ?>"
                        target="_blank" class="btn btn-primary btn-sm flex-1 justify-center">
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
// Ambil status guru
$guru_detail = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user['id']]);
$guru_status = $guru_detail['status'] ?? 'guru';

if ($role === 'admin' || $role === 'kepala_sekolah') {
    $kelas_list = db_query(
        'SELECT * FROM kelas WHERE tahun_ajaran_id = :ta ORDER BY nama_kelas',
        [':ta' => $ta_id]
    );
} elseif ($guru_status === 'wali_kelas') {
    // Wali kelas: kelas yang dipimpin + yang diajar
    // :uid2 = nilai sama dengan :uid — PDO tidak boleh pakai named param duplikat
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         LEFT JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id AND gmk.guru_user_id = :uid
         WHERE k.tahun_ajaran_id = :ta
           AND (k.wali_kelas_id = :uid2 OR gmk.id IS NOT NULL)
         ORDER BY k.nama_kelas',
        [':uid' => $user['id'], ':uid2' => $user['id'], ':ta' => $ta_id]
    );
} else {
    // Guru biasa: hanya kelas yang diajar
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
    // Filter bulan jika jenis bulanan/sts
    $bulan_cond  = '';
    // :kid dipakai 2x di query (JOIN dan WHERE) — PDO tidak boleh duplikat named param
    // Gunakan :kid2 dengan nilai sama sebagai workaround
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
                        <option value="">— Pilih Kelas —</option>
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
                        <option value="sts" <?= $jenis==='sts'     ?'selected':'' ?>>STS — Tengah Semester</option>
                        <option value="semester" <?= $jenis==='semester'?'selected':'' ?>>Semester (SAS)</option>
                    </select>
                </div>
                <!-- Bulan (tampil hanya jika jenis bulanan atau sts) -->
                <?php if (in_array($jenis, ['bulanan','sts'])): ?>
                <div>
                    <label class="form-label">Bulan</label>
                    <select name="bulan" class="form-control">
                        <option value="">— Pilih Bulan —</option>
                        <?php foreach (BULAN_ID as $bk => $bv): ?>
                        <option value="<?= $bk ?>" <?= $bk==$bulan?'selected':'' ?>><?= $bv ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-full" style="border-radius: 30px">Tampilkan</button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($kelas_id && !empty($siswa_raport)): ?>
    <div class="card">
        <div class="card-header">
            <span>Daftar Siswa — Peringkat Kelas</span>
            <div class="flex items-center gap-2">
                <span class="badge badge-orange"><?= ucfirst($jenis) ?></span>
                <?php if (!in_array($role, ['siswa'])): ?>
                <a href="index.php?page=raport.print_kelas&ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
                    target="_blank" class="btn btn-sm btn-primary" title="Buka halaman cetak semua raport kelas ini"
                    style="border-radius: 30px">
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
                                    class="btn btn-sm btn-secondary" style="border-radius: 30px">Lihat</a>
                                <a href="index.php?page=raport.print&siswa=<?= $s['id'] ?>&ta=<?= $ta_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
                                    target="_blank" class="btn btn-sm btn-primary" style="border-radius: 30px">Cetak</a>
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