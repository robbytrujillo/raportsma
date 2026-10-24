<?php
/**
 * pages/kelas/import.php
 * Template Excel (.xlsx) & import data kelas
 */
require_role('admin');
require_once __DIR__ . '/../../excel.php';

$ta_list   = db_query('SELECT * FROM tahun_ajaran ORDER BY is_aktif DESC, id DESC');
$wali_list = db_query(
    "SELECT u.id, u.nama_lengkap, g.status FROM users u
     LEFT JOIN guru g ON g.user_id = u.id
     WHERE u.role IN ('wali_kelas','guru') ORDER BY u.nama_lengkap"
);

/* ── Download template Excel ─────────────────────────────────── */
if (isset($_GET['download_template'])) {
    $ta_ex   = $ta_list[0] ?? ['id'=>1,'nama'=>'2024/2025','semester'=>1];
    $tingkat_opts = ['VII','VIII','IX','X','XI','XII'];

    // Baris referensi tahun ajaran
    $ta_ref = [];
    foreach ($ta_list as $ta) {
        $ta_ref[] = [$ta['id'], $ta['nama'], 'Sem.'.$ta['semester'], $ta['is_aktif'] ? '★ Aktif' : ''];
    }
    // Baris referensi wali kelas
    $wali_ref = [];
    foreach ($wali_list as $w) {
        $wali_ref[] = [$w['id'], $w['nama_lengkap'], $w['status'] ?? 'guru'];
    }

    xlsx_template('template_kelas.xlsx', [
        'title'    => 'Template Import Data Kelas — Raportku',
        'subtitle' => '★ = wajib diisi  |  Lihat sheet "Referensi" untuk ID tahun ajaran dan wali kelas',
        'columns'  => [
            ['header'=>'Nama Kelas',      'field'=>'nama_kelas',      'width'=>20, 'required'=>true,  'type'=>'text','format'=>'@',
             'example'=>'X IPA 1', 'note'=>'Nama kelas, misal: X IPA 1, XI IPS 2'],
            ['header'=>'Tingkat',         'field'=>'tingkat',         'width'=>12, 'required'=>true,  'type'=>'dropdown',
             'dropdown'=>$tingkat_opts,
             'example'=>'X', 'note'=>'Pilih tingkat kelas dari dropdown'],
            ['header'=>'Tahun Ajaran ID', 'field'=>'tahun_ajaran_id', 'width'=>16, 'required'=>true,  'type'=>'number',
             'example'=>$ta_ex['id'], 'note'=>'ID tahun ajaran dari sheet Referensi'],
            ['header'=>'Wali Kelas ID',   'field'=>'wali_kelas_id',   'width'=>14, 'required'=>false, 'type'=>'number',
             'example'=>'', 'note'=>'ID guru/wali kelas dari sheet Referensi. Kosongkan jika belum ditentukan.'],
        ],
        'examples' => [
            ['X IPA 2',  'X',   $ta_ex['id'], ''],
            ['XI IPS 1', 'XI',  $ta_ex['id'], ''],
            ['XII IPA',  'XII', $ta_ex['id'], ''],
        ],
        'notes_sheet' => [
            'title'   => 'Referensi ID — Tahun Ajaran & Wali Kelas',
            'headers' => ['ID Tahun Ajaran','Nama','Semester','Status','','ID Guru/Wali','Nama Guru','Status Guru'],
            'rows'    => array_map(function($i) use ($ta_ref, $wali_ref) {
                $ta  = $ta_ref[$i]  ?? ['','','',''];
                $wal = $wali_ref[$i] ?? ['','',''];
                return [$ta[0],$ta[1],$ta[2],$ta[3],'', $wal[0],$wal[1],$wal[2]];
            }, range(0, max(count($ta_ref), count($wali_ref)) - 1)),
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
            foreach ($parsed['rows'] as $i => $row) {
                $line     = $i + 4;
                $nama     = excel_str($row, 0, 100);
                $tingkat  = excel_str($row, 1, 10);
                $ta_id    = excel_int($row, 2);
                $wali_id  = excel_int($row, 3);

                if (!$nama)    { $results[] = ['line'=>$line,'status'=>'skip','msg'=>'Nama kelas kosong — dilewati']; continue; }
                if (!$tingkat) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: tingkat kosong — dilewati"]; continue; }
                if (!$ta_id)   { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: tahun_ajaran_id kosong — dilewati"]; continue; }

                if (!db_row('SELECT id FROM tahun_ajaran WHERE id=:id',[':id'=>$ta_id])) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: tahun_ajaran_id $ta_id tidak ditemukan"]; continue;
                }

                $wali_id_save = null;
                if ($wali_id) {
                    if (!db_row('SELECT id FROM users WHERE id=:id',[':id'=>$wali_id])) {
                        $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: wali_kelas_id $wali_id tidak ditemukan"]; continue;
                    }
                    $wali_id_save = $wali_id;
                }

                if (db_row('SELECT id FROM kelas WHERE nama_kelas=:n AND tahun_ajaran_id=:ta',[':n'=>$nama,':ta'=>$ta_id])) {
                    $results[] = ['line'=>$line,'status'=>'skip','msg'=>"$nama: sudah ada di tahun ajaran tersebut"]; continue;
                }

                try {
                    db_execute(
                        'INSERT INTO kelas (nama_kelas,tingkat,wali_kelas_id,tahun_ajaran_id) VALUES (:n,:t,:w,:ta)',
                        [':n'=>$nama,':t'=>$tingkat,':w'=>$wali_id_save,':ta'=>$ta_id]
                    );
                    $results[] = ['line'=>$line,'status'=>'ok','msg'=>"$nama (Tingkat $tingkat) — berhasil ditambahkan"];
                    $successes++;
                } catch (\PDOException $e) {
                    $results[] = ['line'=>$line,'status'=>'error','msg'=>"$nama: " . $e->getMessage()];
                }
            }
            if ($successes > 0) flash('success', "$successes kelas berhasil diimport.");
        }
    }
}
?>
<div class="page-content" style="max-width:860px;margin:0 auto">
  <div class="page-header">
    <div>
      <h1 class="page-title">Import Data Kelas</h1>
      <p class="page-subtitle">Upload file Excel (.xlsx) untuk menambahkan banyak kelas sekaligus</p>
    </div>
    <a href="index.php?page=kelas" class="btn btn-secondary">← Kembali</a>
  </div>

  <?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div class="card">
      <div class="card-header">
        <span>Langkah Import</span>
        <a href="index.php?page=kelas.import&download_template=1" class="btn btn-sm btn-primary">
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
          <li>Kolom <strong>Tingkat</strong> sudah ada dropdown (VII–XII)</li>
          <li>Lihat sheet <strong>"Referensi"</strong> untuk ID tahun ajaran & wali kelas</li>
          <li>Upload file .xlsx</li>
        </ol>

        <div class="p-3 mt-3 rounded-lg text-xs" style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534">
          <strong>✓ Didukung:</strong> .xlsx, .xls, .csv
          <br><strong>✓ Dropdown Tingkat</strong>: VII, VIII, IX, X, XI, XII
          <br><strong>✓ Sheet "Referensi"</strong>: ID Tahun Ajaran + Wali Kelas dalam 1 sheet
        </div>

        <div class="divider"></div>
        <p class="font-semibold text-xs text-gray-700 mb-1">Tahun Ajaran:</p>
        <table style="width:100%;font-size:.75rem;border-collapse:collapse;margin-bottom:8px">
          <tr style="background:#f3f4f6">
            <th style="padding:3px 6px;text-align:left">ID</th>
            <th style="padding:3px 6px;text-align:left">Nama</th>
            <th style="padding:3px 6px;text-align:left">Sem</th>
          </tr>
          <?php foreach ($ta_list as $ta): ?>
          <tr style="border-bottom:1px solid #f3f4f6">
            <td style="padding:2px 6px;font-weight:700;color:#f97316"><?= $ta['id'] ?></td>
            <td style="padding:2px 6px"><?= e($ta['nama']) ?><?= $ta['is_aktif'] ? ' ★' : '' ?></td>
            <td style="padding:2px 6px"><?= $ta['semester'] ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <p class="font-semibold text-xs text-gray-700 mb-1">Wali Kelas <small style="font-weight:400;color:#9ca3af">(opsional)</small>:</p>
        <div style="max-height:100px;overflow-y:auto">
          <table style="width:100%;font-size:.75rem;border-collapse:collapse">
            <tr style="background:#f3f4f6">
              <th style="padding:3px 6px;text-align:left">ID</th>
              <th style="padding:3px 6px;text-align:left">Nama</th>
            </tr>
            <?php foreach ($wali_list as $w): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
              <td style="padding:2px 6px;font-weight:700;color:#f97316"><?= $w['id'] ?></td>
              <td style="padding:2px 6px"><?= e($w['nama_lengkap']) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Upload File</div>
      <div class="card-body">
        <form method="POST" action="index.php?page=kelas.import" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label">Pilih File Excel / CSV <span class="text-red-500">*</span></label>
            <input type="file" name="excel_file" accept=".xlsx,.xls,.csv,.txt"
              class="form-control" style="padding:6px;font-size:.875rem" required>
            <p class="text-xs text-gray-400 mt-1">Format: .xlsx, .xls, .csv | Maks 10MB</p>
          </div>
          <div class="p-3 rounded-lg text-xs" style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e;margin-bottom:16px">
            <strong>⚠ Perhatian:</strong>
            <ul style="padding-left:16px;margin-top:4px;line-height:1.7">
              <li>Kelas dengan nama & tahun ajaran yang sama akan <strong>dilewati</strong></li>
              <li>Kolom Wali Kelas ID boleh dikosongkan</li>
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
