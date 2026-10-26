<?php
/**
 * pages/guru/form.php
 * Form tambah / edit guru — admin
 */
require_role('admin');

$page_action = $_GET['page'] ?? 'guru.create';
$is_edit     = ($page_action === 'guru.edit');
$id          = (int)($_GET['id'] ?? 0); // user id

$user_data = null;
$guru_data = null;

if ($is_edit) {
    $user_data = db_row('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    $guru_data = db_row('SELECT * FROM guru WHERE user_id = :id', [':id' => $id]);
    if (!$user_data) { flash('error','Guru tidak ditemukan.'); header('Location: index.php?page=guru'); exit; }
}

$errors = [];

// ── Handle Submit ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'Token tidak valid.'; }
    else {
        $nama         = trim($_POST['nama_lengkap'] ?? '');
        $username     = trim($_POST['username'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $nip          = trim($_POST['nip'] ?? '');
        $status       = $_POST['status'] ?? 'guru';
        $bidang_studi = trim($_POST['bidang_studi'] ?? '');
        $password     = $_POST['password'] ?? '';
        $role_val     = ($status === 'wali_kelas') ? 'wali_kelas' : 'guru';

        if (!$nama)     $errors[] = 'Nama lengkap wajib diisi.';
        if (!$username) $errors[] = 'Username wajib diisi.';
        if (!$is_edit && !$password) $errors[] = 'Password wajib diisi.';

        $exist = db_row('SELECT id FROM users WHERE username = :u AND id != :id', [':u'=>$username, ':id'=>$id]);
        if ($exist) $errors[] = 'Username sudah digunakan.';

        if (empty($errors)) {
            if ($is_edit) {
                // Update users
                $sql = 'UPDATE users SET nama_lengkap=:n, username=:u, email=:e, role=:r';
                $p   = [':n'=>$nama, ':u'=>$username, ':e'=>$email, ':r'=>$role_val, ':id'=>$id];
                if ($password) { $sql .= ', password=:pw'; $p[':pw'] = password_hash($password, PASSWORD_BCRYPT); }
                $sql .= ' WHERE id=:id';
                db_execute($sql, $p);

                // Update atau insert guru
                if ($guru_data) {
                    db_execute(
                        'UPDATE guru SET nip=:nip, status=:s, bidang_studi=:bs WHERE user_id=:uid',
                        [':nip'=>$nip, ':s'=>$status, ':bs'=>$bidang_studi, ':uid'=>$id]
                    );
                } else {
                    db_execute(
                        'INSERT INTO guru (user_id, nip, status, bidang_studi) VALUES (:uid,:nip,:s,:bs)',
                        [':uid'=>$id, ':nip'=>$nip, ':s'=>$status, ':bs'=>$bidang_studi]
                    );
                }
                flash('success', 'Data guru berhasil diperbarui.');
            } else {
                $uid = db_execute(
                    'INSERT INTO users (nama_lengkap, username, email, role, password) VALUES (:n,:u,:e,:r,:pw)',
                    [':n'=>$nama, ':u'=>$username, ':e'=>$email, ':r'=>$role_val,
                     ':pw'=>password_hash($password, PASSWORD_BCRYPT)]
                );
                db_execute(
                    'INSERT INTO guru (user_id, nip, status, bidang_studi) VALUES (:uid,:nip,:s,:bs)',
                    [':uid'=>$uid, ':nip'=>$nip, ':s'=>$status, ':bs'=>$bidang_studi]
                );
                flash('success', 'Guru baru berhasil ditambahkan.');
            }
            header('Location: index.php?page=guru'); exit;
        }
    }
}

$d  = $user_data ?? [];
$dg = $guru_data ?? [];
?>
<div class="page-content" style="max-width:680px;margin:0 auto">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= $is_edit ? 'Edit Guru' : 'Tambah Guru' ?></h1>
            <p class="page-subtitle"><?= $is_edit ? 'Perbarui data guru' : 'Daftarkan guru baru ke sistem' ?></p>
        </div>
        <a href="index.php?page=guru" class="btn btn-secondary">← Kembali</a>
    </div>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <div class="card">
        <div class="card-header">Data Guru</div>
        <div class="card-body">
            <form method="POST" action="index.php?page=<?= $page_action ?><?= $is_edit ? '&id='.$id : '' ?>">
                <?= csrf_field() ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group md:col-span-2">
                        <label class="form-label">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="nama_lengkap" class="form-control" required
                            value="<?= e($d['nama_lengkap'] ?? '') ?>" placeholder="Nama lengkap guru">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username <span class="text-red-500">*</span></label>
                        <input type="text" name="username" class="form-control" required
                            value="<?= e($d['username'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= e($d['email'] ?? '') ?>"
                            placeholder="email@sekolah.sch.id">
                    </div>

                    <div class="form-group">
                        <label class="form-label">NIP</label>
                        <input type="text" name="nip" class="form-control" value="<?= e($dg['nip'] ?? '') ?>"
                            placeholder="Nomor Induk Pegawai">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Bidang Studi</label>
                        <input type="text" name="bidang_studi" class="form-control"
                            value="<?= e($dg['bidang_studi'] ?? '') ?>"
                            placeholder="Contoh: Matematika, IPA, Bahasa Indonesia">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="guru" <?= ($dg['status']??'guru')==='guru'?'selected':'' ?>>Guru</option>
                            <option value="wali_kelas" <?= ($dg['status']??'')==='wali_kelas'?'selected':'' ?>>Wali
                                Kelas</option>
                            <option value="koordinator" <?= ($dg['status']??'')==='koordinator'?'selected':'' ?>>
                                Koordinator</option>
                        </select>
                    </div>

                    <div class="form-group md:col-span-2">
                        <label class="form-label">Password
                            <?= $is_edit ? '(kosongkan jika tidak diubah)' : '<span class="text-red-500">*</span>' ?></label>
                        <input type="password" name="password" class="form-control"
                            placeholder="<?= $is_edit ? 'Biarkan kosong jika tidak mengubah password' : 'Password minimal 6 karakter' ?>">
                    </div>
                </div>

                <div class="flex gap-3 mt-4">
                    <button type="submit" class="btn btn-primary" style="border-radius: 30px;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
                            viewBox="0 0 24 24">
                            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                            <polyline points="17 21 17 13 7 13 7 21" />
                        </svg>
                        <?= $is_edit ? 'Simpan' : 'Tambah Guru' ?>
                    </button>
                    <a href="index.php?page=guru" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
</div>