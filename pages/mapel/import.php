<?php
/**
 * pages/mapel/import.php
 * Template Excel (.xlsx) & import mata pelajaran
 */
require_role('admin');
require_once __DIR__ . '/../../excel.php';

// Daftar mapel induk untuk referensi
$mapel_induk = db_query("SELECT id, nama_mapel, kelompok FROM mapel WHERE parent_id IS NULL ORDER BY kelompok, urutan");

/* ── Download template Excel ─────────────────────────────────── */
if (isset($_GET['download_template'])) {
    $ref_rows = [];
    foreach ($mapel_induk as $m) {
        $ref_rows[] = [$m['id'], $m['nama_mapel'], $m['kelompok']];
    }

    xlsx_template('template_mapel.xlsx', [
        'title'    => 'Template Import Mata Pelajaran — Raportku',
        'subtitle' => '★ = wajib diisi  |  Import mapel INDUK dulu, lalu sub-mapel  |  Lihat sheet "Referensi" untuk parent_id',
        'columns'  => [
            ['header'=>'Nama Mapel',  'field'=>'nama_mapel',  'width'=>32, 'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>'Bahasa Indonesia', 'note'=>'Nama lengkap mata pelajaran'],
            ['header'=>'Kode Mapel',  'field'=>'kode_mapel',  'width'=>12, 'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'BIND', 'note'=>'Kode singkat, misal: MTK, BIND, PAI'],
            ['header'=>'Kelompok',    'field'=>'kelompok',    'width'=>18, 'required'=>true,  'type'=>'dropdown',
             'dropdown'=>['A','B','C','C Pilihan','D','Muatan Lokal'],
             'example'=>'A', 'note'=>'Pilih: A / B / C / C Pilihan / D / Muatan Lokal'],
            ['header'=>'Parent ID',   'field'=>'parent_id',   'width'=>12, 'required'=>false, 'type'=>'number',
             'example'=>'', 'note'=>'ID mapel induk jika ini sub-mapel. Lihat sheet Referensi. Kosongkan jika mapel mandiri.'],
            ['header'=>'Sub Label',   'field'=>'sub_label',   'width'=>10, 'required'=>false, 'type'=>'text','format'=>'@',
             'example'=>'', 'note'=>'Label huruf sub-mapel: a, b, c, d. Kosongkan jika mapel mandiri.'],
            ['header'=>'Urutan',      'field'=>'urutan',      'width'=>10, 'required'=>false, 'type'=>'number',
             'example'=>'1', 'note'=>'Angka urutan tampil di raport (0, 1, 2, ...)'],
        ],
        'examples' => [
            ['Pendidikan Agama Islam', 'PAI',  'A', '', '', '1'],
            ['Tauhid/Aqidah',          'TAU',  'A', '',  'a', '2'],
            ['Matematika',             'MTK',  'A', '', '', '7'],
            ['Bahasa Arab',            'BARB', 'B', '', '', '1'],
        ],
        'notes_sheet' => [
            'title'   => 'Daftar Mapel Induk — gunakan angka di kolom "ID" sebagai Parent ID untuk sub-mapel',
            'headers' => ['ID','Nama Mapel Induk','Kelompok'],
            'rows'    => $ref_rows ?: [['(belum ada mapel induk)', '', '']],
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
            $valid_kelompok = ['A', 'B', 'C', 'C Pilihan', 'D', 'Muatan Lokal'];

            foreach ($parsed['rows'] as $i => $row) {
                $line     = $i + 4;
                $nama     = excel_str($row, 0, 150);
                $kode     = excel_str($row, 1, 20);
                $kelompok = excel_str($row, 2, 20) ?: 'A';
                $parent   = excel_int($row, 3);
                $sublabel = excel_str($row, 4, 3);
                $urutan   = excel_int($row, 5);

                if (!$nama) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>'Nama mapel kosong — dilewati']; continue; }
                if (!in_array($kelompok, $valid_kelompok)) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: kelompok '$kelompok' tidak valid (A/B/C/C Pilihan/D/Muatan Lokal)"]; continue;
                }

                $parent_id = null;
                if ($parent > 0) {
                    if (!db_row('SELECT id FROM mapel WHERE id=:id',[':id'=>$parent])) {
                        $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: parent_id $parent tidak ditemukan"]; continue;
                    }
                    $parent_id = $parent;
                }

                try {
                    db_execute(
                        'INSERT INTO mapel (nama_mapel,kode_mapel,kelompok,parent_id,sub_label,urutan)
                         VALUES (:n,:k,:g,:pid,:sl,:u)',
                        [':n'=>$nama,':k'=>$kode,':g'=>$kelompok,
                         ':pid'=>$parent_id,':sl'=>($sublabel?:null),':u'=>$urutan]
                    );
                    $results[] = ['line'=>$line,'status'=>'ok','msg'=>"$nama — ditambahkan (Kelompok $kelompok)"];
                    $successes++;
                } catch (\PDOException $e) {
                    $results[] = ['line'=>$line,'status'=>'error','msg'=>"$nama: " . $e->getMessage()];
                }
            }
            if ($successes > 0) flash('success', "$successes mata pelajaran berhasil diimport.");
        }
    }
}
?>
<div class="page-content" style="max-width:860px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title">Import Mata Pelajaran</h1>
      <p class="page-subtitle">Upload file Excel (.xlsx) untuk menambahkan mata pelajaran sekaligus</p>
    </div>
    <a href="index.php?page=mapel" class="btn btn-secondary">← Kembali</a>
  </div>

  <?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div class="card">
      <div class="card-header">
        <span>Petunjuk</span>
        <a href="index.php?page=mapel.import&download_template=1" class="btn btn-sm btn-primary">
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
          <li>Kolom <strong>Kelompok</strong> sudah ada dropdown: A / B / C / C Pilihan / D / Muatan Lokal</li>
          <li>Import mapel <strong>induk dulu</strong>, lalu sub-mapelnya</li>
          <li>Lihat sheet <strong>"Referensi"</strong> untuk Parent ID sub-mapel</li>
        </ol>

        <div class="p-3 mt-3 rounded-lg text-xs" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534">
          <strong>✓ Didukung:</strong> .xlsx, .xls, .csv
          <br><strong>✓ Dropdown</strong> di kolom Kelompok
          <br><strong>✓ Sheet "Referensi"</strong> berisi daftar mapel induk & ID-nya
        </div>

        <div class="divider"></div>
        <table style="width:100%;border-collapse:collapse;font-size:.8rem">
          <tr style="background:#f3f4f6">
            <th style="padding:5px 8px;text-align:left">Kolom</th>
            <th style="padding:5px 8px;text-align:left">Keterangan</th>
          </tr>
          <?php foreach ([
            ['nama_mapel','Nama lengkap mata pelajaran — wajib'],
            ['kode_mapel','Kode singkat misal MTK, BIND — opsional'],
            ['kelompok','A / B / C / C Pilihan / D / Muatan Lokal (dropdown)'],
            ['parent_id','ID mapel induk (kosongkan jika mapel mandiri)'],
            ['sub_label','Label huruf: a, b, c (untuk sub-mapel)'],
            ['urutan','Angka urutan di raport'],
          ] as $c): ?>
          <tr style="border-bottom:1px solid #f3f4f6">
            <td style="padding:4px 8px;color:#f97316;font-family:monospace;font-weight:600"><?= $c[0] ?></td>
            <td style="padding:4px 8px"><?= $c[1] ?></td>
          </tr>
          <?php endforeach; ?>
        </table>

        <?php if (!empty($mapel_induk)): ?>
        <div class="divider"></div>
        <p class="font-semibold text-xs text-gray-700 mb-1">Mapel induk saat ini (parent_id):</p>
        <div style="max-height:120px;overflow-y:auto">
          <table style="width:100%;font-size:.75rem;border-collapse:collapse">
            <tr style="background:#f3f4f6"><th style="padding:3px 6px">ID</th><th style="padding:3px 6px">Nama</th><th style="padding:3px 6px">Kel.</th></tr>
            <?php foreach ($mapel_induk as $m): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
              <td style="padding:2px 6px;font-weight:700;color:#f97316"><?= $m['id'] ?></td>
              <td style="padding:2px 6px"><?= e($m['nama_mapel']) ?></td>
              <td style="padding:2px 6px"><?= e($m['kelompok']) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Upload File</div>
      <div class="card-body">
        <form method="POST" action="index.php?page=mapel.import" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label">File Excel / CSV <span class="text-red-500">*</span></label>
            <input type="file" name="excel_file" accept=".xlsx,.xls,.csv,.txt"
              class="form-control" style="padding:6px" required>
            <p class="text-xs text-gray-400 mt-1">Format: .xlsx, .xls, .csv | Maks 10MB</p>
          </div>
          <div class="p-3 rounded-lg text-xs mb-4" style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e">
            <strong>⚠ Tips:</strong> Import mapel <strong>induk dulu</strong>, baru sub-mapelnya,
            karena parent_id merujuk ke ID mapel induk yang sudah ada di database.
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
    <div class="table-container" style="max-height:280px;overflow-y:auto">
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
