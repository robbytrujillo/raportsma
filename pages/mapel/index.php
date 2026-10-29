<?php
/**
 * pages/mapel/index.php
 * Daftar Mata Pelajaran â€" admin
 */
require_role(['admin','super_admin']);
require_once __DIR__ . '/../../excel.php';

// â"€â"€ Export CSV â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
if (isset($_GET['export'])) {
    $rows = db_query('SELECT nama_mapel, kode_mapel, kelompok, parent_id, sub_label, urutan FROM mapel ORDER BY kelompok, urutan, nama_mapel');
    $headers = ['nama_mapel','kode_mapel','kelompok','parent_id','sub_label','urutan'];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [$r['nama_mapel'],$r['kode_mapel'],$r['kelompok'],
                  $r['parent_id'],$r['sub_label'],$r['urutan']];
    }
    csv_download('export_mapel_' . date('Ymd') . '.csv', $headers, $out);
}

// â"€â"€ Handle POST â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); header('Location: index.php?page=mapel'); exit; }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $nama      = trim($_POST['nama_mapel'] ?? '');
        $kode      = trim($_POST['kode_mapel'] ?? '');
        $kelompok  = $_POST['kelompok'] ?? 'A';
        $parent_id = ($_POST['parent_id'] ?? '') ?: null;
        $sub_label = trim($_POST['sub_label'] ?? '') ?: null;
        $urutan    = (int)($_POST['urutan'] ?? 0);
        $mid       = (int)($_POST['id'] ?? 0);
        if ($nama) {
            if ($mid) {
                db_execute('UPDATE mapel SET nama_mapel=:n, kode_mapel=:k, kelompok=:g, parent_id=:pid, sub_label=:sl, urutan=:u WHERE id=:id',
                    [':n'=>$nama, ':k'=>$kode, ':g'=>$kelompok, ':pid'=>$parent_id, ':sl'=>$sub_label, ':u'=>$urutan, ':id'=>$mid]);
                flash('success','Mata pelajaran diperbarui.');
            } else {
                db_execute('INSERT INTO mapel (nama_mapel, kode_mapel, kelompok, parent_id, sub_label, urutan) VALUES (:n,:k,:g,:pid,:sl,:u)',
                    [':n'=>$nama, ':k'=>$kode, ':g'=>$kelompok, ':pid'=>$parent_id, ':sl'=>$sub_label, ':u'=>$urutan]);
                flash('success','Mata pelajaran ditambahkan.');
            }
        }
    } elseif ($action === 'delete') {
        db_execute('DELETE FROM mapel WHERE id = :id', [':id'=>(int)$_POST['id']]);
        flash('success','Mata pelajaran dihapus.');
    }
    header('Location: index.php?page=mapel'); exit;
}

// â"€â"€ Paginasi â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
$search_m = trim($_GET['q'] ?? '');
$per_opts  = [10, 25, 50, 100];
$per      = in_array((int)($_GET['per'] ?? 0), $per_opts) ? (int)$_GET['per'] : 10;
$page_n   = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page_n - 1) * $per;

$where_m  = '1=1';
$params_m = [];
if ($search_m) {
    $where_m       = '(nama_mapel LIKE :q OR kode_mapel LIKE :q)';
    $params_m[':q'] = "%$search_m%";
}

$total_m = (int)(db_row("SELECT COUNT(*) as n FROM mapel WHERE $where_m", $params_m)['n'] ?? 0);
$pages_m = max(1, (int)ceil($total_m / $per));
// Jika page_n melebihi jumlah halaman, reset ke halaman terakhir
if ($page_n > $pages_m) $page_n = $pages_m;
$offset  = ($page_n - 1) * $per;

$mapel_list  = db_query(
    "SELECT * FROM mapel WHERE $where_m ORDER BY kelompok, urutan, nama_mapel LIMIT $per OFFSET $offset",
    $params_m
);
$mapel_induk   = db_query("SELECT * FROM mapel WHERE parent_id IS NULL ORDER BY kelompok, urutan, nama_mapel");
$kelompok_opts = ['A','B','C','C Pilihan','D','Muatan Lokal'];
$qstr_m = http_build_query(['page'=>'mapel','q'=>$search_m,'per'=>$per]);
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Mata Pelajaran</h1>
            <p class="page-subtitle">Kelola daftar mata pelajaran</p>
        </div>
        <div class="flex gap-2">
            <a href="index.php?page=mapel&export=1" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                Export CSV
            </a>
            <a href="index.php?page=mapel.import" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="17 8 12 3 7 8" />
                    <line x1="12" y1="3" x2="12" y2="15" />
                </svg>
                Import CSV
            </a>
            <button onclick="Modal.open('modalMapel')" class="btn btn-primary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Tambah Mapel
            </button>
        </div>
    </div>

    <!-- Search Bar + Per-page selector -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="index.php" class="flex gap-2 flex-wrap items-end">
                <input type="hidden" name="page" value="mapel">
                <div>
                    <label class="form-label">Cari Mapel</label>
                    <input type="text" name="q" placeholder="Nama / kode mapel..." value="<?= e($search_m) ?>"
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
                    <?php if ($search_m || $per !== 10): ?>
                    <a href="index.php?page=mapel" class="btn btn-secondary btn-sm">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Daftar Mata Pelajaran</span>
            <span class="text-sm text-gray-500"><?= $total_m ?> mapel</span>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Kode</th>
                        <th>Nama Mata Pelajaran</th>
                        <th>Sub-label</th>
                        <th>Induk</th>
                        <th>Kelompok</th>
                        <th>Urutan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mapel_list)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-8 text-gray-400">Belum ada mata pelajaran.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($mapel_list as $i => $m): ?>
                    <?php
            $i_abs = $offset + $i; // nomor baris global
            // Cari nama induk
            $nama_induk = '-';
            if ($m['parent_id']) {
                foreach ($mapel_induk as $mi) {
                    if ($mi['id'] == $m['parent_id']) { $nama_induk = $mi['nama_mapel']; break; }
                }
            }
          ?>
                    <tr <?= $m['parent_id'] ? 'style="background:#fffbf5"' : '' ?>>
                        <td class="text-gray-400 text-xs"><?= $i_abs + 1 ?></td>
                        <td><span class="badge badge-gray"><?= e($m['kode_mapel'] ?: '-') ?></span></td>
                        <td class="<?= $m['parent_id'] ? 'pl-6' : 'font-semibold' ?>"
                            style="<?= $m['parent_id'] ? 'padding-left:24px' : '' ?>">
                            <?= $m['parent_id'] ? 'â†³ ' : '' ?><?= e($m['nama_mapel']) ?>
                        </td>
                        <td class="text-center"><?= e($m['sub_label'] ?: '-') ?></td>
                        <td class="text-xs text-gray-500"><?= e($nama_induk) ?></td>
                        <td>
                            <?php $kls=['A'=>'badge-blue','B'=>'badge-orange','C'=>'badge-green','C Pilihan'=>'badge-teal','D'=>'badge-red','Muatan Lokal'=>'badge-purple'];?>
                            <span class="badge <?= $kls[$m['kelompok']] ?? 'badge-gray' ?>">Kel.
                                <?= e($m['kelompok']) ?></span>
                        </td>
                        <td class="text-center text-gray-400"><?= $m['urutan'] ?: '-' ?></td>
                        <td>
                            <div class="flex gap-1">
                                <button onclick='editMapel(<?= json_encode($m) ?>)' class="btn btn-sm btn-secondary"
                                    style="border-radius: 30px">Edit</button>
                                <form method="POST" action="index.php?page=mapel" style="display:inline"
                                    onsubmit="return confirm('Hapus mata pelajaran ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
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
        <?php if ($pages_m > 1): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100">
            <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages_m ?> (<?= $total_m ?>
                mapel)</span>
            <div class="pagination">
                <a href="?<?= $qstr_m ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>"
                    style="border-radius: 30px">â€¹ Prev</a>
                <?php
          $start = max(1, $page_n - 3); $end = min($pages_m, $page_n + 3);
          if ($start > 1): ?><a href="?<?= $qstr_m ?>&p=1" class="page-link">1</a><?php
            if ($start > 2): ?><span class="page-link disabled">â€¦</span><?php endif;
          endif;
          for ($pi = $start; $pi <= $end; $pi++):
        ?><a href="?<?= $qstr_m ?>&p=<?= $pi ?>" class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a><?php
          endfor;
          if ($end < $pages_m):
            if ($end < $pages_m - 1): ?><span class="page-link disabled">â€¦</span><?php endif;
            ?><a href="?<?= $qstr_m ?>&p=<?= $pages_m ?>" class="page-link"><?= $pages_m ?></a><?php
          endif;
        ?>
                <a href="?<?= $qstr_m ?>&p=<?= min($pages_m,$page_n+1) ?>"
                    class="page-link <?= $page_n>=$pages_m?'disabled':'' ?>" style="border-radius: 30px">Next â€º</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalMapel">
    <div class="modal">
        <div class="modal-header">
            <span id="modalMapelTitle">Tambah Mata Pelajaran</span>
            <button onclick="Modal.close('modalMapel')" class="text-gray-400 hover:text-gray-700 text-2xl leading-none"
                style="border-radius: 30px">&times;</button>
        </div>
        <form method="POST" action="index.php?page=mapel">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="mapelId" value="">
            <div class="modal-body">
                <div class="grid grid-cols-2 gap-3">
                    <div class="form-group">
                        <label class="form-label">Kode Mapel</label>
                        <input type="text" name="kode_mapel" id="mapelKode" class="form-control"
                            placeholder="MTK, BIN, IPA...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kelompok</label>
                        <select name="kelompok" id="mapelKelompok" class="form-control">
                            <?php foreach ($kelompok_opts as $kg): ?>
                            <option value="<?= $kg ?>"><?= $kg ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Mata Pelajaran <span class="text-red-500">*</span></label>
                    <input type="text" name="nama_mapel" id="mapelNama" class="form-control" required
                        placeholder="Nama lengkap mapel">
                </div>
                <div class="form-group">
                    <label class="form-label">Mapel Induk <span class="text-xs text-gray-400">(isi jika sub-mapel, mis.
                            sub dari Pendidikan Agama Islam)</span></label>
                    <select name="parent_id" id="mapelParent" class="form-control">
                        <option value="">â€" Mapel Mandiri (tidak ada induk) â€"</option>
                        <?php foreach ($mapel_induk as $mi): ?>
                        <option value="<?= $mi['id'] ?>">[Kel.<?= e($mi['kelompok']) ?>] <?= e($mi['nama_mapel']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="form-group">
                        <label class="form-label">Sub-label <span class="text-xs text-gray-400">(a, b, c,
                                ...)</span></label>
                        <input type="text" name="sub_label" id="mapelSubLabel" class="form-control" maxlength="3"
                            placeholder="a">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Urutan tampil</label>
                        <input type="number" name="urutan" id="mapelUrutan" class="form-control" min="0" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Modal.close('modalMapel')" class="btn btn-secondary"
                    style="border-radius: 30px">Batal</button>
                <button type="submit" class="btn btn-primary" style="border-radius: 30px">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editMapel(data) {
    document.getElementById('modalMapelTitle').textContent = 'Edit Mata Pelajaran';
    document.getElementById('mapelId').value = data.id;
    document.getElementById('mapelKode').value = data.kode_mapel || '';
    document.getElementById('mapelNama').value = data.nama_mapel;
    document.getElementById('mapelKelompok').value = data.kelompok;
    document.getElementById('mapelParent').value = data.parent_id || '';
    document.getElementById('mapelSubLabel').value = data.sub_label || '';
    document.getElementById('mapelUrutan').value = data.urutan || 0;
    Modal.open('modalMapel');
}
</script>