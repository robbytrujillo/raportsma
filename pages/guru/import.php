<?php
/**
 * pages/guru/import.php
 * Template Excel (.xlsx) & import data guru
 */
require_role('admin');
require_once __DIR__ . '/../../excel.php';

/* ── Download template Excel ─────────────────────────────────── */
if (isset($_GET['download_template'])) {
    xlsx_template('template_guru.xlsx', [
        'title'    => 'Template Import Data Guru — Raportku',
        'subtitle' => '★ = wajib diisi  |  Hapus baris contoh sebelum upload  |  Kolom "status": guru / wali_kelas / koordinator',
        'columns'  => [
            ['header'=>'Nama Lengkap',  'field'=>'nama_lengkap',  'width'=>30, 'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>'Budi Santoso, S.Pd', 'note'=>'Nama lengkap sesuai SK/ijazah'],
            ['header'=>'Username',      'field'=>'username',      'width'=>20, 'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>'budi.santoso', 'note'=>'Username login, harus unik, tanpa spasi'],
            ['header'=>'Password',      'field'=>'password',      'width'=>18, 'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'password123', 'note'=>'Kosongkan = default "password"'],
            ['header'=>'Email',         'field'=>'email',         'width'=>26, 'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'budi@sekolah.id'],
            ['header'=>'NIP',           'field'=>'nip',           'width'=>22, 'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'199001012015011001', 'note'=>'Nomor Induk Pegawai (18 digit)'],
            ['header'=>'Status',        'field'=>'status',        'width'=>16, 'required'=>true,  'type'=>'dropdown',
             'dropdown'=>['guru','wali_kelas','koordinator'],
             'example'=>'guru', 'note'=>'Pilih: guru / wali_kelas / koordinator'],
            ['header'=>'Bidang Studi',  'field'=>'bidang_studi',  'width'=>24, 'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'Matematika', 'note'=>'Bidang studi / spesialisasi guru (opsional)'],
        ],
        'examples' => [
            ['Siti Rahayu, M.Pd', 'siti.rahayu',  'password123', 'siti@sekolah.id',  '198505152010012002', 'wali_kelas',  'Bahasa Indonesia'],
            ['Ahmad Koordinasi',  'ahmad.kord',   'password123', '',                 '',                   'koordinator', 'IPA'],
        ],
    ]);
}

/* ── Proses Import ─────────────────────────────────────────────── */
$results   = [];
$errors    = [];
$successes = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    if (!verify_csrf()) {
        $errors[] = 'Token tidak valid.';
    } else {
        $parsed = excel_parse($_FILES['excel_file'], 3);

        if (!$parsed['ok']) {
            $errors[] = $parsed['error'];
        } else {
            $valid_status = ['guru', 'wali_kelas', 'koordinator'];

            foreach ($parsed['rows'] as $i => $row) {
                $line     = $i + 4;
                $nama         = excel_str($row, 0, 200);
                $username     = excel_str($row, 1, 100);
                $password     = excel_str($row, 2, 100);
                $email        = excel_str($row, 3, 150);
                $nip          = excel_str($row, 4, 30);
                $status       = strtolower(excel_str($row, 5, 20)) ?: 'guru';
                $bidang_studi = excel_str($row, 6, 150);

                if (!$nama)     { $results[] = ['line'=>$line,'status'=>'skip','msg'=>'Nama kosong — dilewati']; continue; }
                if (!$username) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: username kosong — dilewati"]; continue; }
                if (!in_array($status, $valid_status)) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: status '$status' tidak valid"]; continue;
                }
                if (db_row('SELECT id FROM users WHERE username=:u',[':u'=>$username])) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: username '$username' sudah ada"]; continue;
                }

                try {
                    $role_val = ($status === 'wali_kelas') ? 'wali_kelas' : 'guru';
                    $pw_hash  = password_hash($password ?: 'password', PASSWORD_BCRYPT);
                    $uid = db_execute(
                        'INSERT INTO users (nama_lengkap,username,email,role,password) VALUES (:n,:u,:e,:r,:pw)',
                        [':n'=>$nama,':u'=>$username,':e'=>$email,':r'=>$role_val,':pw'=>$pw_hash]
                    );
                    db_execute(
                        'INSERT INTO guru (user_id,nip,status,bidang_studi) VALUES (:uid,:nip,:s,:bs)',
                        [':uid'=>$uid,':nip'=>$nip,':s'=>$status,':bs'=>$bidang_studi]
                    );
                    $results[] = ['line'=>$line,'status'=>'ok','msg'=>"$nama ($status) — berhasil ditambahkan"];
                    $successes++;
                } catch (\PDOException $e) {
                    $results[] = ['line'=>$line,'status'=>'error','msg'=>"$nama: " . $e->getMessage()];
                }
            }
            if ($successes > 0) flash('success', "$successes guru berhasil diimport.");
        }
    }
}
?>
<div class="page-content" style="max-width:860px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title">Import Data Guru</h1>
      <p class="page-subtitle">Upload file Excel (.xlsx) untuk menambahkan banyak guru sekaligus</p>
    </div>
    <a href="index.php?page=guru" class="btn btn-secondary">← Kembali</a>
  </div>

  <?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div class="card">
      <div class="card-header">
        <span>Petunjuk & Kolom</span>
        <a href="index.php?page=guru.import&download_template=1" class="btn btn-sm btn-primary">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          Download Template Excel
        </a>
      </div>
      <div class="card-body text-sm" style="line-height:1.8">
        <ol style="padding-left:18px;color:#374151">
          <li>Klik <strong>Download Template Excel</strong></li>
          <li>Isi data mulai baris ke-<strong>4</strong> (baris kuning = contoh)</li>
          <li>Kolom <strong>Status</strong> sudah ada dropdown: <code>guru</code> / <code>wali_kelas</code> / <code>koordinator</code></li>
          <li>Simpan sebagai <code>.xlsx</code> lalu upload</li>
        </ol>

        <div class="p-3 mt-3 rounded-lg text-xs" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534">
          <strong>✓ Didukung:</strong> .xlsx, .xls, .csv
          <br><strong>✓ Kolom merah</strong> = wajib diisi
          <br><strong>✓ Dropdown</strong> di kolom Status (Excel)
        </div>

        <div class="divider"></div>
        <table style="width:100%;border-collapse:collapse;font-size:.8rem">
          <tr style="background:#f3f4f6">
            <th style="padding:5px 8px;text-align:left">Kolom</th>
            <th style="padding:5px 8px;text-align:left">Keterangan</th>
            <th style="padding:5px 8px;text-align:center">Wajib</th>
          </tr>
          <?php foreach ([
            ['nama_lengkap','Nama lengkap guru','Ya'],
            ['username','Username unik untuk login','Ya'],
            ['password','Kosong = default "password"','Tidak'],
            ['email','Alamat email','Tidak'],
            ['nip','Nomor Induk Pegawai','Tidak'],
            ['status','guru / wali_kelas / koordinator','Ya'],
            ['bidang_studi','Bidang studi / spesialisasi guru','Tidak'],
          ] as $c): ?>
          <tr style="border-bottom:1px solid #f3f4f6">
            <td style="padding:4px 8px;color:#f97316;font-family:monospace;font-weight:600"><?= $c[0] ?></td>
            <td style="padding:4px 8px"><?= $c[1] ?></td>
            <td style="padding:4px 8px;text-align:center">
              <?= $c[2]==='Ya' ? '<span class="badge badge-orange" style="font-size:.7rem">Wajib</span>' : '<span class="badge badge-gray" style="font-size:.7rem">Opsional</span>' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Upload File</div>
      <div class="card-body">
        <form method="POST" action="index.php?page=guru.import" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label">Pilih File Excel / CSV <span class="text-red-500">*</span></label>
            <input type="file" name="excel_file" accept=".xlsx,.xls,.csv,.txt"
              class="form-control" style="padding:6px" required>
            <p class="text-xs text-gray-400 mt-1">Format: .xlsx, .xls, .csv | Maks 10MB</p>
          </div>
          <div class="p-3 rounded-lg text-xs mb-4" style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e">
            <strong>⚠ Perhatian:</strong> Username yang sudah ada akan <strong>dilewati</strong>. Password kosong → default <code>password</code>.
          </div>
          <button type="submit" class="btn btn-primary w-full justify-center">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/>
              <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            Proses Import
          </button>
        </form>
      </div>
    </div>
  </div>

  <?php if (!empty($results)): ?>
  <div class="card mt-5">
    <div class="card-header">
      <span>Hasil Import</span>
      <span class="badge <?= $successes > 0 ? 'badge-green' : 'badge-red' ?>">
        <?= $successes ?> berhasil / <?= count($results) - $successes ?> dilewati
      </span>
    </div>
    <div class="table-container" style="max-height:300px;overflow-y:auto">
      <table class="data-table">
        <thead><tr><th>Baris</th><th>Status</th><th>Keterangan</th></tr></thead>
        <tbody>
          <?php foreach ($results as $r): ?>
          <tr>
            <td class="text-gray-400 text-xs"><?= $r['line'] ?></td>
            <td><?php
              if ($r['status']==='ok')    echo '<span class="badge badge-green">✓ OK</span>';
              elseif ($r['status']==='skip') echo '<span class="badge badge-gray">— Skip</span>';
              else echo '<span class="badge badge-red">✗ Error</span>';
            ?></td>
            <td class="text-sm"><?= e($r['msg']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
