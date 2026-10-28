<?php
/**
 * pages/penugasan/index.php
 * Penugasan Guru ke Mata Pelajaran & Kelas — admin
 */
require_role('admin');

// ── Handle POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); header('Location: index.php?page=penugasan'); exit; }

    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $guru_uid  = (int)($_POST['guru_user_id'] ?? 0);
        $mapel_id  = (int)($_POST['mapel_id'] ?? 0);
        $kelas_id  = (int)($_POST['kelas_id'] ?? 0);
        $ta_id     = (int)($_POST['tahun_ajaran_id'] ?? 0);

        if ($guru_uid && $mapel_id && $kelas_id && $ta_id) {
            try {
                db_execute(
                    'INSERT INTO guru_mapel_kelas (guru_user_id, mapel_id, kelas_id, tahun_ajaran_id) VALUES (:g,:m,:k,:ta)',
                    [':g'=>$guru_uid, ':m'=>$mapel_id, ':k'=>$kelas_id, ':ta'=>$ta_id]
                );
                flash('success','Penugasan berhasil ditambahkan.');
            } catch (\PDOException $e) {
                flash('error','Penugasan sudah ada atau data tidak valid.');
            }
        } else {
            flash('error','Semua field wajib diisi.');
        }
    } elseif ($action === 'delete') {
        db_execute('DELETE FROM guru_mapel_kelas WHERE id = :id', [':id'=>(int)$_POST['id']]);
        flash('success','Penugasan dihapus.');
    }
    header('Location: index.php?page=penugasan'); exit;
}

$ta_aktif   = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_id      = (int)($_GET['ta'] ?? ($ta_aktif['id'] ?? 0));
$search_p   = trim($_GET['q'] ?? '');

$guru_list  = db_query("SELECT u.id, u.nama_lengkap FROM users u WHERE u.role IN ('guru','wali_kelas') ORDER BY u.nama_lengkap");
$mapel_list = db_query('SELECT * FROM mapel ORDER BY nama_mapel');
$kelas_list = db_query('SELECT * FROM kelas WHERE tahun_ajaran_id = :ta ORDER BY nama_kelas', [':ta'=>$ta_id]);
$ta_list    = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');

// ── Build where untuk filter ─────────────────────────────────────
$where_p  = 'gmk.tahun_ajaran_id = :ta';
$params_p = [':ta' => $ta_id];
if ($search_p) {
    $where_p           .= ' AND (u.nama_lengkap LIKE :q OR m.nama_mapel LIKE :q OR k.nama_kelas LIKE :q)';
    $params_p[':q']     = "%$search_p%";
}

// ── Paginasi ────────────────────────────────────────────────────
$per_opts = [10, 25, 50, 100];
$per      = in_array((int)($_GET['per'] ?? 0), $per_opts) ? (int)$_GET['per'] : 10;
$page_n   = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page_n - 1) * $per;

$total_p = (int)(db_row(
    "SELECT COUNT(*) as n
     FROM guru_mapel_kelas gmk
     LEFT JOIN users u ON u.id = gmk.guru_user_id
     LEFT JOIN mapel m ON m.id = gmk.mapel_id
     LEFT JOIN kelas k ON k.id = gmk.kelas_id
     WHERE $where_p",
    $params_p
)['n'] ?? 0);
$pages_p = max(1, (int)ceil($total_p / $per));
if ($page_n > $pages_p) $page_n = $pages_p;
$offset  = ($page_n - 1) * $per;

$penugasan = db_query(
    "SELECT gmk.*, u.nama_lengkap as nama_guru, m.nama_mapel, k.nama_kelas
     FROM guru_mapel_kelas gmk
     LEFT JOIN users u ON u.id = gmk.guru_user_id
     LEFT JOIN mapel m ON m.id = gmk.mapel_id
     LEFT JOIN kelas k ON k.id = gmk.kelas_id
     WHERE $where_p
     ORDER BY u.nama_lengkap, m.nama_mapel
     LIMIT $per OFFSET $offset",
    $params_p
);
$qstr_p = http_build_query(['page'=>'penugasan','ta'=>$ta_id,'q'=>$search_p,'per'=>$per]);
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Penugasan Guru</h1>
            <p class="page-subtitle">Atur guru mengajar mata pelajaran di kelas tertentu</p>
        </div>
        <button onclick="Modal.open('modalPenugasan')" class="btn btn-primary" style="border-radius: 30px">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="12" y1="5" x2="12" y2="19" />
                <line x1="5" y1="12" x2="19" y2="12" />
            </svg>
            Tambah Penugasan
        </button>
    </div>

    <!-- Filter TA -->
    <!-- Filter TA + Search + Per-page -->
    <div class="flex items-start justify-between flex-wrap gap-3 mb-4">
        <div class="flex gap-2 flex-wrap">
            <?php foreach ($ta_list as $ta): ?>
            <a href="index.php?page=penugasan&ta=<?= $ta['id'] ?>&per=<?= $per ?>"
                class="badge text-sm py-1 px-3 <?= $ta['id']==$ta_id ? 'badge-orange' : 'badge-gray' ?>"
                style="text-decoration:none;cursor:pointer">
                <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="index.php" class="flex gap-2 flex-wrap items-end">
                <input type="hidden" name="page" value="penugasan">
                <input type="hidden" name="ta" value="<?= $ta_id ?>">
                <div>
                    <label class="form-label">Cari</label>
                    <input type="text" name="q" placeholder="Guru / mapel / kelas..." value="<?= e($search_p) ?>"
                        class="form-control" style="max-width:240px">
                </div>
                <div>
                    <label class="form-label">Tampil</label>
                    <select name="per" class="form-control" style="width:90px" onchange="this.form.submit()">
                        <?php foreach ([10,25,50,100] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?>/hal</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm" style="border-radius: 30px">Cari</button>
                    <?php if ($search_p || $per !== 10): ?><a href="index.php?page=penugasan&ta=<?= $ta_id ?>"
                        class="btn btn-secondary btn-sm">Reset</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Daftar Penugasan</span>
            <span class="text-sm text-gray-500"><?= $total_p ?> penugasan</span>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Guru</th>
                        <th>Mata Pelajaran</th>
                        <th>Kelas</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($penugasan)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-8 text-gray-400">Belum ada penugasan untuk tahun ajaran
                            ini.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($penugasan as $i => $p): ?>
                    <tr>
                        <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
                        <td class="font-semibold"><?= e($p['nama_guru']) ?></td>
                        <td><?= e($p['nama_mapel']) ?></td>
                        <td><span class="badge badge-blue"><?= e($p['nama_kelas']) ?></span></td>
                        <td>
                            <form method="POST" action="index.php?page=penugasan&ta=<?= $ta_id ?>"
                                style="display:inline" onsubmit="return confirm('Hapus penugasan ini?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    style="border-radius: 30px">Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginasi -->
        <?php if ($pages_p > 1): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100">
            <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages_p ?> (<?= $total_p ?>
                penugasan)</span>
            <div class="pagination">
                <a href="?<?= $qstr_p ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>">‹
                    Prev</a>
                <?php
          $start = max(1, $page_n - 3); $end = min($pages_p, $page_n + 3);
          if ($start > 1): ?><a href="?<?= $qstr_p ?>&p=1" class="page-link">1</a><?php
            if ($start > 2): ?><span class="page-link disabled">…</span><?php endif;
          endif;
          for ($pi = $start; $pi <= $end; $pi++):
        ?><a href="?<?= $qstr_p ?>&p=<?= $pi ?>" class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a><?php
          endfor;
          if ($end < $pages_p):
            if ($end < $pages_p - 1): ?><span class="page-link disabled">…</span><?php endif;
            ?><a href="?<?= $qstr_p ?>&p=<?= $pages_p ?>" class="page-link"><?= $pages_p ?></a><?php
          endif;
        ?>
                <a href="?<?= $qstr_p ?>&p=<?= min($pages_p,$page_n+1) ?>"
                    class="page-link <?= $page_n>=$pages_p?'disabled':'' ?>">Next ›</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Tambah Penugasan -->
<div class="modal-overlay" id="modalPenugasan">
    <div class="modal">
        <div class="modal-header">
            <span>Tambah Penugasan Guru</span>
            <button onclick="Modal.close('modalPenugasan')"
                class="text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="index.php?page=penugasan&ta=<?= $ta_id ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Guru <span class="text-red-500">*</span></label>
                    <select name="guru_user_id" class="form-control" required>
                        <option value="">— Pilih Guru —</option>
                        <?php foreach ($guru_list as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= e($g['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Mata Pelajaran <span class="text-red-500">*</span></label>
                    <select name="mapel_id" class="form-control" required>
                        <option value="">— Pilih Mata Pelajaran —</option>
                        <?php foreach ($mapel_list as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= e($m['nama_mapel']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Kelas <span class="text-red-500">*</span></label>
                    <select name="kelas_id" class="form-control" required>
                        <option value="">— Pilih Kelas —</option>
                        <?php foreach ($kelas_list as $k): ?>
                        <option value="<?= $k['id'] ?>"><?= e($k['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tahun Ajaran <span class="text-red-500">*</span></label>
                    <select name="tahun_ajaran_id" class="form-control" required>
                        <?php foreach ($ta_list as $ta): ?>
                        <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_id?'selected':'' ?>>
                            <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Modal.close('modalPenugasan')" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>