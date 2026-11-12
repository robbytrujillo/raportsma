<?php
/**
 * pages/siswa/index.php
 * Daftar siswa – admin / super_admin
 */
require_role(['admin','super_admin']);
require_once __DIR__ . '/../../excel.php';
$user = current_user();
$_exp_uf   = get_user_unit_filter($user);
$_exp_usql_siswa = '';
if ($_exp_uf['units']) {
    $u_in = implode("','", $_exp_uf['units']);
    $_exp_usql_siswa = "AND k.unit IN ('$u_in')";
}

// ── Export Excel ──────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $rows = db_query(
        "SELECT u.nama_lengkap, u.username, u.email, s.nis, s.nisn,
                s.kelas_id, s.tempat_lahir, s.tanggal_lahir, s.nama_ayah, s.nama_ibu, s.alamat,
                k.nama_kelas
         FROM users u
         INNER JOIN siswa s ON s.user_id = u.id
         LEFT  JOIN kelas k ON k.id = s.kelas_id
         WHERE u.role = 'siswa' AND u.is_active = 1 {$_exp_usql_siswa}
         ORDER BY s.kelas_id, u.nama_lengkap"
    );
    xlsx_template('export_siswa_' . date('Ymd') . '.xlsx', [
        'title'    => 'Export Data Siswa -- Raportku',
        'subtitle' => 'Kolom merah = wajib saat re-import | Password dikosongkan demi keamanan -- isi saat import',
        'columns'  => [
            ['header'=>'Nama Lengkap',  'field'=>'nama_lengkap',  'width'=>28,'required'=>true,  'type'=>'text','format'=>'@'],
            ['header'=>'Username',      'field'=>'username',      'width'=>18,'required'=>true,  'type'=>'text','format'=>'@',
             'note'=>'Username untuk login, harus unik, tanpa spasi'],
            ['header'=>'Password',      'field'=>'password',      'width'=>16,'required'=>false, 'type'=>'text','format'=>'@',
             'note'=>'Dikosongkan demi keamanan. Isi password baru saat re-import. Kosong = default "password"'],
            ['header'=>'Email',         'field'=>'email',         'width'=>26,'required'=>false, 'type'=>'text','format'=>'@'],
            ['header'=>'NIS',           'field'=>'nis',           'width'=>14,'required'=>true,  'type'=>'text','format'=>'@',
             'note'=>'Nomor Induk Siswa, harus unik'],
            ['header'=>'NISN',          'field'=>'nisn',          'width'=>14,'required'=>false, 'type'=>'text','format'=>'@',
             'note'=>'Nomor Induk Siswa Nasional (10 digit)'],
            ['header'=>'Kelas ID',      'field'=>'kelas_id',      'width'=>10,'required'=>true,  'type'=>'number',
             'note'=>'ID kelas -- gunakan angka dari kolom [Nama Kelas] di bawah'],
            ['header'=>'Tempat Lahir',  'field'=>'tempat_lahir',  'width'=>18,'required'=>false, 'type'=>'text','format'=>'@'],
            ['header'=>'Tanggal Lahir', 'field'=>'tanggal_lahir', 'width'=>14,'required'=>false, 'type'=>'text','format'=>'@',
             'note'=>'Format: YYYY-MM-DD'],
            ['header'=>'Nama Ayah',     'field'=>'nama_ayah',     'width'=>22,'required'=>false, 'type'=>'text','format'=>'@'],
            ['header'=>'Nama Ibu',      'field'=>'nama_ibu',      'width'=>22,'required'=>false, 'type'=>'text','format'=>'@'],
            ['header'=>'Alamat',        'field'=>'alamat',        'width'=>32,'required'=>false, 'type'=>'text','format'=>'@'],
            ['header'=>'[Nama Kelas]',  'field'=>'nama_kelas',    'width'=>16,'required'=>false, 'type'=>'text','format'=>'@',
             'note'=>'Referensi -- Kelas ID di atas yang digunakan saat import'],
        ],
        'examples' => array_map(fn($r) => [
            $r['nama_lengkap'], $r['username'], '',
            $r['email'], $r['nis'], $r['nisn'], $r['kelas_id'],
            $r['tempat_lahir'], $r['tanggal_lahir'],
            $r['nama_ayah'], $r['nama_ibu'], $r['alamat'],
            $r['nama_kelas'],
        ], $rows),
    ]);
}

// ── Handle TOGGLE AKTIF ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'toggle_active') {
    if (verify_csrf()) {
        $tid     = (int)($_POST['id'] ?? 0);
        $cur     = (int)(db_row('SELECT is_active FROM users WHERE id=:id AND role="siswa"', [':id'=>$tid])['is_active'] ?? 1);
        $new_val = $cur ? 0 : 1;
        db_execute('UPDATE users SET is_active=:a WHERE id=:id AND role="siswa"', [':a'=>$new_val,':id'=>$tid]);
        flash('success', 'Status siswa diubah menjadi ' . ($new_val ? 'Aktif' : 'Nonaktif') . '.');
    }
    $back = http_build_query(['page'=>'siswa','ta'=>$_POST['ta']??'','kelas'=>$_POST['kelas']??'','q'=>$_POST['q']??'','per'=>$_POST['per']??'']);
    header("Location: index.php?$back"); exit;
}

// ── Handle DELETE ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    if (verify_csrf()) {
        db_execute('DELETE FROM users WHERE id = :id AND role = "siswa"', [':id'=>(int)$_POST['id']]);
        flash('success','Data siswa dihapus.');
    }
    header('Location: index.php?page=siswa'); exit;
}

// ── Handle BULK PINDAH KELAS ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'bulk_pindah_kelas') {
    if (verify_csrf()) {
        $ids_raw   = $_POST['siswa_ids'] ?? [];
        $kelas_baru = (int)($_POST['kelas_tujuan_id'] ?? 0);
        $ids = array_filter(array_map('intval', $ids_raw));

        if ($kelas_baru && !empty($ids)) {
            // Pastikan kelas_tujuan valid
            $kelas_valid = db_row('SELECT id, nama_kelas FROM kelas WHERE id = :id', [':id'=>$kelas_baru]);
            if ($kelas_valid) {
                $berhasil = 0;
                foreach ($ids as $uid) {
                    // Pastikan user adalah siswa
                    $check = db_row('SELECT u.id FROM users u INNER JOIN siswa s ON s.user_id = u.id WHERE u.id=:id AND u.role="siswa"', [':id'=>$uid]);
                    if ($check) {
                        db_execute('UPDATE siswa SET kelas_id = :kid WHERE user_id = :uid', [':kid'=>$kelas_baru, ':uid'=>$uid]);
                        $berhasil++;
                    }
                }
                flash('success', "$berhasil siswa berhasil dipindahkan ke kelas {$kelas_valid['nama_kelas']}.");
            } else {
                flash('error', 'Kelas tujuan tidak valid.');
            }
        } else {
            flash('error', 'Pilih minimal 1 siswa dan kelas tujuan yang valid.');
        }
    }
    $back = http_build_query(['page'=>'siswa','ta'=>$_POST['ta']??'','kelas'=>$_POST['kelas']??'','q'=>$_POST['q']??'','per'=>$_POST['per']??'']);
    header("Location: index.php?$back"); exit;
}

// ── Data tampilan ─────────────────────────────────────────────────────────────
$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_id    = (int)($_GET['ta'] ?? ($ta_aktif['id'] ?? 0));
$kelas_id = (int)($_GET['kelas'] ?? 0);
$search   = trim($_GET['q'] ?? '');

$ta_list    = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$_unit_f   = get_user_unit_filter($user);
$_unit_sql = $_unit_f['sql'];
$kelas_list = db_query("SELECT * FROM kelas k WHERE k.tahun_ajaran_id = :ta {$_unit_sql} ORDER BY k.nama_kelas", [':ta'=>$ta_id]);
// Semua kelas dari semua tahun ajaran (untuk target pindah kelas) — difilter unit
$semua_kelas = db_query(
    "SELECT k.id, k.nama_kelas, k.tingkat, ta.nama as ta_nama, ta.semester
     FROM kelas k
     JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id
     WHERE 1=1 {$_unit_sql}
     ORDER BY ta.id DESC, k.tingkat, k.nama_kelas"
);

$where  = ['u.role = "siswa"'];
$params = [];

// Selalu terapkan filter unit ke query siswa (join kelas k sudah ada)
if ($_unit_f['units']) {
    $unit_in = implode("','", $_unit_f['units']);
    $where[] = "k.unit IN ('$unit_in')";
}

if ($kelas_id) {
    $where[] = 's.kelas_id = :kid';
    $params[':kid'] = $kelas_id;
} else {
    $where[] = 'k.tahun_ajaran_id = :ta';
    $params[':ta'] = $ta_id;
}
if ($search) {
    $where[]       = '(u.nama_lengkap LIKE :q1 OR s.nis LIKE :q2 OR s.nisn LIKE :q3)';
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}
$whereStr = implode(' AND ', $where);

$per_opts = [10, 25, 50, 100];
$per      = in_array((int)($_GET['per'] ?? 0), $per_opts) ? (int)$_GET['per'] : 10;
$page_n   = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page_n - 1) * $per;

$total = (int)(db_row(
    "SELECT COUNT(*) as n
     FROM users u
     INNER JOIN siswa s ON s.user_id = u.id
     INNER JOIN kelas k ON k.id = s.kelas_id
     WHERE $whereStr",
    $params
)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
if ($page_n > $pages) $page_n = $pages;
$offset = ($page_n - 1) * $per;

$siswa_list = db_query(
    "SELECT u.id, u.nama_lengkap, u.username, u.email, u.is_active,
            s.id as siswa_id, s.nis, s.nisn, s.tanggal_lahir, s.tempat_lahir,
            k.nama_kelas, k.id as kelas_id
     FROM users u
     INNER JOIN siswa s ON s.user_id = u.id
     INNER JOIN kelas k ON k.id = s.kelas_id
     WHERE $whereStr ORDER BY k.nama_kelas, u.nama_lengkap
     LIMIT $per OFFSET $offset",
    $params
);

$qstr = http_build_query(['page'=>'siswa','ta'=>$ta_id,'kelas'=>$kelas_id,'q'=>$search,'per'=>$per]);
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Data Siswa</h1>
            <p class="page-subtitle">Kelola data siswa sekolah</p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <a href="index.php?page=siswa&export=1" class="btn btn-secondary" style="border-radius:30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Export Excel
            </a>
            <a href="index.php?page=siswa.import" class="btn btn-secondary" style="border-radius:30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Import Excel
            </a>
            <a href="index.php?page=siswa.create" class="btn btn-primary" style="border-radius:30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Tambah Siswa
            </a>
        </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="index.php" class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <input type="hidden" name="page" value="siswa">
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
                        <option value="">Semua Kelas</option>
                        <?php foreach ($kelas_list as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $k['id']==$kelas_id?'selected':'' ?>>
                            <?= e($k['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Cari</label>
                    <input type="text" name="q" class="form-control" placeholder="Nama / NIS..."
                        value="<?= e($search) ?>">
                </div>
                <div>
                    <label class="form-label">Tampil</label>
                    <select name="per" class="form-control" onchange="this.form.submit()">
                        <?php foreach ([10,25,50,100] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?>/hal</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-full" style="border-radius:30px">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Daftar Siswa -->
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span>Daftar Siswa</span>
                <span class="text-sm text-gray-500"><?= $total ?> siswa</span>
            </div>
            <!-- Tombol Pindah Kelas Massal — muncul jika ada yang dicentang -->
            <button type="button" id="btnPindahKelas"
                onclick="bukaPindahKelas()"
                class="btn btn-sm"
                style="border-radius:30px;background:#f97316;color:#fff;display:none">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                </svg>
                Pindah Kelas (<span id="jmlDipilih">0</span>)
            </button>
        </div>
        <div class="table-container">
            <table class="data-table" id="dataTable">
                <thead>
                    <tr>
                        <!-- Kolom checkbox select-all -->
                        <th style="width:36px;text-align:center">
                            <input type="checkbox" id="chkAll" title="Pilih semua"
                                style="width:15px;height:15px;cursor:pointer"
                                onchange="toggleSemuaSiswa(this.checked)">
                        </th>
                        <th>#</th>
                        <th>Nama Siswa</th>
                        <th>NIS</th>
                        <th>NISN</th>
                        <th>Kelas</th>
                        <th>Tempat, Tgl Lahir</th>
                        <th>Username</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($siswa_list)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-8 text-gray-400">Belum ada data siswa.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($siswa_list as $i => $s): ?>
                    <tr style="<?= !($s['is_active'] ?? 1) ? 'opacity:.6' : '' ?>">
                        <td style="text-align:center">
                            <input type="checkbox" class="chk-siswa" value="<?= $s['id'] ?>"
                                style="width:15px;height:15px;cursor:pointer"
                                onchange="updatePilihan()">
                        </td>
                        <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="font-semibold"><?= e($s['nama_lengkap']) ?></div>
                            <div class="text-xs text-gray-400"><?= e($s['nis'] ?: '') ?></div>
                        </td>
                        <td><?= e($s['nis'] ?: '-') ?></td>
                        <td class="text-gray-400"><?= e($s['nisn'] ?: '-') ?></td>
                        <td><span class="badge badge-blue"><?= e($s['nama_kelas']) ?></span></td>
                        <td class="text-sm text-gray-500">
                            <?= e($s['tempat_lahir'] ?: '-') ?>,
                            <?= $s['tanggal_lahir'] ? date('d/m/Y', strtotime($s['tanggal_lahir'])) : '-' ?>
                        </td>
                        <td class="text-gray-500"><?= e($s['username']) ?></td>
                        <td>
                            <span class="badge <?= ($s['is_active'] ?? 1) ? 'badge-green' : 'badge-red' ?>">
                                <?= ($s['is_active'] ?? 1) ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td>
                            <div class="flex gap-1">
                                <a href="index.php?page=siswa.edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-secondary"
                                    style="border-radius:30px">Edit</a>
                                <form method="POST" action="index.php?page=siswa" style="display:inline"
                                    onsubmit="return confirm('<?= ($s['is_active']??1) ? 'Nonaktifkan' : 'Aktifkan' ?> siswa <?= addslashes(e($s['nama_lengkap'])) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="ta" value="<?= $ta_id ?>">
                                    <input type="hidden" name="kelas" value="<?= $kelas_id ?>">
                                    <input type="hidden" name="q" value="<?= e($search) ?>">
                                    <input type="hidden" name="per" value="<?= $per ?>">
                                    <button type="submit"
                                        class="btn btn-sm <?= ($s['is_active']??1) ? 'btn-warning' : 'btn-success' ?>"
                                        style="border-radius:30px">
                                        <?= ($s['is_active']??1) ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                                <form method="POST" action="index.php?page=siswa" style="display:inline"
                                    onsubmit="return confirm('Hapus siswa <?= addslashes(e($s['nama_lengkap'])) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                        style="border-radius:30px">Hapus</button>
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
            <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages ?> (<?= $total ?> siswa)</span>
            <div class="pagination">
                <a href="?<?= $qstr ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>"
                    style="border-radius:30px">&#8249; Prev</a>
                <?php
                $start = max(1, $page_n - 3);
                $end   = min($pages, $page_n + 3);
                if ($start > 1): ?><a href="?<?= $qstr ?>&p=1" class="page-link">1</a><?php
                    if ($start > 2): ?><span class="page-link disabled">&hellip;</span><?php endif;
                endif;
                for ($pi = $start; $pi <= $end; $pi++): ?>
                <a href="?<?= $qstr ?>&p=<?= $pi ?>"
                    class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a>
                <?php endfor;
                if ($end < $pages):
                    if ($end < $pages - 1): ?><span class="page-link disabled">&hellip;</span><?php endif; ?>
                    <a href="?<?= $qstr ?>&p=<?= $pages ?>" class="page-link"><?= $pages ?></a><?php
                endif; ?>
                <a href="?<?= $qstr ?>&p=<?= min($pages,$page_n+1) ?>"
                    class="page-link <?= $page_n>=$pages?'disabled':'' ?>" style="border-radius:30px">Next &#8250;</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Modal Pindah Kelas Massal ─────────────────────────────────────────── -->
<div class="modal-overlay" id="modalPindahKelas" onclick="if(event.target===this)tutupPindahKelas()">
    <div class="modal" style="max-width:480px;width:100%">
        <div class="modal-header">
            <h3 class="modal-title" style="display:flex;align-items:center;gap:8px">
                <svg width="18" height="18" fill="none" stroke="#f97316" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                </svg>
                Pindah Kelas Massal
            </h3>
            <button type="button" onclick="tutupPindahKelas()" class="btn btn-sm btn-secondary" style="border-radius:50%;padding:4px 8px">&times;</button>
        </div>
        <form method="POST" action="index.php?page=siswa" id="formPindahKelas">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_pindah_kelas">
            <input type="hidden" name="ta" value="<?= $ta_id ?>">
            <input type="hidden" name="kelas" value="<?= $kelas_id ?>">
            <input type="hidden" name="q" value="<?= e($search) ?>">
            <input type="hidden" name="per" value="<?= $per ?>">
            <div id="hiddenSiswaIds"></div><!-- diisi JS -->

            <div class="modal-body" style="padding:20px 24px">
                <!-- Ringkasan siswa dipilih -->
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 16px;margin-bottom:18px">
                    <div class="text-sm font-semibold text-orange-700" style="margin-bottom:6px">
                        Siswa yang akan dipindahkan:
                    </div>
                    <ul id="listNamaSiswa"
                        style="margin:0;padding-left:18px;font-size:.82rem;color:#92400e;max-height:140px;overflow-y:auto;line-height:1.8">
                    </ul>
                </div>

                <!-- Pilih kelas tujuan -->
                <div class="form-group">
                    <label class="form-label" style="font-weight:700">
                        Kelas Tujuan
                        <span class="text-orange-500">*</span>
                    </label>
                    <select name="kelas_tujuan_id" id="selKelasTujuan" class="form-control" required>
                        <option value="">-- Pilih Kelas Tujuan --</option>
                        <?php
                        $prev_ta = null;
                        foreach ($semua_kelas as $k):
                            $ta_label = $k['ta_nama'] . ' Sem.' . $k['semester'];
                            if ($prev_ta !== $ta_label):
                                if ($prev_ta !== null) echo '</optgroup>';
                                echo '<optgroup label="' . e($ta_label) . '">';
                                $prev_ta = $ta_label;
                            endif;
                        ?>
                        <option value="<?= $k['id'] ?>"><?= e($k['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($prev_ta !== null): ?></optgroup><?php endif; ?>
                    </select>
                    <p class="text-xs text-gray-400" style="margin-top:5px">
                        Kelas dari semua tahun ajaran tersedia. Pilih kelas tujuan yang sesuai.
                    </p>
                </div>
            </div>

            <div class="modal-footer" style="padding:14px 24px;gap:8px">
                <button type="button" onclick="tutupPindahKelas()" class="btn btn-secondary" style="border-radius:30px">
                    Batal
                </button>
                <button type="submit" class="btn btn-primary" style="border-radius:30px"
                    onclick="return konfirmasiPindah()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path d="M20 6L9 17l-5-5"/>
                    </svg>
                    Pindahkan Sekarang
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/* ── Data nama siswa di halaman ini (untuk ditampilkan di modal) ── */
const SISWA_NAMA = {
<?php foreach ($siswa_list as $s): ?>
    <?= (int)$s['id'] ?>: <?= json_encode($s['nama_lengkap']) ?>,
<?php endforeach; ?>
};

/* ── Update counter pilihan & tampilkan tombol ── */
function updatePilihan() {
    const dipilih = document.querySelectorAll('.chk-siswa:checked');
    const btn     = document.getElementById('btnPindahKelas');
    const jml     = document.getElementById('jmlDipilih');
    jml.textContent = dipilih.length;
    btn.style.display = dipilih.length > 0 ? '' : 'none';

    // Sinkron checkbox "pilih semua"
    const semua   = document.querySelectorAll('.chk-siswa');
    const chkAll  = document.getElementById('chkAll');
    if (chkAll) {
        chkAll.checked       = dipilih.length === semua.length && semua.length > 0;
        chkAll.indeterminate = dipilih.length > 0 && dipilih.length < semua.length;
    }
}

/* ── Centang / lepas semua ── */
function toggleSemuaSiswa(val) {
    document.querySelectorAll('.chk-siswa').forEach(c => c.checked = val);
    updatePilihan();
}

/* ── Buka modal, isi daftar nama & hidden inputs ── */
function bukaPindahKelas() {
    const dipilih = [...document.querySelectorAll('.chk-siswa:checked')];
    if (!dipilih.length) return;

    // Isi daftar nama di modal
    const ul = document.getElementById('listNamaSiswa');
    ul.innerHTML = dipilih.map(c => {
        const nama = SISWA_NAMA[parseInt(c.value)] || 'Siswa #' + c.value;
        return `<li>${nama}</li>`;
    }).join('');

    // Isi hidden inputs
    const wrap = document.getElementById('hiddenSiswaIds');
    wrap.innerHTML = dipilih.map(c =>
        `<input type="hidden" name="siswa_ids[]" value="${parseInt(c.value)}">`
    ).join('');

    // Gunakan .open sesuai CSS modal-overlay (opacity + pointer-events, bukan display)
    document.getElementById('modalPindahKelas').classList.add('open');
    setTimeout(() => document.getElementById('selKelasTujuan').focus(), 80);
}

/* ── Tutup modal ── */
function tutupPindahKelas() {
    document.getElementById('modalPindahKelas').classList.remove('open');
}

/* ── Konfirmasi sebelum submit ── */
function konfirmasiPindah() {
    const sel  = document.getElementById('selKelasTujuan');
    const jml  = document.querySelectorAll('.chk-siswa:checked').length;
    const nama = sel.options[sel.selectedIndex]?.text || '';
    if (!sel.value) { alert('Pilih kelas tujuan terlebih dahulu.'); return false; }
    return confirm(`Pindahkan ${jml} siswa ke kelas "${nama}"?\n\nTindakan ini akan mengubah kelas siswa. Data nilai yang ada tetap tersimpan.`);
}

/* ── Keyboard: Esc menutup modal ── */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') tutupPindahKelas();
});
</script>
