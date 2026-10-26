<?php
/**
 * pages/absensi/index.php
 * Data absensi siswa per kelas — input oleh wali kelas/admin
 */
require_role(['admin','wali_kelas','guru']);

$user     = current_user();
$role     = $user['role'];
$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_id    = (int)($_GET['ta'] ?? ($ta_aktif['id'] ?? 0));

// ── Handle Save ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); header('Location: index.php?page=absensi'); exit; }

    $kelas_id = (int)$_POST['kelas_id'];
    $ta_id_p  = (int)$_POST['tahun_ajaran_id'];
    $siswa_ids = $_POST['siswa_ids'] ?? [];

    foreach ($siswa_ids as $sid) {
        $sid   = (int)$sid;
        $sakit = (int)($_POST["sakit_$sid"] ?? 0);
        $izin  = (int)($_POST["izin_$sid"] ?? 0);
        $alpa  = (int)($_POST["alpa_$sid"] ?? 0);

        // Upsert
        $exist = db_row('SELECT id FROM absensi WHERE siswa_user_id=:s AND kelas_id=:k AND tahun_ajaran_id=:ta',
            [':s'=>$sid, ':k'=>$kelas_id, ':ta'=>$ta_id_p]);
        if ($exist) {
            db_execute('UPDATE absensi SET sakit=:s, izin=:i, alpa=:a WHERE id=:id',
                [':s'=>$sakit, ':i'=>$izin, ':a'=>$alpa, ':id'=>$exist['id']]);
        } else {
            db_execute('INSERT INTO absensi (siswa_user_id,kelas_id,tahun_ajaran_id,sakit,izin,alpa)
                        VALUES (:sid,:kid,:ta,:s,:i,:a)',
                [':sid'=>$sid, ':kid'=>$kelas_id, ':ta'=>$ta_id_p, ':s'=>$sakit, ':i'=>$izin, ':a'=>$alpa]);
        }
    }
    flash('success','Data absensi berhasil disimpan.');
    header("Location: index.php?page=absensi&ta=$ta_id_p&kelas=$kelas_id"); exit;
}

$ta_list    = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$kelas_id   = (int)($_GET['kelas'] ?? 0);

// Kelas yang bisa diakses
if ($role === 'admin') {
    $kelas_list = db_query('SELECT * FROM kelas WHERE tahun_ajaran_id = :ta ORDER BY nama_kelas', [':ta'=>$ta_id]);
} else {
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id
         WHERE gmk.guru_user_id = :uid AND gmk.tahun_ajaran_id = :ta ORDER BY k.nama_kelas',
        [':uid'=>$user['id'], ':ta'=>$ta_id]
    );
    // Juga kelas yang jadi walinya
    if ($role === 'wali_kelas') {
        $wali_kelas = db_query('SELECT * FROM kelas WHERE wali_kelas_id = :uid AND tahun_ajaran_id = :ta',
            [':uid'=>$user['id'], ':ta'=>$ta_id]);
        // Merge unik
        $existing_ids = array_column($kelas_list, 'id');
        foreach ($wali_kelas as $wk) {
            if (!in_array($wk['id'], $existing_ids)) $kelas_list[] = $wk;
        }
    }
}

$siswa_list  = [];
$absensi_map = [];
if ($kelas_id) {
    $siswa_list = db_query(
        'SELECT u.id, u.nama_lengkap, s.nis FROM users u
         INNER JOIN siswa s ON s.user_id = u.id
         WHERE s.kelas_id = :kid ORDER BY u.nama_lengkap',
        [':kid' => $kelas_id]
    );
    $abs_rows = db_query(
        'SELECT * FROM absensi WHERE kelas_id = :kid AND tahun_ajaran_id = :ta',
        [':kid'=>$kelas_id, ':ta'=>$ta_id]
    );
    foreach ($abs_rows as $ab) {
        $absensi_map[$ab['siswa_user_id']] = $ab;
    }
}
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Data Absensi</h1>
            <p class="page-subtitle">Rekap kehadiran siswa per semester</p>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" action="index.php" class="card mb-5">
        <div class="card-body">
            <input type="hidden" name="page" value="absensi">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
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
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-100"
                        style="border-radius: 30px; text-align: center;">Tampilkan</button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($kelas_id && !empty($siswa_list)): ?>
    <div class="card">
        <div class="card-header">
            <span>Input Absensi Kelas —
                <?= e(array_column($kelas_list,'nama_kelas',null)[array_search($kelas_id, array_column($kelas_list,'id'))] ?? '') ?></span>
        </div>
        <form method="POST" action="index.php?page=absensi&ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="kelas_id" value="<?= $kelas_id ?>">
            <input type="hidden" name="tahun_ajaran_id" value="<?= $ta_id ?>">
            <?php foreach ($siswa_list as $s): ?>
            <input type="hidden" name="siswa_ids[]" value="<?= $s['id'] ?>">
            <?php endforeach; ?>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Siswa</th>
                            <th>NIS</th>
                            <th class="text-center" style="width:100px">Sakit</th>
                            <th class="text-center" style="width:100px">Izin</th>
                            <th class="text-center" style="width:100px">Alpa</th>
                            <th class="text-center" style="width:80px">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($siswa_list as $i => $s): ?>
                        <?php $ab = $absensi_map[$s['id']] ?? ['sakit'=>0,'izin'=>0,'alpa'=>0]; ?>
                        <tr>
                            <td class="text-gray-400 text-xs"><?= $i+1 ?></td>
                            <td class="font-semibold"><?= e($s['nama_lengkap']) ?></td>
                            <td class="text-gray-500"><?= e($s['nis']) ?></td>
                            <td>
                                <input type="number" name="sakit_<?= $s['id'] ?>" min="0" max="999"
                                    class="form-control text-center" style="padding:5px" value="<?= $ab['sakit'] ?>">
                            </td>
                            <td>
                                <input type="number" name="izin_<?= $s['id'] ?>" min="0" max="999"
                                    class="form-control text-center" style="padding:5px" value="<?= $ab['izin'] ?>">
                            </td>
                            <td>
                                <input type="number" name="alpa_<?= $s['id'] ?>" min="0" max="999"
                                    class="form-control text-center" style="padding:5px" value="<?= $ab['alpa'] ?>">
                            </td>
                            <td class="text-center font-bold text-orange-600">
                                <?= ($ab['sakit']+$ab['izin']+$ab['alpa']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-100">
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
                        viewBox="0 0 24 24">
                        <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                        <polyline points="17 21 17 13 7 13 7 21" />
                    </svg>
                    Simpan Absensi
                </button>
            </div>
        </form>
    </div>
    <?php elseif ($kelas_id): ?>
    <div class="card">
        <div class="card-body empty-state">
            <p>Belum ada siswa di kelas ini.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <rect x="3" y="4" width="18" height="18" rx="2" />
                <line x1="3" y1="10" x2="21" y2="10" />
            </svg>
            <p>Pilih kelas untuk menampilkan data absensi.</p>
        </div>
    </div>
    <?php endif; ?>
</div>