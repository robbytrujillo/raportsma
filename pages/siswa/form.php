<?php
/**
 * pages/siswa/form.php
 * Form tambah / edit siswa â€" admin
 */
require_role(['admin','super_admin']);

$page_action = $_GET['page'] ?? 'siswa.create';
$is_edit     = ($page_action === 'siswa.edit');
$id          = (int)($_GET['id'] ?? 0); // user id

$user_data  = null;
$siswa_data = null;

if ($is_edit) {
    $user_data  = db_row('SELECT * FROM users WHERE id = :id AND role = "siswa"', [':id' => $id]);
    $siswa_data = db_row('SELECT * FROM siswa WHERE user_id = :id', [':id' => $id]);
    if (!$user_data) { flash('error','Siswa tidak ditemukan.'); header('Location: index.php?page=siswa'); exit; }
}

$errors = [];

// â"€â"€ Handle Submit â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'Token tidak valid.'; }
    else {
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $nis      = trim($_POST['nis'] ?? '');
        $nisn     = trim($_POST['nisn'] ?? '');
        $kelas_id = (int)($_POST['kelas_id'] ?? 0);
        $tgl_lahir= trim($_POST['tanggal_lahir'] ?? '');
        $tpt_lahir= trim($_POST['tempat_lahir'] ?? '');
        $alamat   = trim($_POST['alamat'] ?? '');
        $ayah     = trim($_POST['nama_ayah'] ?? '');
        $ibu      = trim($_POST['nama_ibu'] ?? '');

        if (!$nama)     $errors[] = 'Nama lengkap wajib diisi.';
        if (!$username) $errors[] = 'Username wajib diisi.';
        if (!$nis)      $errors[] = 'NIS wajib diisi.';
        if (!$kelas_id) $errors[] = 'Kelas wajib dipilih.';
        if (!$is_edit && !$password) $errors[] = 'Password wajib diisi.';

        $exist_u = db_row('SELECT id FROM users WHERE username = :u AND id != :id', [':u'=>$username, ':id'=>$id]);
        if ($exist_u) $errors[] = 'Username sudah digunakan.';
        $exist_nis = db_row('SELECT id FROM siswa WHERE nis = :nis AND user_id != :id', [':nis'=>$nis, ':id'=>$id]);
        if ($exist_nis) $errors[] = 'NIS sudah terdaftar.';

        if (empty($errors)) {
            if ($is_edit) {
                $sql = 'UPDATE users SET nama_lengkap=:n, username=:u, email=:e';
                $p   = [':n'=>$nama, ':u'=>$username, ':e'=>$email, ':id'=>$id];
                if ($password) { $sql .= ', password=:pw'; $p[':pw'] = password_hash($password, PASSWORD_BCRYPT); }
                $sql .= ' WHERE id=:id';
                db_execute($sql, $p);

                if ($siswa_data) {
                    db_execute(
                        'UPDATE siswa SET nis=:nis, nisn=:nisn, kelas_id=:kid, tanggal_lahir=:tl,
                         tempat_lahir=:tpt, alamat=:al, nama_ayah=:ay, nama_ibu=:ib WHERE user_id=:uid',
                        [':nis'=>$nis, ':nisn'=>$nisn, ':kid'=>$kelas_id, ':tl'=>$tgl_lahir?:null,
                         ':tpt'=>$tpt_lahir, ':al'=>$alamat, ':ay'=>$ayah, ':ib'=>$ibu, ':uid'=>$id]
                    );
                } else {
                    db_execute(
                        'INSERT INTO siswa (user_id,nis,nisn,kelas_id,tanggal_lahir,tempat_lahir,alamat,nama_ayah,nama_ibu)
                         VALUES (:uid,:nis,:nisn,:kid,:tl,:tpt,:al,:ay,:ib)',
                        [':uid'=>$id, ':nis'=>$nis, ':nisn'=>$nisn, ':kid'=>$kelas_id, ':tl'=>$tgl_lahir?:null,
                         ':tpt'=>$tpt_lahir, ':al'=>$alamat, ':ay'=>$ayah, ':ib'=>$ibu]
                    );
                }
                flash('success','Data siswa diperbarui.');
            } else {
                $uid = db_execute(
                    'INSERT INTO users (nama_lengkap, username, email, role, password) VALUES (:n,:u,:e,"siswa",:pw)',
                    [':n'=>$nama, ':u'=>$username, ':e'=>$email, ':pw'=>password_hash($password, PASSWORD_BCRYPT)]
                );
                db_execute(
                    'INSERT INTO siswa (user_id,nis,nisn,kelas_id,tanggal_lahir,tempat_lahir,alamat,nama_ayah,nama_ibu)
                     VALUES (:uid,:nis,:nisn,:kid,:tl,:tpt,:al,:ay,:ib)',
                    [':uid'=>$uid, ':nis'=>$nis, ':nisn'=>$nisn, ':kid'=>$kelas_id, ':tl'=>$tgl_lahir?:null,
                     ':tpt'=>$tpt_lahir, ':al'=>$alamat, ':ay'=>$ayah, ':ib'=>$ibu]
                );
                flash('success','Siswa baru berhasil ditambahkan.');
            }
            header('Location: index.php?page=siswa'); exit;
        }
    }
}

$kelas_list = db_query(
    'SELECT k.*, ta.nama as ta_nama FROM kelas k LEFT JOIN tahun_ajaran ta ON ta.id=k.tahun_ajaran_id
     ORDER BY ta.is_aktif DESC, k.nama_kelas'
);
$u = $user_data ?? [];
$s = $siswa_data ?? [];
?>
<div class="page-content" style="max-width:760px;margin:0 auto">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= $is_edit ? 'Edit Siswa' : 'Tambah Siswa' ?></h1>
        </div>
        <a href="index.php?page=siswa" class="btn btn-secondary">
            <- Kembali</a>
    </div>

    <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="card">
        <div class="card-header">Data Siswa</div>
        <div class="card-body">
            <form method="POST" action="index.php?page=<?= $page_action ?><?= $is_edit?'&id='.$id:'' ?>">
                <?= csrf_field() ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group md:col-span-2">
                        <label class="form-label">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="nama_lengkap" class="form-control" required
                            value="<?= e($u['nama_lengkap']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username <span class="text-red-500">*</span></label>
                        <input type="text" name="username" class="form-control" required
                            value="<?= e($u['username']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= e($u['email']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password
                            <?= $is_edit ? '(kosongkan jika tidak diubah)' : '<span class="text-red-500">*</span>' ?></label>
                        <input type="password" name="password" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kelas <span class="text-red-500">*</span></label>
                        <select name="kelas_id" class="form-control" required>
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach ($kelas_list as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= ($s['kelas_id']??0)==$k['id']?'selected':'' ?>>
                                <?= e($k['nama_kelas']) ?> (<?= e($k['ta_nama']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">NIS <span class="text-red-500">*</span></label>
                        <input type="text" name="nis" class="form-control" required value="<?= e($s['nis']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">NISN</label>
                        <input type="text" name="nisn" class="form-control" value="<?= e($s['nisn']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tempat Lahir</label>
                        <input type="text" name="tempat_lahir" class="form-control"
                            value="<?= e($s['tempat_lahir']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Lahir</label>
                        <input type="date" name="tanggal_lahir" class="form-control"
                            value="<?= e($s['tanggal_lahir']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nama Ayah</label>
                        <input type="text" name="nama_ayah" class="form-control" value="<?= e($s['nama_ayah']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nama Ibu</label>
                        <input type="text" name="nama_ibu" class="form-control" value="<?= e($s['nama_ibu']??'') ?>">
                    </div>
                    <div class="form-group md:col-span-2">
                        <label class="form-label">Alamat</label>
                        <textarea name="alamat" class="form-control" rows="2"><?= e($s['alamat']??'') ?></textarea>
                    </div>
                </div>

                <div class="flex gap-3 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
                            viewBox="0 0 24 24">
                            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                            <polyline points="17 21 17 13 7 13 7 21" />
                        </svg>
                        <?= $is_edit ? 'Simpan' : 'Tambah Siswa' ?>
                    </button>
                    <a href="index.php?page=siswa" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
</div>