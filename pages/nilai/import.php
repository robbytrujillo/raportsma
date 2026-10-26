<?php
/**
 * pages/nilai/import.php
 * Template Excel (.xlsx) & import nilai bulk
 *
 * Kolom: siswa_nis, mapel_nama, kelas_nama, tahun_ajaran_id, jenis_raport, bulan,
 *        nh1, nh2, nh3, tugas, sts, sas, catatan
 */
require_role(['admin', 'guru', 'wali_kelas']);
require_once __DIR__ . '/../../excel.php';

$user     = current_user();
$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$sekolah  = sekolah_info();

$ta_list    = db_query('SELECT * FROM tahun_ajaran ORDER BY is_aktif DESC, id DESC');
$mapel_list = db_query('SELECT id, nama_mapel, kelompok FROM mapel ORDER BY kelompok, urutan, nama_mapel');
$kelas_list = db_query(
    'SELECT k.id, k.nama_kelas, k.tingkat, ta.nama as ta_nama, ta.id as ta_id
     FROM kelas k LEFT JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id
     ORDER BY ta.is_aktif DESC, k.nama_kelas'
);

$krit_a = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c = (float)($sekolah['kriteria_c_min'] ?? 76);

/* ── Download template Excel ─────────────────────────────────── */
if (isset($_GET['download_template'])) {
    $ta_ex    = $ta_aktif ?? ($ta_list[0] ?? ['id'=>1,'nama'=>'2024/2025']);
    $mapel_ex = $mapel_list[0]['nama_mapel'] ?? 'Matematika';
    $kelas_ex = $kelas_list[0]['nama_kelas'] ?? 'X IPA 1';
    $nis_ex   = '2024001';

    // Sheet referensi: tahun ajaran | mapel | kelas
    $ref_ta = array_map(fn($ta) => [$ta['id'], $ta['nama'], 'Sem.'.$ta['semester'], $ta['is_aktif']?'★':''], $ta_list);
    $ref_mapel = array_map(fn($m) => [$m['nama_mapel'], $m['kelompok']], $mapel_list);
    $ref_kelas = array_map(fn($k) => [$k['nama_kelas'], $k['tingkat'], $k['ta_nama'], $k['ta_id']], $kelas_list);

    $max_ref = max(count($ref_ta), count($ref_mapel), count($ref_kelas));
    $ref_rows = [];
    for ($i = 0; $i < $max_ref; $i++) {
        $ta  = $ref_ta[$i]    ?? ['','','',''];
        $m   = $ref_mapel[$i] ?? ['',''];
        $k   = $ref_kelas[$i] ?? ['','','',''];
        $ref_rows[] = [$ta[0],$ta[1],$ta[2],$ta[3],'', $m[0],$m[1],'', $k[0],$k[1],$k[2],'(TA ID: '.$k[3].')'];
    }

    xlsx_template('template_nilai.xlsx', [
        'title'    => 'Template Import Nilai — Raportku',
        'subtitle' => '★ = wajib  |  Lihat sheet "Referensi" untuk nama mapel, kelas, dan ID tahun ajaran yang valid',
        'columns'  => [
            ['header'=>'NIS Siswa',       'field'=>'siswa_nis',       'width'=>14,'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>$nis_ex, 'note'=>'Nomor Induk Siswa (NIS)'],
            ['header'=>'Nama Mapel',      'field'=>'mapel_nama',      'width'=>26,'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>$mapel_ex, 'note'=>'Nama mapel PERSIS sama dengan database (lihat Referensi)'],
            ['header'=>'Nama Kelas',      'field'=>'kelas_nama',      'width'=>16,'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>$kelas_ex, 'note'=>'Nama kelas PERSIS sama dengan database (lihat Referensi)'],
            ['header'=>'Tahun Ajaran ID', 'field'=>'tahun_ajaran_id', 'width'=>16,'required'=>true,  'type'=>'number',
             'example'=>$ta_ex['id'], 'note'=>'ID tahun ajaran (lihat sheet Referensi)'],
            ['header'=>'Jenis Raport',    'field'=>'jenis_raport',    'width'=>14,'required'=>true,  'type'=>'dropdown',
             'dropdown'=>['bulanan','sts','semester'],
             'example'=>'bulanan', 'note'=>'bulanan / sts / semester'],
            ['header'=>'Bulan',           'field'=>'bulan',           'width'=>8, 'required'=>false, 'type'=>'number',
             'example'=>7, 'note'=>'1-12. Wajib jika jenis=bulanan/sts. Kosongkan jika semester.'],
            ['header'=>'Nilai Harian 1',  'field'=>'nh1',             'width'=>13,'required'=>false, 'type'=>'number',
             'example'=>85, 'note'=>'Diisi jika jenis=bulanan'],
            ['header'=>'Nilai Harian 2',  'field'=>'nh2',             'width'=>13,'required'=>false, 'type'=>'number',
             'example'=>88, 'note'=>'Diisi jika jenis=bulanan'],
            ['header'=>'Nilai Harian 3',  'field'=>'nh3',             'width'=>13,'required'=>false, 'type'=>'number',
             'example'=>90, 'note'=>'Diisi jika jenis=bulanan'],
            ['header'=>'Nilai Tugas',     'field'=>'tugas',           'width'=>12,'required'=>false, 'type'=>'number',
             'example'=>80, 'note'=>'Diisi jika jenis=bulanan. Formula: (avg(NH1,2,3)×70%)+(Tugas×30%)'],
            ['header'=>'Nilai STS',       'field'=>'sts',             'width'=>12,'required'=>false, 'type'=>'number',
             'example'=>0, 'note'=>'Diisi jika jenis=sts'],
            ['header'=>'Nilai SAS',       'field'=>'sas',             'width'=>12,'required'=>false, 'type'=>'number',
             'example'=>0, 'note'=>'Diisi jika jenis=semester'],
            ['header'=>'Catatan',         'field'=>'catatan',         'width'=>28,'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'', 'note'=>'Catatan guru (opsional)'],
        ],
        'examples' => [
            // Contoh semester
            [$nis_ex, $mapel_ex, $kelas_ex, $ta_ex['id'], 'semester', '', 0, 0, 0, 0, 0, 88, 'Siswa aktif dan berprestasi'],
        ],
        'notes_sheet' => [
            'title'   => 'Referensi: Tahun Ajaran | Nama Mapel | Nama Kelas',
            'headers' => ['ID TA','Nama TA','Semester','Status','','Nama Mapel','Kelompok','','Nama Kelas','Tingkat','Tahun Ajaran','(TA ID)'],
            'rows'    => $ref_rows,
        ],
    ]);
}

/* ── Proses Import ─────────────────────────────────────────────── */
$results   = [];
$errors    = [];
$successes = 0;

// Cache lookup antar baris
$siswa_cache = [];
$mapel_cache = [];
$kelas_cache = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    if (!verify_csrf()) {
        $errors[] = 'Token tidak valid.';
    } else {
        $parsed = excel_parse($_FILES['excel_file'], 3);

        if (!$parsed['ok']) {
            $errors[] = $parsed['error'];
        } else {
            foreach ($parsed['rows'] as $i => $row) {
                $line       = $i + 4;
                $nis        = excel_str($row, 0, 20);
                $mapel_nama = excel_str($row, 1, 255);
                $kelas_nama = excel_str($row, 2, 100);
                $ta_id      = excel_int($row, 3);
                $jenis      = excel_str($row, 4, 20);
                $bulan_raw  = trim($row[5] ?? '');
                $h1         = excel_float($row, 6);
                $h2         = excel_float($row, 7);
                $h3         = excel_float($row, 8);
                $tugas      = excel_float($row, 9);
                $sts_val    = excel_float($row, 10);
                $sas        = excel_float($row, 11);
                $catatan    = excel_str($row, 12, 500);

                // Validasi wajib
                if (!$nis)        { $results[] = ['line'=>$line,'status'=>'skip','msg'=>'NIS kosong — dilewati']; continue; }
                if (!$mapel_nama) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: nama mapel kosong — dilewati"]; continue; }
                if (!$kelas_nama) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: nama kelas kosong — dilewati"]; continue; }
                if (!$ta_id)      { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: tahun_ajaran_id kosong — dilewati"]; continue; }

                if (!in_array($jenis, ['bulanan','sts','semester'], true)) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: jenis_raport '$jenis' tidak valid — dilewati"]; continue;
                }

                $bulan = null;
                if ($jenis !== 'semester') {
                    $bulan = (int)$bulan_raw;
                    if ($bulan < 1 || $bulan > 12) {
                        $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: bulan harus 1-12 untuk jenis '$jenis' — dilewati"]; continue;
                    }
                }

                // Lookup Siswa (cache)
                if (!isset($siswa_cache[$nis])) {
                    $s = db_row('SELECT s.user_id FROM siswa s WHERE s.nis=:nis LIMIT 1',[':nis'=>$nis]);
                    $siswa_cache[$nis] = $s ? (int)$s['user_id'] : null;
                }
                if (!$siswa_cache[$nis]) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: siswa tidak ditemukan — dilewati"]; continue;
                }

                // Lookup Mapel (cache, case-insensitive)
                $mk = mb_strtolower($mapel_nama);
                if (!isset($mapel_cache[$mk])) {
                    $m = db_row('SELECT id FROM mapel WHERE LOWER(nama_mapel)=LOWER(:n) LIMIT 1',[':n'=>$mapel_nama]);
                    $mapel_cache[$mk] = $m ? (int)$m['id'] : null;
                }
                if (!$mapel_cache[$mk]) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: mapel '$mapel_nama' tidak ditemukan — dilewati"]; continue;
                }

                // Lookup Kelas (cache, case-insensitive + ta_id)
                $kk = mb_strtolower($kelas_nama) . '|' . $ta_id;
                if (!isset($kelas_cache[$kk])) {
                    $k = db_row('SELECT id FROM kelas WHERE LOWER(nama_kelas)=LOWER(:n) AND tahun_ajaran_id=:ta LIMIT 1',
                        [':n'=>$kelas_nama,':ta'=>$ta_id]);
                    $kelas_cache[$kk] = $k ? (int)$k['id'] : null;
                }
                if (!$kelas_cache[$kk]) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: kelas '$kelas_nama' (TA ID:$ta_id) tidak ditemukan — dilewati"]; continue;
                }

                // Hitung nilai akhir — bulanan fleksibel (rata-rata komponen yang terisi)
                if ($jenis === 'semester')     $na = round($sas, 2);
                elseif ($jenis === 'sts')      $na = round($sts_val, 2);
                else {
                    $komp = array_filter([$h1, $h2, $h3, $tugas], fn($v) => $v > 0);
                    $na   = count($komp) > 0 ? round(array_sum($komp) / count($komp), 2) : 0;
                }

                $pred = ($na >= $krit_a) ? 'A' : (($na >= $krit_b) ? 'B' : (($na >= $krit_c) ? 'C' : 'D'));

                try {
                    db_execute(
                        'INSERT INTO nilai
                            (siswa_user_id,mapel_id,kelas_id,tahun_ajaran_id,guru_user_id,
                             jenis_raport,bulan,
                             nilai_harian_1,nilai_harian_2,nilai_harian_3,
                             nilai_tugas,nilai_sts,nilai_sas,nilai_akhir,predikat,catatan)
                         VALUES (:sid,:m,:k,:ta,:guru,:j,:b,:h1,:h2,:h3,:tug,:sts,:sas,:na,:pred,:cat)
                         ON DUPLICATE KEY UPDATE
                             guru_user_id=VALUES(guru_user_id),
                             nilai_harian_1=VALUES(nilai_harian_1),nilai_harian_2=VALUES(nilai_harian_2),
                             nilai_harian_3=VALUES(nilai_harian_3),nilai_tugas=VALUES(nilai_tugas),
                             nilai_sts=VALUES(nilai_sts),nilai_sas=VALUES(nilai_sas),
                             nilai_akhir=VALUES(nilai_akhir),predikat=VALUES(predikat),catatan=VALUES(catatan)',
                        [':sid'=>$siswa_cache[$nis],':m'=>$mapel_cache[$mk],
                         ':k'=>$kelas_cache[$kk],':ta'=>$ta_id,':guru'=>$user['id'],
                         ':j'=>$jenis,':b'=>$bulan,
                         ':h1'=>$h1,':h2'=>$h2,':h3'=>$h3,
                         ':tug'=>$tugas,':sts'=>$sts_val,':sas'=>$sas,
                         ':na'=>$na,':pred'=>$pred,':cat'=>$catatan]
                    );
                    $results[] = ['line'=>$line,'status'=>'ok',
                        'msg'=>"NIS $nis | $mapel_nama | NA: $na ($pred) — berhasil"];
                    $successes++;
                } catch (\PDOException $ex) {
                    $results[] = ['line'=>$line,'status'=>'error','msg'=>"NIS $nis: " . $ex->getMessage()];
                }
            }
            if ($successes > 0) flash('success', "$successes data nilai berhasil diimport.");
        }
    }
}
?>
<div class="page-content" style="max-width:960px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title">Import Data Nilai</h1>
      <p class="page-subtitle">Upload file Excel (.xlsx) untuk memasukkan nilai banyak siswa sekaligus</p>
    </div>
    <a href="index.php?page=nilai" class="btn btn-secondary">← Kembali</a>
  </div>

  <?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <!-- Petunjuk & Download Template -->
    <div class="card">
      <div class="card-header">
        <span>Langkah Import</span>
        <a href="index.php?page=nilai.import&download_template=1" class="btn btn-sm btn-primary">
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
          <li>Isi data mulai baris ke-<strong>4</strong></li>
          <li>Kolom <strong>Jenis Raport</strong> sudah ada dropdown</li>
          <li>Lihat sheet <strong>"Referensi"</strong> untuk nama mapel/kelas yang valid</li>
          <li>Tooltip muncul saat hover di header kolom (Excel)</li>
          <li>Upload file .xlsx</li>
        </ol>

        <div class="p-3 mt-3 rounded-lg text-xs" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534">
          <strong>✓ Didukung:</strong> .xlsx, .xls, .csv
          <br><strong>✓ Dropdown Jenis:</strong> bulanan / sts / semester
          <br><strong>✓ Sheet "Referensi":</strong> Tahun Ajaran, Mapel, Kelas dalam 1 file
          <br><strong>✓ Data yang sudah ada:</strong> akan di-update (overwrite)
        </div>

        <div class="divider"></div>
        <p class="font-semibold text-xs text-gray-700 mb-1">Formula nilai akhir:</p>
        <div class="p-2 rounded text-xs" style="background:#f3f4f6;font-family:monospace;line-height:1.8">
          Bulanan: (avg(NH1,2,3)×70%)+(Tugas×30%)<br>
          STS: nilai STS langsung<br>
          Semester: nilai SAS langsung
        </div>

        <div class="divider"></div>
        <p class="font-semibold text-xs text-gray-700 mb-1">Penjelasan kolom:</p>
        <table style="width:100%;font-size:.75rem;border-collapse:collapse">
          <tr style="background:#f3f4f6">
            <th style="padding:3px 6px;text-align:left">Kolom</th>
            <th style="padding:3px 6px;text-align:left">Keterangan</th>
          </tr>
          <?php foreach ([
            ['siswa_nis',       'NIS siswa — wajib'],
            ['mapel_nama',      'Nama mapel PERSIS (case-insensitive)'],
            ['kelas_nama',      'Nama kelas PERSIS (case-insensitive)'],
            ['tahun_ajaran_id', 'ID tahun ajaran (lihat Referensi)'],
            ['jenis_raport',    'bulanan / sts / semester (dropdown)'],
            ['bulan',           '1-12 (wajib untuk bulanan/sts)'],
            ['nh1,nh2,nh3',     'Nilai harian 1,2,3 (bulanan)'],
            ['tugas',           'Nilai tugas (bulanan)'],
            ['sts',             'Nilai STS (untuk jenis=sts)'],
            ['sas',             'Nilai SAS (untuk jenis=semester)'],
          ] as $c): ?>
          <tr style="border-bottom:1px solid #f3f4f6">
            <td style="padding:2px 6px;color:#f97316;font-family:monospace;font-weight:600;white-space:nowrap"><?= $c[0] ?></td>
            <td style="padding:2px 6px"><?= $c[1] ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <!-- Form Upload -->
    <div class="card">
      <div class="card-header">Upload File</div>
      <div class="card-body">
        <form method="POST" action="index.php?page=nilai.import" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label">Pilih File Excel / CSV <span class="text-red-500">*</span></label>
            <input type="file" name="excel_file" accept=".xlsx,.xls,.csv,.txt"
              class="form-control" style="padding:6px;font-size:.875rem" required>
            <p class="text-xs text-gray-400 mt-1">Format: .xlsx, .xls, .csv | Maks 10MB</p>
          </div>
          <div class="p-3 rounded-lg text-xs" style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e;margin-bottom:16px">
            <strong>⚠ Perhatian:</strong>
            <ul style="padding-left:16px;margin-top:4px;line-height:1.8">
              <li>Jika data sudah ada akan di-<strong>update</strong> (overwrite)</li>
              <li>Nama mapel &amp; kelas harus <strong>persis sama</strong> dengan database</li>
              <li>Lookup menggunakan <strong>NIS</strong>, bukan nama siswa</li>
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
    <div class="table-container" style="max-height:340px;overflow-y:auto">
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
