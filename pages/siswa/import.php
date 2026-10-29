<?php
/**
 * pages/siswa/import.php
 * Template Excel (.xlsx) & import data siswa
 * Mendukung upload .xlsx / .xls / .csv
 */
require_role(['admin','super_admin']);
require_once __DIR__ . '/../../excel.php';

$ta_aktif   = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$kelas_list = db_query(
    'SELECT k.id, k.nama_kelas, k.tingkat, ta.nama as ta_nama, ta.is_aktif
     FROM kelas k
     LEFT JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id
     ORDER BY ta.is_aktif DESC, k.tingkat, k.nama_kelas'
);

/* â"€â"€ Download template Excel â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ */
if (isset($_GET['download_template'])) {
    $kelas_ex   = $kelas_list[0] ?? ['id'=>1,'nama_kelas'=>'X IPA 1'];
    $kelas_ref  = [];
    foreach ($kelas_list as $k) {
        $kelas_ref[] = [$k['id'], $k['nama_kelas'], $k['tingkat'], $k['ta_nama'], $k['is_aktif'] ? 'â˜... Aktif' : ''];
    }

    xlsx_template('template_siswa.xlsx', [
        'title'    => 'Template Import Data Siswa â€" Raportku',
        'subtitle' => 'â˜... = wajib diisi  |  Hapus baris contoh sebelum upload  |  Lihat sheet "Referensi" untuk daftar kelas_id',
        'columns'  => [
            ['header'=>'Nama Lengkap',    'field'=>'nama_lengkap',  'width'=>28,'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>'Ahmad Fauzi', 'note'=>'Nama lengkap siswa sesuai ijazah'],
            ['header'=>'Username',         'field'=>'username',      'width'=>18,'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>'ahmad.fauzi', 'note'=>'Username untuk login, harus unik, tanpa spasi'],
            ['header'=>'Password',         'field'=>'password',      'width'=>16,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'password123', 'note'=>'Kosongkan = pakai default "password"'],
            ['header'=>'Email',            'field'=>'email',         'width'=>24,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'ahmad@sekolah.id'],
            ['header'=>'NIS',              'field'=>'nis',           'width'=>14,'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>'2024001', 'note'=>'Nomor Induk Siswa, harus unik'],
            ['header'=>'NISN',             'field'=>'nisn',          'width'=>14,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'0012345678', 'note'=>'Nomor Induk Siswa Nasional (10 digit)'],
            ['header'=>'Kelas ID',         'field'=>'kelas_id',      'width'=>10,'required'=>true,  'type'=>'number',
             'example'=>$kelas_ex['id'], 'note'=>'ID kelas dari sheet Referensi'],
            ['header'=>'Tempat Lahir',     'field'=>'tempat_lahir',  'width'=>18,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'Jakarta'],
            ['header'=>'Tanggal Lahir',    'field'=>'tanggal_lahir', 'width'=>14,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'2008-05-15', 'note'=>'Format: YYYY-MM-DD (contoh: 2008-05-15)'],
            ['header'=>'Nama Ayah',        'field'=>'nama_ayah',     'width'=>22,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'Bapak Fauzi'],
            ['header'=>'Nama Ibu',         'field'=>'nama_ibu',      'width'=>22,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'Ibu Sari'],
            ['header'=>'Alamat',           'field'=>'alamat',        'width'=>32,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'Jl. Mawar No.1 Jakarta'],
        ],
        'examples' => [
            ['Siti Aminah','siti.aminah','password123','siti@sekolah.id',
             '2024002','0012345679',$kelas_ex['id'],'Depok','2008-07-20','Bapak Amin','Ibu Nur','Jl. Melati No.2'],
        ],
        'notes_sheet' => [
            'title'   => 'Daftar Kelas â€" gunakan angka di kolom "ID" sebagai Kelas ID',
            'headers' => ['ID','Nama Kelas','Tingkat','Tahun Ajaran','Status'],
            'rows'    => $kelas_ref,
        ],
    ]);
}

/* â"€â"€ Proses Import â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ */
$results   = [];
$errors    = [];
$successes = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    if (!verify_csrf()) {
        $errors[] = 'Token tidak valid.';
    } else {
        $parsed = excel_parse($_FILES['excel_file'], 3); // 3 baris header (judul+subtitle+kolom)

        if (!$parsed['ok']) {
            $errors[] = $parsed['error'];
        } else {
            foreach ($parsed['rows'] as $i => $row) {
                $line     = $i + 4; // +4: 3 header + 1-based
                $nama     = excel_str($row, 0, 200);
                $username = excel_str($row, 1, 100);
                $password = excel_str($row, 2, 100);
                $email    = excel_str($row, 3, 150);
                $nis      = excel_str($row, 4, 20);
                $nisn     = excel_str($row, 5, 20);
                $kelas_id = excel_int($row, 6);
                $tpt      = excel_str($row, 7, 100);
                $tgl      = excel_str($row, 8, 10);
                $ayah     = excel_str($row, 9, 200);
                $ibu      = excel_str($row, 10, 200);
                $alamat   = excel_str($row, 11, 500);

                if (!$nama) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>'Nama kosong â€" dilewati']; continue; }
                if (!$username) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: username kosong â€" dilewati"]; continue; }
                if (!$nis) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: NIS kosong â€" dilewati"]; continue; }
                if (!$kelas_id) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: kelas_id kosong â€" dilewati"]; continue; }

                if (db_row('SELECT id FROM users WHERE username=:u',[':u'=>$username])) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: username '$username' sudah ada"]; continue;
                }
                if (db_row('SELECT id FROM siswa WHERE nis=:n',[':n'=>$nis])) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: NIS '$nis' sudah terdaftar"]; continue;
                }
                if (!db_row('SELECT id FROM kelas WHERE id=:id',[':id'=>$kelas_id])) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: kelas_id $kelas_id tidak ditemukan"]; continue;
                }

                try {
                    $pw_hash = password_hash($password ?: 'password', PASSWORD_BCRYPT);
                    $uid = db_execute(
                        'INSERT INTO users (nama_lengkap,username,email,role,password) VALUES (:n,:u,:e,"siswa",:pw)',
                        [':n'=>$nama,':u'=>$username,':e'=>$email,':pw'=>$pw_hash]
                    );
                    db_execute(
                        'INSERT INTO siswa (user_id,nis,nisn,kelas_id,tempat_lahir,tanggal_lahir,alamat,nama_ayah,nama_ibu)
                         VALUES (:uid,:nis,:nisn,:kid,:tpt,:tgl,:al,:ay,:ib)',
                        [':uid'=>$uid,':nis'=>$nis,':nisn'=>$nisn,':kid'=>$kelas_id,
                         ':tpt'=>$tpt,':tgl'=>($tgl?:null),':al'=>$alamat,':ay'=>$ayah,':ib'=>$ibu]
                    );
                    $results[] = ['line'=>$line,'status'=>'ok','msg'=>"$nama (NIS: $nis, Kelas ID: $kelas_id) â€" berhasil"];
                    $successes++;
                } catch (\PDOException $e) {
                    $results[] = ['line'=>$line,'status'=>'error','msg'=>"$nama: " . $e->getMessage()];
                }
            }
            if ($successes > 0) flash('success', "$successes siswa berhasil diimport.");
        }
    }
}
?>
<div class="page-content" style="max-width:860px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title">Import Data Siswa</h1>
      <p class="page-subtitle">Upload file Excel (.xlsx) untuk menambahkan banyak siswa sekaligus</p>
    </div>
    <a href="index.php?page=siswa" class="btn btn-secondary">â† Kembali</a>
  </div>

  <?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <!-- Petunjuk & Download Template -->
    <div class="card">
      <div class="card-header">
        <span>Langkah Import</span>
        <a href="index.php?page=siswa.import&download_template=1" class="btn btn-sm btn-primary">
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
          <li>Klik <strong>Download Template Excel</strong> di atas</li>
          <li>Buka file <code>.xlsx</code> di <strong>Microsoft Excel</strong></li>
          <li>Isi data siswa mulai baris ke-<strong>4</strong> (baris kuning = contoh, boleh dihapus)</li>
          <li>Lihat sheet <strong>"Referensi"</strong> untuk daftar ID kelas</li>
          <li>Simpan file (tetap format <code>.xlsx</code>)</li>
          <li>Upload di form sebelah kanan</li>
        </ol>

        <div class="p-3 mt-3 rounded-lg text-xs" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534">
          <strong>âœ" Format yang didukung:</strong> .xlsx, .xls, .csv
          <br><strong>âœ" Kolom header berwarna merah</strong> = wajib diisi
          <br><strong>âœ" Sheet "Referensi"</strong> di file berisi daftar kelas_id
        </div>

        <div class="divider"></div>
        <p class="font-semibold text-gray-700 mb-2 text-xs">Daftar kelas (kelas_id):</p>
        <div style="max-height:140px;overflow-y:auto">
          <table style="width:100%;font-size:.75rem;border-collapse:collapse">
            <tr style="background:#f3f4f6">
              <th style="padding:3px 6px;text-align:left">ID</th>
              <th style="padding:3px 6px;text-align:left">Nama Kelas</th>
              <th style="padding:3px 6px;text-align:left">TA</th>
            </tr>
            <?php foreach ($kelas_list as $k): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
              <td style="padding:2px 6px;font-weight:700;color:#f97316"><?= $k['id'] ?></td>
              <td style="padding:2px 6px"><?= e($k['nama_kelas']) ?></td>
              <td style="padding:2px 6px;color:#9ca3af"><?= e($k['ta_nama']) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
    </div>

    <!-- Form Upload -->
    <div class="card">
      <div class="card-header">Upload File</div>
      <div class="card-body">
        <form method="POST" action="index.php?page=siswa.import" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label">Pilih File Excel / CSV <span class="text-red-500">*</span></label>
            <input type="file" name="excel_file" accept=".xlsx,.xls,.csv,.txt"
              class="form-control" style="padding:6px;font-size:.875rem" required>
            <p class="text-xs text-gray-400 mt-1">Format: .xlsx, .xls, .csv | Maks 10MB</p>
          </div>
          <div class="p-3 rounded-lg text-xs" style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e;margin-bottom:16px">
            <strong>âš  Perhatian:</strong>
            <ul style="padding-left:16px;margin-top:4px;line-height:1.7">
              <li>Baris dengan username / NIS yang sudah ada akan <strong>dilewati</strong></li>
              <li>Password kosong â†' default: <code>password</code></li>
              <li>Data dimulai baris ke-4 pada template Excel</li>
            </ul>
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
    <div class="table-container" style="max-height:320px;overflow-y:auto">
      <table class="data-table">
        <thead><tr><th>Baris</th><th>Status</th><th>Keterangan</th></tr></thead>
        <tbody>
          <?php foreach ($results as $r): ?>
          <tr>
            <td class="text-gray-400 text-xs"><?= $r['line'] ?></td>
            <td><?php
              if ($r['status']==='ok')    echo '<span class="badge badge-green">âœ" OK</span>';
              elseif ($r['status']==='skip') echo '<span class="badge badge-gray">â€" Skip</span>';
              else echo '<span class="badge badge-red">âœ&mdash; Error</span>';
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
