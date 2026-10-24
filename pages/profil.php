<?php
/**
 * pages/profil.php
 * Halaman profil pengguna — update data, foto, tanda tangan (upload atau draw)
 */
require_login();

$user      = current_user();
$role      = $user['role'];
$uid       = $user['id'];
$errors    = [];
$full_user = db_row('SELECT * FROM users WHERE id = :id', [':id' => $uid]);

// ── Handle POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'Token tidak valid.'; }
    else {
        $action = $_POST['action'] ?? 'update_profil';

        // ── Update profil dasar ──────────────────────────────────
        if ($action === 'update_profil') {
            $nama      = trim($_POST['nama_lengkap'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $password  = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';

            if (!$nama) $errors[] = 'Nama lengkap wajib diisi.';
            if ($password && $password !== $password2) $errors[] = 'Konfirmasi password tidak cocok.';

            if (empty($errors)) {
                $sql = 'UPDATE users SET nama_lengkap=:n, email=:e';
                $p   = [':n'=>$nama, ':e'=>$email, ':id'=>$uid];
                if ($password) { $sql .= ', password=:pw'; $p[':pw'] = password_hash($password, PASSWORD_BCRYPT); }
                $sql .= ' WHERE id=:id';
                db_execute($sql, $p);
                $_SESSION['nama_lengkap'] = $nama;
                flash('success', 'Profil berhasil diperbarui.');
                header('Location: index.php?page=profil'); exit;
            }
        }

        // ── Upload foto profil ───────────────────────────────────
        if ($action === 'upload_foto') {
            $result = _handle_upload('foto', 'photos');
            if ($result['success']) {
                if ($full_user['foto']) @unlink(UPLOAD_DIR . $full_user['foto']);
                db_execute('UPDATE users SET foto=:f WHERE id=:id', [':f'=>$result['path'], ':id'=>$uid]);
                $_SESSION['foto'] = $result['path'];
                flash('success', 'Foto profil diperbarui.');
            } else { $errors[] = $result['message']; }
            if (empty($errors)) { header('Location: index.php?page=profil'); exit; }
        }

        // ── Upload tanda tangan (file) ───────────────────────────
        if ($action === 'upload_ttd') {
            if (!in_array($role, ['guru','wali_kelas','kepala_sekolah','admin'])) {
                $errors[] = 'Role Anda tidak memerlukan tanda tangan.';
            } else {
                $result = _handle_upload('tanda_tangan', 'signatures');
                if ($result['success']) {
                    if ($full_user['tanda_tangan']) @unlink(UPLOAD_DIR . $full_user['tanda_tangan']);
                    db_execute('UPDATE users SET tanda_tangan=:t WHERE id=:id', [':t'=>$result['path'], ':id'=>$uid]);
                    $_SESSION['tanda_tangan'] = $result['path'];
                    flash('success', 'Tanda tangan berhasil diupload.');
                } else { $errors[] = $result['message']; }
                if (empty($errors)) { header('Location: index.php?page=profil'); exit; }
            }
        }

        // ── Simpan tanda tangan dari canvas (draw) ───────────────
        if ($action === 'save_ttd_canvas') {
            if (!in_array($role, ['guru','wali_kelas','kepala_sekolah','admin'])) {
                $errors[] = 'Role Anda tidak memerlukan tanda tangan.';
            } else {
                $dataUrl = $_POST['canvas_data'] ?? '';
                // Validasi format data URL PNG
                if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
                    $errors[] = 'Data canvas tidak valid.';
                } else {
                    $base64 = substr($dataUrl, strlen('data:image/png;base64,'));
                    $imgData = base64_decode($base64);
                    if (!$imgData) {
                        $errors[] = 'Gagal mendekode data canvas.';
                    } else {
                        $dir  = UPLOAD_DIR . 'signatures/';
                        if (!is_dir($dir)) mkdir($dir, 0755, true);
                        $filename  = 'signatures/' . uniqid('draw_') . '_' . time() . '.png';
                        $dest = UPLOAD_DIR . $filename;
                        if (file_put_contents($dest, $imgData) !== false) {
                            if ($full_user['tanda_tangan']) @unlink(UPLOAD_DIR . $full_user['tanda_tangan']);
                            db_execute('UPDATE users SET tanda_tangan=:t WHERE id=:id', [':t'=>$filename, ':id'=>$uid]);
                            $_SESSION['tanda_tangan'] = $filename;
                            flash('success', 'Tanda tangan berhasil disimpan.');
                            header('Location: index.php?page=profil'); exit;
                        } else {
                            $errors[] = 'Gagal menyimpan file tanda tangan.';
                        }
                    }
                }
            }
        }

        // ── Hapus tanda tangan ───────────────────────────────────
        if ($action === 'delete_ttd') {
            if ($full_user['tanda_tangan']) {
                @unlink(UPLOAD_DIR . $full_user['tanda_tangan']);
                db_execute('UPDATE users SET tanda_tangan=NULL WHERE id=:id', [':id'=>$uid]);
                $_SESSION['tanda_tangan'] = null;
                flash('success', 'Tanda tangan dihapus.');
            }
            header('Location: index.php?page=profil'); exit;
        }
    }
    $full_user = db_row('SELECT * FROM users WHERE id = :id', [':id' => $uid]);
}

/** Handle file upload — foto / tanda tangan */
function _handle_upload(string $field, string $subdir): array {
    if (empty($_FILES[$field]['name'])) {
        return ['success'=>false, 'message'=>'Tidak ada file yang dipilih.'];
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success'=>false, 'message'=>'Error upload (kode '.$file['error'].').'];
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success'=>false, 'message'=>'File terlalu besar. Maksimal 2MB.'];
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ALLOWED_IMG)) {
        return ['success'=>false, 'message'=>'Format tidak didukung. Gunakan PNG, JPG, atau GIF.'];
    }
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = $subdir . '/' . uniqid() . '_' . time() . '.' . $ext;
    $dest     = UPLOAD_DIR . $filename;
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success'=>false, 'message'=>'Gagal menyimpan file.'];
    }
    return ['success'=>true, 'path'=>$filename];
}

$initials = '';
foreach (explode(' ', $full_user['nama_lengkap']) as $w) {
    $initials .= strtoupper(substr($w, 0, 1));
    if (strlen($initials) >= 2) break;
}
$has_ttd  = !empty($full_user['tanda_tangan']);
$need_ttd = in_array($role, ['guru','wali_kelas','kepala_sekolah','admin']);
?>
<div class="page-content" style="max-width:900px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title">Profil Saya</h1>
      <p class="page-subtitle">Kelola data akun dan tanda tangan digital</p>
    </div>
  </div>

  <?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

    <!-- ── KOLOM KIRI: Avatar, Foto, Tanda Tangan ── -->
    <div>

      <!-- Card Foto Profil -->
      <div class="card text-center mb-4">
        <div class="card-body">
          <!-- Avatar -->
          <div style="margin:0 auto 12px">
            <?php if ($full_user['foto']): ?>
              <img src="<?= UPLOAD_URL . e($full_user['foto']) ?>" alt="foto"
                style="width:90px;height:90px;border-radius:50%;object-fit:cover;
                       border:3px solid #fed7aa;display:block;margin:0 auto">
            <?php else: ?>
              <div style="width:90px;height:90px;background:#f97316;border-radius:50%;
                          display:flex;align-items:center;justify-content:center;
                          color:#fff;font-size:2rem;font-weight:800;margin:0 auto">
                <?= e($initials) ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="font-bold text-gray-800"><?= e($full_user['nama_lengkap']) ?></div>
          <div class="badge badge-orange mt-1 mb-2"><?= str_replace('_',' ', ucfirst($role)) ?></div>
          <div class="text-sm text-gray-400 mb-3">@<?= e($full_user['username']) ?></div>

          <!-- Upload Foto -->
          <form method="POST" action="index.php?page=profil" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_foto">
            <label class="btn btn-secondary btn-sm w-full justify-center cursor-pointer">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
              </svg>
              Ganti Foto
              <input type="file" name="foto" accept="image/*" class="hidden" onchange="this.form.submit()">
            </label>
          </form>
        </div>
      </div>

      <!-- Card Tanda Tangan (hanya guru/walas/kepsek/admin) -->
      <?php if ($need_ttd): ?>
      <div class="card">
        <div class="card-header">
          <span>Tanda Tangan Digital</span>
          <?php if ($has_ttd): ?>
            <span class="badge badge-green" style="font-size:.7rem">✓ Tersimpan</span>
          <?php else: ?>
            <span class="badge badge-red" style="font-size:.7rem">Belum ada</span>
          <?php endif; ?>
        </div>
        <div class="card-body">

          <!-- Preview tanda tangan saat ini -->
          <div class="signature-preview mb-3" id="currentTtdPreview"
            style="background:<?= $has_ttd ? '#fff' : 'var(--gray-50)' ?>">
            <?php if ($has_ttd): ?>
              <img src="<?= UPLOAD_URL . e($full_user['tanda_tangan']) ?>"
                   alt="Tanda Tangan" style="max-height:80px;max-width:180px;object-fit:contain">
            <?php else: ?>
              <div class="text-gray-400 text-xs text-center">
                <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5"
                     viewBox="0 0 24 24" style="margin:0 auto 4px;opacity:.4">
                  <path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                </svg>
                Belum ada tanda tangan
              </div>
            <?php endif; ?>
          </div>

          <!-- Tombol pilih metode -->
          <div class="flex gap-2 mb-3">
            <button type="button" onclick="showTtdMethod('upload')"
              id="btnMethodUpload"
              class="btn btn-sm flex-1 justify-center"
              style="background:#fff7ed;color:#f97316;border:1.5px solid #fed7aa">
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              Upload File
            </button>
            <button type="button" onclick="showTtdMethod('draw')"
              id="btnMethodDraw"
              class="btn btn-sm flex-1 justify-center"
              style="background:#fff;color:#374151;border:1.5px solid #e5e7eb">
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M12 19l7-7 3 3-7 7-3-3z"/>
                <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>
                <path d="M2 2l7.586 7.586"/>
                <circle cx="11" cy="11" r="2"/>
              </svg>
              Tanda di Sini
            </button>
          </div>

          <!-- Panel: Upload File -->
          <div id="panelUpload">
            <form method="POST" action="index.php?page=profil" enctype="multipart/form-data" id="formUploadTtd">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="upload_ttd">
              <div class="form-group">
                <label class="form-label text-xs">Pilih file gambar tanda tangan</label>
                <input type="file" name="tanda_tangan" id="ttdFileInput"
                  accept="image/png,image/jpeg,image/gif,image/webp"
                  class="form-control" style="font-size:.8rem;padding:5px"
                  onchange="previewTtdFile(this)">
                <p class="text-xs text-gray-400 mt-1">PNG transparan sangat direkomendasikan | Maks 2MB</p>
              </div>
              <!-- Live preview sebelum upload -->
              <div id="ttdFilePreview" style="display:none;margin-bottom:10px">
                <p class="text-xs text-gray-500 mb-1">Preview:</p>
                <div style="border:1px dashed #fed7aa;border-radius:8px;padding:8px;
                            background:#fff7ed;text-align:center;max-height:90px;overflow:hidden">
                  <img id="ttdFilePreviewImg" src="" alt="preview"
                    style="max-height:75px;max-width:160px;object-fit:contain">
                </div>
              </div>
              <button type="submit" class="btn btn-primary btn-sm w-full justify-center">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                  <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Upload Tanda Tangan
              </button>
            </form>
          </div>

          <!-- Panel: Draw Canvas -->
          <div id="panelDraw" style="display:none">
            <p class="text-xs text-gray-500 mb-2">Tanda tangani di kotak bawah ini menggunakan mouse/stylus/jari:</p>
            <canvas id="ttdCanvas" width="220" height="100"
              style="border:2px dashed #fed7aa;border-radius:8px;background:#fff;
                     cursor:crosshair;touch-action:none;display:block;
                     width:100%;max-width:220px;margin:0 auto 8px"></canvas>
            <div class="flex gap-2 mb-2">
              <button type="button" onclick="clearCanvas()"
                class="btn btn-sm btn-secondary flex-1 justify-center">
                ✕ Hapus
              </button>
              <button type="button" onclick="saveCanvas()"
                id="btnSaveCanvas"
                class="btn btn-sm btn-primary flex-1 justify-center">
                ✓ Simpan
              </button>
            </div>
            <form method="POST" action="index.php?page=profil" id="formCanvasTtd">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_ttd_canvas">
              <input type="hidden" name="canvas_data" id="canvasDataInput">
            </form>
            <p class="text-xs text-gray-400 text-center">Latar otomatis transparan (PNG)</p>
          </div>

          <!-- Tombol hapus (jika sudah ada TTD) -->
          <?php if ($has_ttd): ?>
          <div style="border-top:1px solid #f3f4f6;margin-top:12px;padding-top:10px">
            <form method="POST" action="index.php?page=profil" id="formDeleteTtd"
              onsubmit="return confirm('Hapus tanda tangan ini?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_ttd">
              <button type="submit" class="btn btn-sm btn-danger w-full justify-center">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <polyline points="3 6 5 6 21 6"/>
                  <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                </svg>
                Hapus Tanda Tangan
              </button>
            </form>
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endif; ?>

    </div><!-- /kolom kiri -->

    <!-- ── KOLOM KANAN: Edit Profil + Panduan ── -->
    <div class="md:col-span-2">

      <!-- Edit Profil -->
      <div class="card mb-4">
        <div class="card-header">Edit Informasi Akun</div>
        <div class="card-body">
          <form method="POST" action="index.php?page=profil">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profil">
            <div class="form-group">
              <label class="form-label">Nama Lengkap <span class="text-red-500">*</span></label>
              <input type="text" name="nama_lengkap" class="form-control" required
                value="<?= e($full_user['nama_lengkap']) ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Username</label>
              <input type="text" class="form-control" value="<?= e($full_user['username']) ?>"
                disabled style="background:#f9fafb;cursor:not-allowed">
              <p class="text-xs text-gray-400 mt-1">Username tidak dapat diubah sendiri.</p>
            </div>
            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control"
                value="<?= e($full_user['email'] ?? '') ?>" placeholder="email@sekolah.sch.id">
            </div>
            <div class="divider"></div>
            <p class="text-sm font-semibold text-gray-600 mb-3">Ubah Password <span class="font-normal text-gray-400">(opsional)</span></p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="form-group">
                <label class="form-label">Password Baru</label>
                <input type="password" name="password" class="form-control"
                  placeholder="Biarkan kosong jika tidak diubah">
              </div>
              <div class="form-group">
                <label class="form-label">Konfirmasi Password</label>
                <input type="password" name="password2" class="form-control"
                  placeholder="Ulangi password baru">
              </div>
            </div>
            <button type="submit" class="btn btn-primary mt-2">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                <polyline points="17 21 17 13 7 13 7 21"/>
              </svg>
              Simpan Perubahan
            </button>
          </form>
        </div>
      </div>

      <!-- Panduan Tanda Tangan (hanya untuk yang butuh TTD) -->
      <?php if ($need_ttd): ?>
      <div class="card">
        <div class="card-header">
          <span>Panduan Tanda Tangan Digital</span>
          <span class="badge badge-orange" style="font-size:.7rem">
            <?= str_replace('_',' ', ucfirst($role)) ?>
          </span>
        </div>
        <div class="card-body text-sm" style="line-height:1.8">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            <!-- Cara 1: Upload File -->
            <div class="p-3 rounded-lg" style="background:#f0fdf4;border:1px solid #bbf7d0">
              <p class="font-semibold text-sm mb-2" style="color:#15803d">
                📁 Cara 1: Upload File Gambar
              </p>
              <ol style="padding-left:16px;color:#166534;font-size:.82rem;line-height:1.9">
                <li>Tanda tangan di kertas putih dengan <strong>spidol hitam</strong></li>
                <li>Foto/scan tanda tangan</li>
                <li>Edit di aplikasi foto: <strong>hapus background</strong> menjadi transparan</li>
                <li>Simpan sebagai <strong>PNG</strong> (bukan JPG)</li>
                <li>Upload via tombol "<strong>Upload File</strong>" di sebelah kiri</li>
              </ol>
              <div class="mt-2 p-2 rounded text-xs" style="background:#dcfce7;color:#166534">
                <strong>✓ Direkomendasikan</strong> untuk tampilan terbaik di raport PDF
              </div>
            </div>

            <!-- Cara 2: Draw di Browser -->
            <div class="p-3 rounded-lg" style="background:#eff6ff;border:1px solid #bfdbfe">
              <p class="font-semibold text-sm mb-2" style="color:#1d4ed8">
                ✏️ Cara 2: Tanda Langsung di Browser
              </p>
              <ol style="padding-left:16px;color:#1e40af;font-size:.82rem;line-height:1.9">
                <li>Klik tombol "<strong>Tanda di Sini</strong>" di sebelah kiri</li>
                <li>Tanda tangani di kotak yang muncul</li>
                <li>Gunakan <strong>mouse, stylus, atau jari</strong> (layar sentuh)</li>
                <li>Klik "<strong>✓ Simpan</strong>" untuk menyimpan</li>
                <li>Klik "<strong>✕ Hapus</strong>" jika ingin mengulang</li>
              </ol>
              <div class="mt-2 p-2 rounded text-xs" style="background:#dbeafe;color:#1e40af">
                <strong>✓ Cepat</strong> — langsung dari perangkat tanpa aplikasi lain
              </div>
            </div>
          </div>

          <!-- Format & Tips -->
          <div class="divider"></div>
          <p class="font-semibold text-sm mb-2">Format & Tips</p>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
            <div class="p-3 rounded-lg text-center" style="background:#f3f4f6">
              <div style="font-size:1.4rem;margin-bottom:4px">🖼️</div>
              <strong>Format terbaik</strong><br>
              PNG dengan latar transparan
            </div>
            <div class="p-3 rounded-lg text-center" style="background:#f3f4f6">
              <div style="font-size:1.4rem;margin-bottom:4px">📐</div>
              <strong>Ukuran ideal</strong><br>
              Maks 400×150 px, 2MB
            </div>
            <div class="p-3 rounded-lg text-center" style="background:#f3f4f6">
              <div style="font-size:1.4rem;margin-bottom:4px">📄</div>
              <strong>Di raport</strong><br>
              Muncul otomatis di halaman cetak
            </div>
          </div>

          <!-- Preview posisi di raport -->
          <div class="divider"></div>
          <p class="font-semibold text-sm mb-2">Posisi di Halaman Raport</p>
          <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fafafa;font-size:.78rem">
            <table style="width:100%;font-family:'Times New Roman',serif">
              <tr>
                <td style="width:45%;text-align:center;color:#374151;vertical-align:top">
                  <div>Mengetahui,</div>
                  <div>Kepala Sekolah</div>
                  <?php if ($role === 'kepala_sekolah' && $has_ttd): ?>
                    <img src="<?= UPLOAD_URL . e($full_user['tanda_tangan']) ?>"
                      style="height:45px;max-width:120px;object-fit:contain;margin:4px auto;display:block">
                  <?php else: ?>
                    <div style="height:45px;border-bottom:1px solid #9ca3af;margin:8px 30px 2px"></div>
                  <?php endif; ?>
                  <div style="text-decoration:underline;font-weight:600">
                    <?= $role === 'kepala_sekolah' ? e($full_user['nama_lengkap']) : 'Nama Kepala Sekolah' ?>
                  </div>
                </td>
                <td style="width:10%"></td>
                <td style="width:45%;text-align:center;color:#374151;vertical-align:top">
                  <div>Kota, Tanggal</div>
                  <div>Wali Kelas</div>
                  <?php if (in_array($role,['guru','wali_kelas']) && $has_ttd): ?>
                    <img src="<?= UPLOAD_URL . e($full_user['tanda_tangan']) ?>"
                      style="height:45px;max-width:120px;object-fit:contain;margin:4px auto;display:block">
                  <?php else: ?>
                    <div style="height:45px;border-bottom:1px solid #9ca3af;margin:8px 30px 2px"></div>
                  <?php endif; ?>
                  <div style="text-decoration:underline;font-weight:600">
                    <?= in_array($role,['guru','wali_kelas']) ? e($full_user['nama_lengkap']) : 'Nama Wali Kelas' ?>
                  </div>
                </td>
              </tr>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /kolom kanan -->

  </div><!-- /grid -->
</div>

<script>
// ── Pilih metode TTD (upload / draw) ───────────────────────────
function showTtdMethod(method) {
    const panelUpload = document.getElementById('panelUpload');
    const panelDraw   = document.getElementById('panelDraw');
    const btnUpload   = document.getElementById('btnMethodUpload');
    const btnDraw     = document.getElementById('btnMethodDraw');

    if (method === 'upload') {
        panelUpload.style.display = '';
        panelDraw.style.display   = 'none';
        btnUpload.style.background = '#fff7ed';
        btnUpload.style.color      = '#f97316';
        btnUpload.style.border     = '1.5px solid #fed7aa';
        btnDraw.style.background   = '#fff';
        btnDraw.style.color        = '#374151';
        btnDraw.style.border       = '1.5px solid #e5e7eb';
    } else {
        panelUpload.style.display = 'none';
        panelDraw.style.display   = '';
        btnDraw.style.background  = '#fff7ed';
        btnDraw.style.color       = '#f97316';
        btnDraw.style.border      = '1.5px solid #fed7aa';
        btnUpload.style.background = '#fff';
        btnUpload.style.color      = '#374151';
        btnUpload.style.border     = '1.5px solid #e5e7eb';
        initCanvas();
    }
}

// ── Live preview file upload ────────────────────────────────────
function previewTtdFile(input) {
    const preview    = document.getElementById('ttdFilePreview');
    const previewImg = document.getElementById('ttdFilePreviewImg');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            previewImg.src    = e.target.result;
            preview.style.display = '';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ── Canvas drawing ──────────────────────────────────────────────
let canvas, ctx, drawing = false, lastX = 0, lastY = 0, canvasInited = false;

function initCanvas() {
    if (canvasInited) return;
    canvasInited = true;
    canvas = document.getElementById('ttdCanvas');
    ctx    = canvas.getContext('2d');

    // Ukuran canvas sesuai tampilan
    const rect = canvas.getBoundingClientRect();
    canvas.width  = Math.round(rect.width  * window.devicePixelRatio || 220);
    canvas.height = Math.round(rect.height * window.devicePixelRatio || 100);
    ctx.scale(window.devicePixelRatio || 1, window.devicePixelRatio || 1);

    ctx.strokeStyle = '#1e293b'; // warna tinta hitam
    ctx.lineWidth   = 2.5;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';

    // Mouse events
    canvas.addEventListener('mousedown',  startDraw);
    canvas.addEventListener('mousemove',  doDraw);
    canvas.addEventListener('mouseup',    stopDraw);
    canvas.addEventListener('mouseleave', stopDraw);

    // Touch events (mobile / tablet)
    canvas.addEventListener('touchstart', e => {
        e.preventDefault();
        const t = e.touches[0];
        const r = canvas.getBoundingClientRect();
        lastX = t.clientX - r.left;
        lastY = t.clientY - r.top;
        drawing = true;
    }, { passive: false });

    canvas.addEventListener('touchmove', e => {
        e.preventDefault();
        if (!drawing) return;
        const t = e.touches[0];
        const r = canvas.getBoundingClientRect();
        const x = t.clientX - r.left;
        const y = t.clientY - r.top;
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(x, y);
        ctx.stroke();
        lastX = x; lastY = y;
    }, { passive: false });

    canvas.addEventListener('touchend', () => { drawing = false; });
}

function startDraw(e) {
    drawing = true;
    const r = canvas.getBoundingClientRect();
    lastX = e.clientX - r.left;
    lastY = e.clientY - r.top;
}

function doDraw(e) {
    if (!drawing) return;
    const r = canvas.getBoundingClientRect();
    const x = e.clientX - r.left;
    const y = e.clientY - r.top;
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(x, y);
    ctx.stroke();
    lastX = x; lastY = y;
}

function stopDraw() { drawing = false; }

function clearCanvas() {
    if (!canvas) return;
    ctx.clearRect(0, 0, canvas.width / (window.devicePixelRatio||1),
                        canvas.height / (window.devicePixelRatio||1));
}

function saveCanvas() {
    if (!canvas) return;
    // Ekspor sebagai PNG dengan background transparan
    const dataUrl = canvas.toDataURL('image/png');
    // Cek apakah canvas kosong (semua pixel transparan)
    const pixels = ctx.getImageData(0, 0,
        canvas.width / (window.devicePixelRatio||1),
        canvas.height / (window.devicePixelRatio||1)).data;
    const hasContent = pixels.some((v, i) => i % 4 === 3 && v > 0);
    if (!hasContent) {
        alert('Tanda tangan masih kosong. Silakan tanda tangani dulu di kotak di atas.');
        return;
    }
    document.getElementById('canvasDataInput').value = dataUrl;
    document.getElementById('formCanvasTtd').submit();
}
</script>
