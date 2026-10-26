<?php
/**
 * pages/siswa/index.php
 * Daftar siswa — admin
 */
require_role('admin');
require_once __DIR__ . '/../../excel.php';

// ── Export CSV (tanpa LIMIT — ambil semua sesuai filter) ────────
if (isset($_GET['export'])) {
    $rows = db_query(
        "SELECT u.nama_lengkap, u.username, u.email, s.nis, s.nisn,
                s.kelas_id, s.tempat_lahir, s.tanggal_lahir, s.nama_ayah, s.nama_ibu, s.alamat
         FROM users u
         INNER JOIN siswa s ON s.user_id = u.id
         WHERE u.role = 'siswa' AND u.is_active = 1
         ORDER BY s.kelas_id, u.nama_lengkap"
    );
    $out = [['nama_lengkap','username','email','nis','nisn','kelas_id',
             'tempat_lahir','tanggal_lahir','nama_ayah','nama_ibu','alamat']];
    foreach ($rows as $r) {
        $out[] = [$r['nama_lengkap'],$r['username'],$r['email'],$r['nis'],$r['nisn'],
                  $r['kelas_id'],$r['tempat_lahir'],$r['tanggal_lahir'],
                  $r['nama_ayah'],$r['nama_ibu'],$r['alamat']];
    }
    $headers = array_shift($out);
    csv_download('export_siswa_' . date('Ymd') . '.csv', $headers, $out);
}

// ── Handle DELETE ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    if (verify_csrf()) {
        db_execute('DELETE FROM users WHERE id = :id AND role = "siswa"', [':id'=>(int)$_POST['id']]);
        flash('success','Data siswa dihapus.');
    }
    header('Location: index.php?page=siswa'); exit;
}

$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_id    = (int)($_GET['ta'] ?? ($ta_aktif['id'] ?? 0));
$kelas_id = (int)($_GET['kelas'] ?? 0);
$search   = trim($_GET['q'] ?? '');

$ta_list    = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$kelas_list = db_query('SELECT * FROM kelas WHERE tahun_ajaran_id = :ta ORDER BY nama_kelas', [':ta'=>$ta_id]);

$where  = ['u.role = "siswa"', 'u.is_active = 1'];
$params = [];

if ($kelas_id) {
    $where[] = 's.kelas_id = :kid';
    $params[':kid'] = $kelas_id;
} else {
    // Hanya tampilkan siswa yang terdaftar di kelas pada tahun ajaran ini
    $where[] = 'k.tahun_ajaran_id = :ta';
    $params[':ta'] = $ta_id;
}
if ($search) {
    $where[] = '(u.nama_lengkap LIKE :q OR s.nis LIKE :q OR s.nisn LIKE :q)';
    $params[':q'] = "%$search%";
}
$whereStr = implode(' AND ', $where);

// ── Paginasi ────────────────────────────────────────────────────
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
    "SELECT u.id, u.nama_lengkap, u.username, u.email,
            s.id as siswa_id, s.nis, s.nisn, s.tanggal_lahir, s.tempat_lahir,
            k.nama_kelas, k.id as kelas_id
     FROM users u
     INNER JOIN siswa s ON s.user_id = u.id
     INNER JOIN kelas k ON k.id = s.kelas_id
     WHERE $whereStr ORDER BY k.nama_kelas, u.nama_lengkap
     LIMIT $per OFFSET $offset",
    $params
);

// Bangun query string untuk tautan paginasi (pertahankan semua filter)
$qstr = http_build_query(['page'=>'siswa','ta'=>$ta_id,'kelas'=>$kelas_id,'q'=>$search,'per'=>$per]);
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Data Siswa</h1>
            <p class="page-subtitle">Kelola data siswa sekolah</p>
        </div>
        <div class="flex gap-2">
            <a href="index.php?page=siswa&export=1" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                Export CSV
            </a>
            <a href="index.php?page=siswa.import" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="17 8 12 3 7 8" />
                    <line x1="12" y1="3" x2="12" y2="15" />
                </svg>
                Import CSV
            </a>
            <a href="index.php?page=siswa.create" class="btn btn-primary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Tambah Siswa
            </a>
        </div>
    </div>

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
                    <button type="submit" class="btn btn-primary w-full" style="border-radius: 30px">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Daftar Siswa</span>
            <span class="text-sm text-gray-500"><?= $total ?> siswa</span>
        </div>
        <div class="table-container">
            <table class="data-table" id="dataTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama Siswa</th>
                        <th>NIS</th>
                        <th>NISN</th>
                        <th>Kelas</th>
                        <th>Tempat, Tgl Lahir</th>
                        <th>Username</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($siswa_list)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-8 text-gray-400">Belum ada data siswa.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($siswa_list as $i => $s): ?>
                    <tr>
                        <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
                        <td class="font-semibold"><?= e($s['nama_lengkap']) ?></td>
                        <td><?= e($s['nis'] ?: '-') ?></td>
                        <td class="text-gray-400"><?= e($s['nisn'] ?: '-') ?></td>
                        <td><span class="badge badge-blue"><?= e($s['nama_kelas']) ?></span></td>
                        <td class="text-sm text-gray-500">
                            <?= e($s['tempat_lahir'] ?: '-') ?>,
                            <?= $s['tanggal_lahir'] ? date('d/m/Y', strtotime($s['tanggal_lahir'])) : '-' ?>
                        </td>
                        <td class="text-gray-500"><?= e($s['username']) ?></td>
                        <td>
                            <div class="flex gap-1">
                                <a href="index.php?page=siswa.edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-secondary"
                                    style="border-radius: 30px">Edit</a>
                                <form method="POST" action="index.php?page=siswa" style="display:inline"
                                    onsubmit="return confirm('Hapus siswa <?= e($s['nama_lengkap']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                        style="border-radius: 30px">Hapus</button>
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
        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100">
            <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages ?> (<?= $total ?> siswa)</span>
            <div class="pagination">
                <a href="?<?= $qstr ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>"
                    style="border-radius: 30px">‹
                    Prev</a>
                <?php
          // Tampilkan max 7 nomor halaman di tengah
          $start = max(1, $page_n - 3);
          $end   = min($pages, $page_n + 3);
          if ($start > 1): ?><a href="?<?= $qstr ?>&p=1" class="page-link">1</a><?php
            if ($start > 2): ?><span class="page-link disabled">…</span><?php endif;
          endif;
          for ($pi = $start; $pi <= $end; $pi++):
        ?>
                <a href="?<?= $qstr ?>&p=<?= $pi ?>" class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a>
                <?php endfor;
          if ($end < $pages):
            if ($end < $pages - 1): ?><span class="page-link disabled">…</span><?php endif;
            ?><a href="?<?= $qstr ?>&p=<?= $pages ?>" class="page-link"><?= $pages ?></a><?php
          endif;
        ?>
                <a href="?<?= $qstr ?>&p=<?= min($pages,$page_n+1) ?>"
                    class="page-link <?= $page_n>=$pages?'disabled':'' ?>" style="border-radius: 30px">Next ›</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>