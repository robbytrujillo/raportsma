<?php
/**
 * pages/tahun_ajaran/index.php
 * Kelola tahun ajaran â€" admin
 */
require_role(['admin','super_admin']);

// â"€â"€ Handle POST â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); header('Location: index.php?page=tahun_ajaran'); exit; }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $nama     = trim($_POST['nama'] ?? '');
        $semester = $_POST['semester'] ?? '1';
        $is_aktif = isset($_POST['is_aktif']) ? 1 : 0;
        $tid      = (int)($_POST['id'] ?? 0);

        if (!$nama) { flash('error','Nama tahun ajaran wajib diisi.'); header('Location: index.php?page=tahun_ajaran'); exit; }

        if ($is_aktif) {
            // Nonaktifkan semua dulu
            db_execute('UPDATE tahun_ajaran SET is_aktif = 0');
        }

        if ($tid) {
            db_execute('UPDATE tahun_ajaran SET nama=:n, semester=:s, is_aktif=:a WHERE id=:id',
                [':n'=>$nama, ':s'=>$semester, ':a'=>$is_aktif, ':id'=>$tid]);
            flash('success','Tahun ajaran diperbarui.');
        } else {
            db_execute('INSERT INTO tahun_ajaran (nama, semester, is_aktif) VALUES (:n,:s,:a)',
                [':n'=>$nama, ':s'=>$semester, ':a'=>$is_aktif]);
            flash('success','Tahun ajaran ditambahkan.');
        }
    } elseif ($action === 'set_aktif') {
        $tid = (int)$_POST['id'];
        db_execute('UPDATE tahun_ajaran SET is_aktif = 0');
        db_execute('UPDATE tahun_ajaran SET is_aktif = 1 WHERE id = :id', [':id'=>$tid]);
        flash('success','Tahun ajaran aktif diperbarui.');
    } elseif ($action === 'delete') {
        db_execute('DELETE FROM tahun_ajaran WHERE id = :id', [':id'=>(int)$_POST['id']]);
        flash('success','Tahun ajaran dihapus.');
    }
    header('Location: index.php?page=tahun_ajaran'); exit;
}

$ta_list = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
?>
<div class="page-content" style="max-width:700px">
  <div class="page-header">
    <div>
      <h1 class="page-title">Tahun Ajaran</h1>
      <p class="page-subtitle">Kelola tahun ajaran aktif</p>
    </div>
    <button onclick="Modal.open('modalTA')" class="btn btn-primary">+ Tambah</button>
  </div>

  <div class="card">
    <div class="card-header">Daftar Tahun Ajaran</div>
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr><th>#</th><th>Tahun Ajaran</th><th>Semester</th><th>Status</th><th>Aksi</th></tr>
        </thead>
        <tbody>
          <?php if (empty($ta_list)): ?>
          <tr><td colspan="5" class="text-center py-6 text-gray-400">Belum ada data.</td></tr>
          <?php else: ?>
          <?php foreach ($ta_list as $i => $ta): ?>
          <tr>
            <td class="text-gray-400 text-xs"><?= $i+1 ?></td>
            <td class="font-semibold"><?= e($ta['nama']) ?></td>
            <td><?= $ta['semester'] == 1 ? 'Ganjil (1)' : 'Genap (2)' ?></td>
            <td>
              <?php if ($ta['is_aktif']): ?>
                <span class="badge badge-green">Aktif</span>
              <?php else: ?>
                <form method="POST" action="index.php?page=tahun_ajaran" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_aktif">
                  <input type="hidden" name="id" value="<?= $ta['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-secondary">Jadikan Aktif</button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <div class="flex gap-1">
                <button onclick='editTA(<?= json_encode($ta) ?>)' class="btn btn-sm btn-secondary">Edit</button>
                <?php if (!$ta['is_aktif']): ?>
                <form method="POST" action="index.php?page=tahun_ajaran" style="display:inline"
                  onsubmit="return confirm('Hapus tahun ajaran ini?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $ta['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalTA">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <span id="modalTATitle">Tambah Tahun Ajaran</span>
      <button onclick="Modal.close('modalTA')" class="text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
    </div>
    <form method="POST" action="index.php?page=tahun_ajaran">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="taId" value="">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Tahun Ajaran <span class="text-red-500">*</span></label>
          <input type="text" name="nama" id="taNama" class="form-control" required placeholder="contoh: 2024/2025">
        </div>
        <div class="form-group">
          <label class="form-label">Semester</label>
          <select name="semester" id="taSemester" class="form-control">
            <option value="1">1 (Ganjil)</option>
            <option value="2">2 (Genap)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="is_aktif" value="1" id="taAktif" class="w-4 h-4 accent-orange-500">
            <span class="form-label mb-0">Jadikan Tahun Ajaran Aktif</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" onclick="Modal.close('modalTA')" class="btn btn-secondary">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>
<script>
function editTA(data) {
  document.getElementById('modalTATitle').textContent = 'Edit Tahun Ajaran';
  document.getElementById('taId').value = data.id;
  document.getElementById('taNama').value = data.nama;
  document.getElementById('taSemester').value = data.semester;
  document.getElementById('taAktif').checked = data.is_aktif == 1;
  Modal.open('modalTA');
}
</script>
