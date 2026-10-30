<?php
/**
 * pages/kelas/index.php + form.php combined â€" admin
 */
require_role(['admin','super_admin']);
require_once __DIR__ . '/../../excel.php';

// â"€â"€ Export CSV â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
if (isset($_GET['export'])) {
    $rows = db_query(
        'SELECT k.nama_kelas, k.tingkat, k.tahun_ajaran_id, k.wali_kelas_id,
                u.nama_lengkap as nama_wali, ta.nama as ta_nama
         FROM kelas k
         LEFT JOIN users u ON u.id = k.wali_kelas_id
         LEFT JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id
         ORDER BY ta.id DESC, k.tingkat, k.nama_kelas'
    );
    $headers = ['nama_kelas','tingkat','tahun_ajaran_id','wali_kelas_id','nama_wali','tahun_ajaran'];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [$r['nama_kelas'],$r['tingkat'],$r['tahun_ajaran_id'],
                  $r['wali_kelas_id'],$r['nama_wali'],$r['ta_nama']];
    }
    csv_download('export_kelas_' . date('Ymd') . '.csv', $headers, $out);
}

// â"€â"€ Handle ALL POST actions BEFORE any output â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); header('Location: index.php?page=kelas'); exit; }

    $action  = $_POST['action'] ?? '';
    $ta_back = (int)($_POST['tahun_ajaran_id'] ?? ($_GET['ta'] ?? 0));

    if ($action === 'delete') {
        db_execute('DELETE FROM kelas WHERE id = :id', [':id' => (int)$_POST['id']]);
        flash('success', 'Kelas berhasil dihapus.');
        header('Location: index.php?page=kelas&ta=' . $ta_back); exit;
    }

    if ($action === 'save') {
        $nama   = trim($_POST['nama_kelas'] ?? '');
        $tingkat= trim($_POST['tingkat'] ?? '');
        $wali   = ($_POST['wali_kelas_id'] ?? '') ?: null;
        $ta_id  = (int)($_POST['tahun_ajaran_id'] ?? 0);
        $kid    = (int)($_POST['id'] ?? 0);

        if ($nama && $tingkat && $ta_id) {
            if ($kid) {
                db_execute('UPDATE kelas SET nama_kelas=:n, tingkat=:t, wali_kelas_id=:w, tahun_ajaran_id=:ta WHERE id=:id',
                    [':n'=>$nama,':t'=>$tingkat,':w'=>$wali,':ta'=>$ta_id,':id'=>$kid]);
                flash('success','Kelas berhasil diperbarui.');
            } else {
                db_execute('INSERT INTO kelas (nama_kelas, tingkat, wali_kelas_id, tahun_ajaran_id) VALUES (:n,:t,:w,:ta)',
                    [':n'=>$nama,':t'=>$tingkat,':w'=>$wali,':ta'=>$ta_id]);
                flash('success','Kelas berhasil ditambahkan.');
            }
        } else {
            flash('error','Nama kelas, tingkat, dan tahun ajaran wajib diisi.');
        }
        header("Location: index.php?page=kelas&ta=$ta_id"); exit;
    }
}

// Ambil daftar tahun ajaran dan guru yang bisa jadi wali kelas
$ta_list    = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$wali_list  = db_query("SELECT u.id, u.nama_lengkap FROM users u WHERE u.role IN ('wali_kelas','guru') ORDER BY u.nama_lengkap");
$ta_aktif   = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_filter  = (int)($_GET['ta'] ?? ($ta_aktif['id'] ?? 0));

// â"€â"€ Paginasi â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
$per_opts = [10, 25, 50, 100];
$per      = in_array((int)($_GET['per'] ?? 0), $per_opts) ? (int)$_GET['per'] : 10;
$page_n   = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page_n - 1) * $per;

$total = (int)(db_row(
    'SELECT COUNT(*) as n FROM kelas k WHERE k.tahun_ajaran_id = :ta',
    [':ta' => $ta_filter]
)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
if ($page_n > $pages) $page_n = $pages;
$offset = ($page_n - 1) * $per;

$kelas_list = db_query(
    'SELECT k.*, u.nama_lengkap as nama_wali, ta.nama as ta_nama, ta.semester
     FROM kelas k
     LEFT JOIN users u ON u.id = k.wali_kelas_id
     LEFT JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id
     WHERE k.tahun_ajaran_id = :ta ORDER BY k.tingkat, k.nama_kelas
     LIMIT ' . $per . ' OFFSET ' . $offset,
    [':ta' => $ta_filter]
);
$qstr_k = http_build_query(['page'=>'kelas','ta'=>$ta_filter,'per'=>$per]);
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Data Kelas</h1>
            <p class="page-subtitle">Kelola kelas per tahun ajaran</p>
        </div>
        <div class="flex gap-2">
            <a href="index.php?page=kelas&export=1" class="btn btn-secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                Export CSV
            </a>
            <a href="index.php?page=kelas.import" class="btn btn-secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="17 8 12 3 7 8" />
                    <line x1="12" y1="3" x2="12" y2="15" />
                </svg>
                Import CSV
            </a>
            <button onclick="Modal.open('modalKelas')" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Tambah Kelas
            </button>
        </div>
    </div>

    <!-- Filter Tahun Ajaran + Per-page -->
    <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
        <div class="flex gap-2 flex-wrap">
            <?php foreach ($ta_list as $ta): ?>
            <a href="index.php?page=kelas&ta=<?= $ta['id'] ?>&per=<?= $per ?>"
                class="badge text-sm py-1 px-3 <?= $ta['id']==$ta_filter ? 'badge-orange' : 'badge-gray' ?>"
                style="text-decoration:none;cursor:pointer">
                <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                <?= $ta['is_aktif'] ? ' ....' : '' ?>
            </a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="index.php" class="flex items-center gap-2">
            <input type="hidden" name="page" value="kelas">
            <input type="hidden" name="ta" value="<?= $ta_filter ?>">
            <label class="text-sm text-gray-500">Tampil:</label>
            <select name="per" class="form-control" style="width:90px" onchange="this.form.submit()">
                <?php foreach ([10,25,50,100] as $opt): ?>
                <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?>/hal</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Daftar Kelas</span>
            <span class="text-sm text-gray-500"><?= $total ?> kelas</span>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama Kelas</th>
                        <th>Tingkat</th>
                        <th>Wali Kelas</th>
                        <th>Tahun Ajaran</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kelas_list)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-8 text-gray-400">Belum ada kelas untuk tahun ajaran ini.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($kelas_list as $i => $k): ?>
                    <tr>
                        <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
                        <td class="font-semibold"><?= e($k['nama_kelas']) ?></td>
                        <td><span class="badge badge-blue"><?= e($k['tingkat']) ?></span></td>
                        <td><?= e($k['nama_wali'] ?: '-') ?></td>
                        <td class="text-gray-500 text-sm"><?= e($k['ta_nama']) ?> Sem.<?= $k['semester'] ?></td>
                        <td>
                            <div class="flex gap-1">
                                <button onclick='editKelas(<?= json_encode($k) ?>)'
                                    class="btn btn-sm btn-secondary">Edit</button>
                                <form method="POST" action="index.php?page=kelas" style="display:inline"
                                    onsubmit="return confirm('Hapus kelas <?= e($k['nama_kelas']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
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
            <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages ?> (<?= $total ?> kelas)</span>
            <div class="pagination">
                <a href="?<?= $qstr_k ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>">
                    < Prev</a>
                        <?php
          $start = max(1, $page_n - 3); $end = min($pages, $page_n + 3);
          if ($start > 1): ?><a href="?<?= $qstr_k ?>&p=1" class="page-link">1</a><?php
            if ($start > 2): ?><span class="page-link disabled">â€¦</span><?php endif;
          endif;
          for ($pi = $start; $pi <= $end; $pi++):
        ?><a href="?<?= $qstr_k ?>&p=<?= $pi ?>" class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a><?php
          endfor;
          if ($end < $pages):
            if ($end < $pages - 1): ?><span class="page-link disabled">â€¦</span><?php endif;
            ?><a href="?<?= $qstr_k ?>&p=<?= $pages ?>" class="page-link"><?= $pages ?></a><?php
          endif;
        ?>
                        <a href="?<?= $qstr_k ?>&p=<?= min($pages,$page_n+1) ?>"
                            class="page-link <?= $page_n>=$pages?'disabled':'' ?>">Next ></a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Tambah/Edit Kelas -->
<div class="modal-overlay" id="modalKelas">
    <div class="modal">
        <div class="modal-header">
            <span id="modalKelasTitle">Tambah Kelas</span>
            <button onclick="Modal.close('modalKelas')"
                class="text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="index.php?page=kelas&ta=<?= $ta_filter ?>" id="formKelas">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="kelasId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Kelas <span class="text-red-500">*</span></label>
                    <input type="text" name="nama_kelas" id="kelasNama" class="form-control" required
                        placeholder="contoh: X IPA 1">
                </div>
                <div class="form-group">
                    <label class="form-label">Tingkat <span class="text-red-500">*</span></label>
                    <select name="tingkat" id="kelasTingkat" class="form-control" required>
                        <option value="X">X</option>
                        <option value="XI">XI</option>
                        <option value="XII">XII</option>
                        <option value="VII">VII</option>
                        <option value="VIII">VIII</option>
                        <option value="IX">IX</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Wali Kelas</label>
                    <select name="wali_kelas_id" id="kelasWali" class="form-control">
                        <option value="">-- Pilih Wali Kelas --</option>
                        <?php foreach ($wali_list as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= e($w['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tahun Ajaran <span class="text-red-500">*</span></label>
                    <select name="tahun_ajaran_id" id="kelasTa" class="form-control" required>
                        <?php foreach ($ta_list as $ta): ?>
                        <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_filter?'selected':'' ?>>
                            <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Modal.close('modalKelas')" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editKelas(data) {
    document.getElementById('modalKelasTitle').textContent = 'Edit Kelas';
    document.getElementById('kelasId').value = data.id;
    document.getElementById('kelasNama').value = data.nama_kelas;
    document.getElementById('kelasTingkat').value = data.tingkat;
    document.getElementById('kelasWali').value = data.wali_kelas_id || '';
    document.getElementById('kelasTa').value = data.tahun_ajaran_id;
    Modal.open('modalKelas');
}
</script>