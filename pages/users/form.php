<?php
/**
 * pages/users/form.php
 * Form tambah / edit pengguna — admin
 */
require_role('admin');

$page_action = $_GET['page'] ?? 'users.create';
$is_edit     = ($page_action === 'users.edit');
$id          = (int)($_GET['id'] ?? 0);
$user_data   = null;

if ($is_edit) {
    $user_data = db_row('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    if (!$user_data) { flash('error','Pengguna tidak ditemukan.'); header('Location: index.php?page=users'); exit; }
}

$errors = [];

// ── Handle Submit ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'Token tidak valid.'; }
    else {
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? 'siswa';
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';

        // Validasi
        if (!$nama)     $errors[] = 'Nama lengkap wajib diisi.';
        if (!$username) $errors[] = 'Username wajib diisi.';
        if (!$is_edit && !$password) $errors[] = 'Password wajib diisi saat membuat pengguna baru.';

        // Cek username duplikat
        $exist = db_row('SELECT id FROM users WHERE username = :u AND id != :id', [':u'=>$username, ':id'=>$id]);
        if ($exist) $errors[] = 'Username sudah digunakan.';

        if (empty($errors)) {
            if ($is_edit) {
                $sql = 'UPDATE users SET nama_lengkap=:n, username=:u, email=:e, role=:r, is_active=:a';
                $p   = [':n'=>$nama, ':u'=>$username, ':e'=>$email, ':r'=>$role, ':a'=>$active, ':id'=>$id];
                if ($password) { $sql .= ', password=:pw'; $p[':pw'] = password_hash($password, PASSWORD_BCRYPT); }
                $sql .= ' WHERE id=:id';
                db_execute($sql, $p);
                flash('success', 'Pengguna berhasil diperbarui.');
            } else {
                db_execute(
                    'INSERT INTO users (nama_lengkap, username, email, role, password, is_active) VALUES (:n,:u,:e,:r,:pw,:a)',
                    [':n'=>$nama, ':u'=>$username, ':e'=>$email, ':r'=>$role,
                     ':pw'=>password_hash($password, PASSWORD_BCRYPT), ':a'=>$active]
                );
                flash('success', 'Pengguna baru berhasil ditambahkan.');
            }
            header('Location: index.php?page=users'); exit;
        }
    }
}

$roles = ['admin'=>'Admin','kepala_sekolah'=>'Kepala Sekolah','wali_kelas'=>'Wali Kelas','guru'=>'Guru','siswa'=>'Siswa'];
$d = $user_data ?? [];
?>
<div class="page-content" style="max-width:680px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title"><?= $is_edit ? 'Edit Pengguna' : 'Tambah Pengguna' ?></h1>
      <p class="page-subtitle"><?= $is_edit ? 'Perbarui data pengguna' : 'Buat akun pengguna baru' ?></p>
    </div>
    <a href="index.php?page=users" class="btn btn-secondary">← Kembali</a>
  </div>

  <?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-header">Informasi Pengguna</div>
    <div class="card-body">
      <form method="POST" action="index.php?page=<?= $page_action ?><?= $is_edit ? '&id='.$id : '' ?>">
        <?= csrf_field() ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="form-group md:col-span-2">
            <label class="form-label">Nama Lengkap <span class="text-red-500">*</span></label>
            <input type="text" name="nama_lengkap" class="form-control" required
              value="<?= e($d['nama_lengkap'] ?? ($_POST['nama_lengkap'] ?? '')) ?>" placeholder="Masukkan nama lengkap">
          </div>

          <div class="form-group">
            <label class="form-label">Username <span class="text-red-500">*</span></label>
            <input type="text" name="username" class="form-control" required
              value="<?= e($d['username'] ?? ($_POST['username'] ?? '')) ?>" placeholder="Username unik">
          </div>

          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control"
              value="<?= e($d['email'] ?? ($_POST['email'] ?? '')) ?>" placeholder="email@sekolah.sch.id">
          </div>

          <div class="form-group">
            <label class="form-label">Password <?= $is_edit ? '(kosongkan jika tidak diubah)' : '<span class="text-red-500">*</span>' ?></label>
            <input type="password" name="password" class="form-control"
              placeholder="<?= $is_edit ? 'Biarkan kosong jika tidak ingin mengubah' : 'Password minimal 6 karakter' ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Role <span class="text-red-500">*</span></label>
            <select name="role" class="form-control">
              <?php foreach ($roles as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($d['role']??$_POST['role']??'siswa')===$k?'selected':'' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group md:col-span-2">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" name="is_active" value="1" <?= ($d['is_active']??1)?'checked':'' ?>
                class="w-4 h-4 accent-orange-500">
              <span class="form-label mb-0">Akun Aktif</span>
            </label>
          </div>
        </div>

        <div class="flex gap-3 mt-4">
          <button type="submit" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
              <polyline points="17 21 17 13 7 13 7 21"/>
              <polyline points="7 3 7 8 15 8"/>
            </svg>
            <?= $is_edit ? 'Simpan Perubahan' : 'Buat Pengguna' ?>
          </button>
          <a href="index.php?page=users" class="btn btn-secondary">Batal</a>
        </div>
      </form>
    </div>
  </div>
</div>
