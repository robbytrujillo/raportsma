<?php
/**
 * pages/sekolah/index.php
 * Pengaturan Informasi Sekolah -- admin
 */
require_role(['admin','super_admin']);

$errors = [];
$sekolah = sekolah_info();

// -- Handle POST --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'Token tidak valid.'; }
    else {
        $nama_yayasan = trim($_POST['nama_yayasan'] ?? '');
        $nama_sekolah = trim($_POST['nama_sekolah'] ?? '');
        $alamat       = trim($_POST['alamat'] ?? '');
        $telp         = trim($_POST['telp'] ?? '');
        $website      = trim($_POST['website'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $npsn         = trim($_POST['npsn'] ?? '');
        $kota         = trim($_POST['kota'] ?? '');
        $krit_a       = max(0, min(100, (float)($_POST['kriteria_a_min'] ?? 92)));
        $krit_b       = max(0, min(100, (float)($_POST['kriteria_b_min'] ?? 83)));
        $krit_c       = max(0, min(100, (float)($_POST['kriteria_c_min'] ?? 76)));

        if (!$nama_sekolah) $errors[] = 'Nama sekolah wajib diisi.';

        // Handle upload logo
        $logo_path = $sekolah['logo'] ?? null;
        if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];
            $mime = mime_content_type($file['tmp_name']);
            if (!in_array($mime, ALLOWED_IMG)) {
                $errors[] = 'Format logo tidak didukung.';
            } elseif ($file['size'] > MAX_FILE_SIZE) {
                $errors[] = 'Ukuran logo maks 2MB.';
            } else {
                // Cek double extension
                $orig_parts  = explode('.', strtolower(basename($file['name'])));
                $danger_exts = ['php','php3','php4','php5','php7','php8','phtml','phar','pl','py','sh','cgi','asp','aspx'];
                $has_danger  = array_intersect($orig_parts, $danger_exts);
                if ($has_danger) {
                    $errors[] = 'Nama file logo tidak diizinkan.';
                } else {
                    // Simpan dengan nama acak dari MIME type (bukan ekstensi dari nama asli)
                    $ext_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                    $ext  = $ext_map[$mime] ?? 'png';
                    $dest = 'logos/logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $full = UPLOAD_DIR . $dest;
                    if (!is_dir(dirname($full))) mkdir(dirname($full), 0755, true);
                    if (move_uploaded_file($file['tmp_name'], $full)) {
                        if ($logo_path) @unlink(UPLOAD_DIR . $logo_path);
                        $logo_path = $dest;
                    }
                }
            }
        }

        if (empty($errors)) {
            $exists = db_row('SELECT id FROM sekolah_info LIMIT 1');
            $p = [
                ':ny'=>$nama_yayasan, ':ns'=>$nama_sekolah, ':al'=>$alamat,
                ':tp'=>$telp, ':wb'=>$website, ':em'=>$email, ':np'=>$npsn,
                ':ko'=>$kota, ':ka'=>$krit_a, ':kb'=>$krit_b, ':kc'=>$krit_c,
                ':lg'=>$logo_path,
            ];
            if ($exists) {
                db_execute(
                    'UPDATE sekolah_info SET nama_yayasan=:ny, nama_sekolah=:ns, alamat=:al,
                     telp=:tp, website=:wb, email=:em, npsn=:np, kota=:ko,
                     kriteria_a_min=:ka, kriteria_b_min=:kb, kriteria_c_min=:kc, logo=:lg
                     WHERE id=' . (int)$exists['id'],
                    $p
                );
            } else {
                db_execute(
                    'INSERT INTO sekolah_info (nama_yayasan,nama_sekolah,alamat,telp,website,email,npsn,kota,
                     kriteria_a_min,kriteria_b_min,kriteria_c_min,logo)
                     VALUES (:ny,:ns,:al,:tp,:wb,:em,:np,:ko,:ka,:kb,:kc,:lg)',
                    $p
                );
            }
            flash('success', 'Informasi sekolah berhasil disimpan.');
            header('Location: index.php?page=sekolah'); exit;
        }
    }
}

$s = $sekolah;
?>
<div class="page-content" style="max-width:820px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title">Pengaturan Sekolah</h1>
      <p class="page-subtitle">Informasi yang tampil di header raport</p>
    </div>
  </div>

  <?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-header">Informasi Sekolah</div>
    <div class="card-body">
      <form method="POST" action="index.php?page=sekolah" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="form-group md:col-span-2">
            <label class="form-label">Nama Yayasan</label>
            <input type="text" name="nama_yayasan" class="form-control"
              value="<?= e($s['nama_yayasan'] ?? '') ?>"
              placeholder="YAYASAN DAKWAH ISLAM CAHAYA ILMU">
          </div>

          <div class="form-group md:col-span-2">
            <label class="form-label">Nama Sekolah <span class="text-red-500">*</span></label>
            <input type="text" name="nama_sekolah" class="form-control" required
              value="<?= e($s['nama_sekolah'] ?? '') ?>"
              placeholder="SMA IBNU HAJAR BOARDING SCHOOL">
          </div>

          <div class="form-group md:col-span-2">
            <label class="form-label">Alamat</label>
            <textarea name="alamat" class="form-control" rows="2"
              placeholder="Jl. Contoh No.1, Kota"><?= e($s['alamat'] ?? '') ?></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Telepon / Fax</label>
            <input type="text" name="telp" class="form-control"
              value="<?= e($s['telp'] ?? '') ?>" placeholder="021-00000000">
          </div>

          <div class="form-group">
            <label class="form-label">Kota (untuk tanggal di raport)</label>
            <input type="text" name="kota" class="form-control"
              value="<?= e($s['kota'] ?? '') ?>" placeholder="Depok">
          </div>

          <div class="form-group">
            <label class="form-label">Website</label>
            <input type="text" name="website" class="form-control"
              value="<?= e($s['website'] ?? '') ?>" placeholder="http://sekolah.sch.id">
          </div>

          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control"
              value="<?= e($s['email'] ?? '') ?>" placeholder="info@sekolah.sch.id">
          </div>

          <div class="form-group">
            <label class="form-label">NPSN</label>
            <input type="text" name="npsn" class="form-control"
              value="<?= e($s['npsn'] ?? '') ?>" placeholder="00000000">
          </div>

          <!-- Logo -->
          <div class="form-group">
            <label class="form-label">Logo Sekolah</label>
            <?php if ($s['logo']): ?>
            <div class="mb-2">
              <img src="<?= UPLOAD_URL . e($s['logo']) ?>" alt="Logo" style="height:60px;object-fit:contain">
            </div>
            <?php endif; ?>
            <input type="file" name="logo" accept="image/*" class="form-control" style="padding:5px;font-size:.85rem">
            <p class="text-xs text-gray-400 mt-1">PNG transparan disarankan. Maks 2MB.</p>
          </div>
        </div>

        <!-- Kriteria Nilai -->
        <div class="divider"></div>
        <p class="text-sm font-semibold text-gray-600 mb-3">Kriteria Penilaian (tampil di raport)</p>
        <div class="grid grid-cols-3 gap-4">
          <div class="form-group">
            <label class="form-label">Predikat A &ndash; minimal</label>
            <div class="flex items-center gap-2">
              <input type="number" name="kriteria_a_min" class="form-control" min="0" max="100" step="1"
                value="<?= (int)($s['kriteria_a_min'] ?? 92) ?>">
              <span class="text-sm text-gray-400">&ndash; 100</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Predikat B &ndash; minimal</label>
            <div class="flex items-center gap-2">
              <input type="number" name="kriteria_b_min" class="form-control" min="0" max="100" step="1"
                value="<?= (int)($s['kriteria_b_min'] ?? 83) ?>">
              <span class="text-sm text-gray-400">&ndash; <?= (int)($s['kriteria_a_min'] ?? 92) - 1 ?></span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Predikat C &ndash; minimal</label>
            <div class="flex items-center gap-2">
              <input type="number" name="kriteria_c_min" class="form-control" min="0" max="100" step="1"
                value="<?= (int)($s['kriteria_c_min'] ?? 76) ?>">
              <span class="text-sm text-gray-400">&ndash; <?= (int)($s['kriteria_b_min'] ?? 83) - 1 ?></span>
            </div>
          </div>
        </div>
        <p class="text-xs text-gray-400 mt-1">Predikat D: di bawah nilai minimal C</p>

        <!-- Preview Kriteria -->
        <div class="mt-2 p-3 bg-gray-50 rounded-lg border border-gray-200 text-xs font-mono text-gray-600 inline-block">
          <em>Kriteria :</em><br>
          A : <?= (int)($s['kriteria_a_min'] ?? 92) ?> - 100<br>
          B : <?= (int)($s['kriteria_b_min'] ?? 83) ?> - <?= (int)($s['kriteria_a_min'] ?? 92) - 1 ?><br>
          C : <?= (int)($s['kriteria_c_min'] ?? 76) ?> - <?= (int)($s['kriteria_b_min'] ?? 83) - 1 ?><br>
          D : &lt; <?= (int)($s['kriteria_c_min'] ?? 76) ?>
        </div>

        <div class="flex gap-3 mt-5">
          <button type="submit" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
              <polyline points="17 21 17 13 7 13 7 21"/>
            </svg>
            Simpan Pengaturan
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
