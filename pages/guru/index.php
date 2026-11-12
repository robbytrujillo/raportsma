<?php
/**
 * pages/guru/index.php
 * Daftar Guru â€" admin saja
 */
require_role(['admin','super_admin']);
require_once __DIR__ . '/../../excel.php';

// â"€â"€ Export CSV â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
// Export XLSX -- kolom sesuai template import guru.
if (isset($_GET['export'])) {
    $rows = db_query(
        "SELECT u.nama_lengkap, u.username, u.email, g.nip, g.status, g.bidang_studi
         FROM users u
         LEFT JOIN guru g ON g.user_id = u.id
         WHERE u.role IN ('guru','wali_kelas') AND u.is_active = 1
         ORDER BY u.nama_lengkap"
    );
    xlsx_template('export_guru_' . date('Ymd') . '.xlsx', [
        'title'    => 'Export Data Guru -- Raportku',
        'subtitle' => 'Kolom merah = wajib saat re-import | Password dikosongkan demi keamanan -- isi saat import',
        'columns'  => [
            ['header'=>'Nama Lengkap', 'field'=>'nama_lengkap',  'width'=>30,'required'=>true,  'type'=>'text','format'=>'@'],
            ['header'=>'Username',     'field'=>'username',      'width'=>20,'required'=>true,  'type'=>'text','format'=>'@',
             'note'=>'Username login, harus unik, tanpa spasi'],
            ['header'=>'Password',     'field'=>'password',      'width'=>18,'required'=>false, 'type'=>'text','format'=>'@',
             'note'=>'Dikosongkan demi keamanan. Kosong = default "password" saat import.'],
            ['header'=>'Email',        'field'=>'email',         'width'=>26,'required'=>false, 'type'=>'text','format'=>'@'],
            ['header'=>'NIP',          'field'=>'nip',           'width'=>22,'required'=>false, 'type'=>'text','format'=>'@',
             'note'=>'Nomor Induk Pegawai (18 digit)'],
            ['header'=>'Status',       'field'=>'status',        'width'=>16,'required'=>true,  'type'=>'dropdown',
             'dropdown'=>['guru','wali_kelas','koordinator'],
             'note'=>'Pilih: guru / wali_kelas / koordinator'],
            ['header'=>'Bidang Studi', 'field'=>'bidang_studi',  'width'=>24,'required'=>false, 'type'=>'text','format'=>'@'],
        ],
        'examples' => array_map(fn($r) => [
            $r['nama_lengkap'], $r['username'], '',  // password dikosongkan
            $r['email'], $r['nip'], $r['status'], $r['bidang_studi'],
        ], $rows),
    ]);
}


// ── Handle TOGGLE UNIT (super_admin only) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'toggle_unit') {
    if (!is_super_admin()) { flash('error','Hanya super admin yang dapat mengubah unit guru.'); }
    elseif (verify_csrf()) {
        $tid      = (int)($_POST['id'] ?? 0);
        $cur_unit = db_row('SELECT unit FROM guru WHERE user_id=:id', [':id'=>$tid])['unit'] ?? 'putra';
        $new_unit = ($cur_unit === 'putra') ? 'putri' : 'putra';
        db_execute('UPDATE guru SET unit=:u WHERE user_id=:id', [':u'=>$new_unit, ':id'=>$tid]);
        flash('success', 'Unit guru diubah ke ' . ucfirst($new_unit) . '.');
    }
    $back = http_build_query(['page'=>'guru','q'=>$_POST['q']??'','per'=>$_POST['per']??'']);
    header("Location: index.php?$back"); exit;
}

// ── Handle TOGGLE AKTIF ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'toggle_active') {
    if (verify_csrf()) {
        $tid     = (int)($_POST['id'] ?? 0);
        $cur     = (int)(db_row('SELECT is_active FROM users WHERE id=:id', [':id'=>$tid])['is_active'] ?? 1);
        $new_val = $cur ? 0 : 1;
        db_execute('UPDATE users SET is_active=:a WHERE id=:id AND role IN ("guru","wali_kelas")', [':a'=>$new_val,':id'=>$tid]);
        flash('success', 'Status guru diubah menjadi ' . ($new_val ? 'Aktif' : 'Nonaktif') . '.');
    }
    $back = http_build_query(['page'=>'guru','q'=>$_POST['q']??'','per'=>$_POST['per']??'']);
    header("Location: index.php?$back"); exit;
}


// â"€â"€ Handle DELETE â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); }
    else {
        $id = (int)($_POST['id'] ?? 0);
        // Hapus user (CASCADE akan hapus data guru)
        db_execute('DELETE FROM users WHERE id = :id AND role IN ("guru","wali_kelas")', [':id' => $id]);
        flash('success', 'Data guru berhasil dihapus.');
    }
    header('Location: index.php?page=guru'); exit;
}

$search = trim($_GET['q'] ?? '');
$params = [];
// Tampilkan semua guru (aktif & nonaktif) agar bisa di-toggle
$where  = "u.role IN ('guru','wali_kelas')";
if ($search) {
    $where .= ' AND (u.nama_lengkap LIKE :q1 OR u.username LIKE :q2 OR g.nip LIKE :q3)';
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}
// Filter unit — admin putri hanya lihat guru unit putri/campuran, dst.
$_gunit_f   = get_user_unit_filter($user, 'g');
if ($_gunit_f['sql'] !== '') {
    $where .= ' ' . $_gunit_f['sql'];
}

// â"€â"€ Paginasi â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
$per_opts = [10, 25, 50, 100];
$per      = in_array((int)($_GET['per'] ?? 0), $per_opts) ? (int)$_GET['per'] : 10;
$page_n   = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page_n - 1) * $per;

$total = (int)(db_row(
    "SELECT COUNT(*) as n FROM users u LEFT JOIN guru g ON g.user_id = u.id WHERE $where",
    $params
)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
if ($page_n > $pages) $page_n = $pages;
$offset = ($page_n - 1) * $per;

$guru_list = db_query(
    "SELECT u.id, u.nama_lengkap, u.username, u.email, u.is_active, u.tanda_tangan,
            g.id as guru_id, g.nip, g.status, g.bidang_studi, g.unit
     FROM users u
     LEFT JOIN guru g ON g.user_id = u.id
     WHERE $where ORDER BY u.nama_lengkap
     LIMIT $per OFFSET $offset",
    $params
);

$status_map = ['guru'=>['Guru','badge-blue'], 'wali_kelas'=>['Wali Kelas','badge-orange'], 'koordinator'=>['Koordinator','badge-purple']];
$qstr = http_build_query(['page'=>'guru','q'=>$search,'per'=>$per]);
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Data Guru</h1>
            <p class="page-subtitle">Kelola data guru dan wali kelas</p>
        </div>
        <div class="flex gap-2">
            <a href="index.php?page=guru&export=1" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                Export CSV
            </a>
            <a href="index.php?page=guru.import" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="17 8 12 3 7 8" />
                    <line x1="12" y1="3" x2="12" y2="15" />
                </svg>
                Import CSV
            </a>
            <a href="index.php?page=guru.create" class="btn btn-primary" style="border-radius: 30px;">
                <svg width=" 16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Tambah Guru
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <form method="GET" action="index.php" class="flex gap-2 flex-wrap items-end">
                <input type="hidden" name="page" value="guru">
                <div>
                    <label class="form-label">Cari Guru</label>
                    <input type="text" name="q" placeholder="Nama / NIP..." value="<?= e($search) ?>"
                        class="form-control" style="max-width:220px">
                </div>
                <div>
                    <label class="form-label">Tampil</label>
                    <select name="per" class="form-control" style="width:90px" onchange="this.form.submit()">
                        <?php foreach ([10,25,50,100] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?>/hal</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2 items-end" style="padding-bottom:0">
                    <button type="submit" class="btn btn-primary btn-sm" style="border-radius: 30px;">Cari</button>
                    <?php if ($search || $per !== 10): ?><a href="index.php?page=guru"
                        class="btn btn-secondary btn-sm">Reset</a><?php endif; ?>
                </div>
            </form>
            <span class="text-sm text-gray-500"><?= $total ?> guru</span>
        </div>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama Guru</th>
                        <th>NIP</th>
                        <th>Bidang Studi</th>
                        <th>Username</th>
                        <th>Jabatan</th>
                        <th>Unit</th>
                        <th>Status</th>
                        <th>TTD</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($guru_list)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-8 text-gray-400">Belum ada data guru.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($guru_list as $i => $g): ?>
                    <tr style="<?= !($g['is_active'] ?? 1) ? 'opacity:.6' : '' ?>">
                        <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="font-semibold"><?= e($g['nama_lengkap']) ?></div>
                            <div class="text-xs text-gray-400"><?= e($g['email'] ?: '-') ?></div>
                        </td>
                        <td class="text-gray-500 text-xs"><?= e($g['nip'] ?: '-') ?></td>
                        <td class="text-xs"><?= e($g['bidang_studi'] ?: '-') ?></td>
                        <td class="text-gray-500"><?= e($g['username']) ?></td>
                        <td>
                            <?php [$lbl,$cls] = $status_map[$g['status'] ?? 'guru'] ?? ['Guru','badge-blue']; ?>
                            <span class="badge <?= $cls ?>"><?= $lbl ?></span>
                        </td>
                        <td>
                            <?php $g_unit = $g['unit'] ?? 'putra'; ?>
                            <?php if (is_super_admin()): ?>
                            <form method="POST" action="index.php?page=guru" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_unit">
                                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                <input type="hidden" name="q" value="<?= e($search) ?>">
                                <input type="hidden" name="per" value="<?= $per ?>">
                                <button type="submit"
                                    title="Klik untuk ganti unit"
                                    style="border:none;background:none;cursor:pointer;padding:0">
                                    <span class="badge <?= $g_unit==='putri'?'badge-purple':'badge-blue' ?>"
                                        style="cursor:pointer;user-select:none">
                                        <?= $g_unit==='putri'?'Putri':'Putra' ?>
                                    </span>
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="badge <?= $g_unit==='putri'?'badge-purple':'badge-blue' ?>">
                                <?= $g_unit==='putri'?'Putri':'Putra' ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= ($g['is_active'] ?? 1) ? 'badge-green' : 'badge-red' ?>">
                                <?= ($g['is_active'] ?? 1) ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($g['tanda_tangan']): ?>
                            <img src="<?= UPLOAD_URL . e($g['tanda_tangan']) ?>" alt="TTD"
                                style="height:36px;object-fit:contain">
                            <?php else: ?>
                            <span class="text-gray-400 text-xs">Belum ada</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="flex gap-1">
                                <a href="index.php?page=guru.edit&id=<?= $g['id'] ?>" class="btn btn-sm btn-secondary"
                                    style="border-radius: 30px;">Edit</a>
                                <!-- Tombol Toggle Aktif/Nonaktif -->
                                <form method="POST" action="index.php?page=guru" style="display:inline"
                                    onsubmit="return confirm('<?= ($g['is_active']??1) ? 'Nonaktifkan' : 'Aktifkan' ?> guru <?= e($g['nama_lengkap']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                    <input type="hidden" name="q" value="<?= e($search) ?>">
                                    <input type="hidden" name="per" value="<?= $per ?>">
                                    <button type="submit"
                                        class="btn btn-sm <?= ($g['is_active']??1) ? 'btn-warning' : 'btn-success' ?>"
                                        style="border-radius:30px">
                                        <?= ($g['is_active']??1) ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                                <form method="POST" action="index.php?page=guru" style="display:inline"
                                    onsubmit="return confirm('Hapus guru <?= e($g['nama_lengkap']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                        style="border-radius: 30px;">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginasi -->
        <?php if ($pages > 1): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 pagination-wrap">
            <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages ?> (<?= $total ?> guru)</span>
            <div class="pagination">
                <a href="?<?= $qstr ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>"
                    style="border-radius: 30px;">
                    < Prev</a>
                        <?php
          $start = max(1, $page_n - 3);
          $end   = min($pages, $page_n + 3);
          if ($start > 1): ?><a href="?<?= $qstr ?>&p=1" class="page-link">1</a><?php
            if ($start > 2): ?><span class="page-link disabled">â€¦</span><?php endif;
          endif;
          for ($pi = $start; $pi <= $end; $pi++):
        ?>
                        <a href="?<?= $qstr ?>&p=<?= $pi ?>"
                            class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a>
                        <?php endfor;
          if ($end < $pages):
            if ($end < $pages - 1): ?><span class="page-link disabled">â€¦</span><?php endif;
            ?><a href="?<?= $qstr ?>&p=<?= $pages ?>" class="page-link"><?= $pages ?></a><?php
          endif;
        ?>
                        <a href="?<?= $qstr ?>&p=<?= min($pages,$page_n+1) ?>"
                            class="page-link <?= $page_n>=$pages?'disabled':'' ?>" style="border-radius: 30px;">Next
                            ></a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>