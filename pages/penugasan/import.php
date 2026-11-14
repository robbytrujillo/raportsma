<?php
/**
 * pages/penugasan/import.php
 * Template Excel (.xlsx) & import penugasan guru
 */
require_role(['admin','super_admin']);
require_once __DIR__ . '/../../excel.php';

// Referensi
$user      = current_user();
$_unit_f   = get_user_unit_filter($user);
$_unit_sql = $_unit_f['sql'];
$_gunit_f  = get_user_unit_filter($user, 'g');

// Guru: filter per unit (alias g = tabel guru, sama seperti index.php)
$_guru_where = "u.role IN ('guru','wali_kelas')";
if ($_gunit_f['sql'] !== '') { $_guru_where .= ' ' . $_gunit_f['sql']; }
$guru_ref  = db_query("SELECT u.id, u.nama_lengkap FROM users u LEFT JOIN guru g ON g.user_id = u.id WHERE {$_guru_where} ORDER BY u.nama_lengkap");

$mapel_ref = db_query('SELECT id, kode_mapel, nama_mapel FROM mapel ORDER BY nama_mapel');
$ta_aktif  = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_list   = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');

// Kelas: filter per unit (alias k = tabel kelas)
$kelas_ref = db_query("SELECT k.id, k.nama_kelas, ta.id as ta_id, ta.nama as ta_nama, ta.semester FROM kelas k LEFT JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id WHERE 1=1 {$_unit_sql} ORDER BY ta.id DESC, k.nama_kelas", []);

// Filter kelas sesuai TA aktif (untuk kolom kelas template)
$ta_id_aktif = $ta_aktif['id'] ?? 0;
$kelas_aktif = array_filter($kelas_ref, fn($k) => $k['ta_id'] == $ta_id_aktif);

/* ── Download template Excel ───────────────────────────────────────────── */
if (isset($_GET['download_template'])) {
    if (ob_get_level()) ob_end_clean();

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()->setCreator('Raportku')->setTitle('Template Import Penugasan Guru');

    // ── Warna tema ──────────────────────────────────────────────
    $orange   = 'FFF97316';
    $white    = 'FFFFFFFF';
    $redBg    = 'FFFEE2E2';
    $redFont  = 'FFDC2626';
    $border   = 'FFE5E7EB';
    $altRow   = 'FFFFF7ED';

    // ── Sheet Data langsung diambil & diberi nama sekarang ───────
    // Hindari sheet "Worksheet" default yang tersisa sebagai tab kosong
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Data');

    // ── Helper: buat sheet referensi ────────────────────────────
    $makeRefSheet = function(\PhpOffice\PhpSpreadsheet\Spreadsheet $ss, string $title, array $headers, array $rows) use ($orange, $white, $border, $altRow): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet {
        $ws = $ss->createSheet();
        $ws->setTitle(substr($title, 0, 31));
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));

        $ws->mergeCells("A1:{$lastCol}1");
        $ws->setCellValue('A1', $title);
        $ws->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold'=>true,'size'=>12,'color'=>['argb'=>$white]],
            'fill'      => ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>$orange]],
            'alignment' => ['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        foreach ($headers as $hi => $hLabel) {
            $hCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hi + 1);
            $ws->setCellValue("{$hCol}2", $hLabel);
            $ws->getStyle("{$hCol}2")->applyFromArray([
                'font' => ['bold'=>true,'color'=>['argb'=>$white]],
                'fill' => ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>$orange]],
            ]);
            $ws->getColumnDimension($hCol)->setAutoSize(true);
        }

        foreach ($rows as $ri => $row) {
            $excelR = $ri + 3;
            $bg = ($ri % 2 === 0) ? $altRow : $white;
            foreach ($row as $ci => $val) {
                $cLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
                $ws->setCellValue("{$cLtr}{$excelR}", $val);
            }
            $lastC = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($row));
            $ws->getStyle("A{$excelR}:{$lastC}{$excelR}")->applyFromArray([
                'fill'    => ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>$bg]],
                'borders' => ['allBorders'=>['borderStyle'=>\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,'color'=>['argb'=>$border]]],
            ]);
        }
        return $ws;
    };

    // ── Buat sheet referensi (setelah Data) ─────────────────────
    // Sheet Guru
    $guruRows   = array_map(fn($r) => [$r['nama_lengkap']], $guru_ref);
    $refGuru    = $makeRefSheet($spreadsheet, 'Ref Guru', ['Nama Guru'], $guruRows);
    $guruCount  = count($guruRows);

    // Sheet Mapel
    $mapelNames = array_map(fn($r) => $r['kode_mapel'] ? "[{$r['kode_mapel']}] {$r['nama_mapel']}" : $r['nama_mapel'], $mapel_ref);
    $mapelRows  = array_map(fn($name) => [$name], $mapelNames);
    $refMapel   = $makeRefSheet($spreadsheet, 'Ref Mapel', ['Nama Mapel'], $mapelRows);
    $mapelCount = count($mapelRows);

    // Sheet Kelas (hanya TA aktif)
    $kelasNames = array_values(array_map(fn($k) => $k['nama_kelas'], $kelas_aktif));
    $kelasRows  = array_map(fn($name) => [$name], $kelasNames);
    $refKelas   = $makeRefSheet($spreadsheet, 'Ref Kelas', ['Nama Kelas'], $kelasRows);
    $kelasCount = count($kelasRows);

    // ── Kembali ke sheet Data untuk isi konten ───────────────────
    $spreadsheet->setActiveSheetIndex(0); // Data selalu index 0

    // Baris 1: Judul
    $sheet->mergeCells('A1:C1');
    $sheet->setCellValue('A1', 'Template Import Penugasan Guru -- Raportku');
    $sheet->getStyle('A1:C1')->applyFromArray([
        'font'      => ['bold'=>true,'size'=>14,'color'=>['argb'=>$white]],
        'fill'      => ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>$orange]],
        'alignment' => ['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'vertical'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(32);

    // Baris 2: Info TA aktif
    $taNama = $ta_aktif ? ($ta_aktif['nama'].' Sem.'.$ta_aktif['semester']) : '(tidak ada TA aktif)';
    $sheet->mergeCells('A2:C2');
    $sheet->setCellValue('A2', "Tahun Ajaran: {$taNama}  |  Satu baris = satu penugasan  |  Pilih dari dropdown");
    $sheet->getStyle('A2:C2')->applyFromArray([
        'font'      => ['italic'=>true,'size'=>10,'color'=>['argb'=>'FF475569']],
        'fill'      => ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>'FFF8F9FA']],
        'alignment' => ['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'vertical'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(22);

    // Baris 3: Header kolom
    $headers = [
        'A' => ['label'=>'Nama Guru *',        'width'=>38, 'bg'=>$redBg,  'font'=>$redFont],
        'B' => ['label'=>'Nama Mapel *',        'width'=>36, 'bg'=>$redBg,  'font'=>$redFont],
        'C' => ['label'=>'Nama Kelas *',        'width'=>20, 'bg'=>$redBg,  'font'=>$redFont],
    ];
    foreach ($headers as $col => $h) {
        $sheet->setCellValue("{$col}3", $h['label']);
        $sheet->getStyle("{$col}3")->applyFromArray([
            'font'      => ['bold'=>true,'size'=>11,'color'=>['argb'=>$h['font']]],
            'fill'      => ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['argb'=>$h['bg']]],
            'alignment' => ['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'vertical'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders'=>['borderStyle'=>\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,'color'=>['argb'=>$border]]],
        ]);
        $sheet->getColumnDimension($col)->setWidth($h['width']);
    }
    $sheet->getRowDimension(3)->setRowHeight(24);

    // ── Dropdown validasi — formula langsung ke sheet referensi ──
    // Format Excel: 'NamaSheet'!$A$3:$A$N  (tanda kutip wajib jika nama sheet ada spasi)
    $applyDropdown = function(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, string $range, string $formula1, string $promptTitle) {
        $dv = $ws->getDataValidation($range);
        $dv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
        $dv->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
        $dv->setAllowBlank(true);
        $dv->setShowDropDown(true); // PhpSpreadsheet: true = sembunyikan di XML = tampil di Excel
        $dv->setShowInputMessage(true);
        $dv->setShowErrorMessage(true);
        $dv->setErrorTitle('Nilai tidak valid');
        $dv->setError('Pilih dari daftar yang tersedia.');
        $dv->setPromptTitle($promptTitle);
        $dv->setPrompt('Klik dropdown untuk memilih.');
        $dv->setFormula1($formula1);
    };

    if ($guruCount  > 0) $applyDropdown($sheet, 'A4:A1000', "'Ref Guru'!\$A\$3:\$A\$" . ($guruCount  + 2), 'Nama Guru');
    if ($mapelCount > 0) $applyDropdown($sheet, 'B4:B1000', "'Ref Mapel'!\$A\$3:\$A\$" . ($mapelCount + 2), 'Nama Mapel');
    if ($kelasCount > 0) $applyDropdown($sheet, 'C4:C1000', "'Ref Kelas'!\$A\$3:\$A\$" . ($kelasCount + 2), 'Nama Kelas');

    // Format teks agar tidak auto-konversi
    $sheet->getStyle('A4:C1000')->getNumberFormat()->setFormatCode('@');

    // Border area header+data
    $sheet->getStyle('A3:C3')->applyFromArray([
        'borders' => ['outline' => ['borderStyle'=>\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,'color'=>['argb'=>$orange]]],
    ]);

    // Freeze baris header
    $sheet->freezePane('A4');

    // Aktifkan sheet Data saat file dibuka
    $spreadsheet->setActiveSheetIndex(0);

    // ── Kirim ke browser ─────────────────────────────────────────
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_penugasan.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    exit;
}

/* ── Proses Import ────────────────────────────────────────────────────── */
$results   = [];
$errors    = [];
$successes = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    if (!verify_csrf()) {
        $errors[] = 'Token tidak valid.';
    } else {
        // Tahun ajaran dari POST (atau aktif sebagai fallback)
        $import_ta_id = (int)($_POST['tahun_ajaran_id'] ?? ($ta_aktif['id'] ?? 0));

        $parsed = excel_parse($_FILES['excel_file'], 3);

        if (!$parsed['ok']) {
            $errors[] = $parsed['error'];
        } else {
            // Cache lookup: nama → id
            $guru_map  = [];
            foreach ($guru_ref as $g) {
                $guru_map[mb_strtolower(trim($g['nama_lengkap']))] = $g['id'];
            }

            $mapel_map = [];
            foreach ($mapel_ref as $m) {
                $label = $m['kode_mapel'] ? "[{$m['kode_mapel']}] {$m['nama_mapel']}" : $m['nama_mapel'];
                $mapel_map[mb_strtolower(trim($label))]          = $m['id'];
                $mapel_map[mb_strtolower(trim($m['nama_mapel']))] = $m['id']; // fallback by name only
            }

            $kelas_map = [];
            foreach ($kelas_ref as $k) {
                $kelas_map[mb_strtolower(trim($k['nama_kelas']))] = ['id'=>$k['id'],'ta_id'=>$k['ta_id']];
            }

            foreach ($parsed['rows'] as $i => $row) {
                $line       = $i + 4;
                $guru_name  = trim(excel_str($row, 0, 200));
                $mapel_name = trim(excel_str($row, 1, 200));
                $kelas_name = trim(excel_str($row, 2, 100));

                if (!$guru_name && !$mapel_name && !$kelas_name) continue; // baris kosong

                if (!$guru_name)  { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"Baris $line: Nama guru kosong"]; continue; }
                if (!$mapel_name) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"Baris $line: Nama mapel kosong"]; continue; }
                if (!$kelas_name) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"Baris $line: Nama kelas kosong"]; continue; }

                $guru_id = $guru_map[mb_strtolower($guru_name)] ?? null;
                if (!$guru_id) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"Baris $line: Guru \"{$guru_name}\" tidak ditemukan"]; continue; }

                $mapel_id = $mapel_map[mb_strtolower($mapel_name)] ?? null;
                if (!$mapel_id) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"Baris $line: Mapel \"{$mapel_name}\" tidak ditemukan"]; continue; }

                $kelas_info = $kelas_map[mb_strtolower($kelas_name)] ?? null;
                if (!$kelas_info) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"Baris $line: Kelas \"{$kelas_name}\" tidak ditemukan"]; continue; }

                $kelas_id = $kelas_info['id'];
                $ta_id    = $import_ta_id ?: $kelas_info['ta_id'];

                try {
                    db_execute(
                        'INSERT INTO guru_mapel_kelas (guru_user_id, mapel_id, kelas_id, tahun_ajaran_id) VALUES (:g,:m,:k,:ta)',
                        [':g'=>$guru_id, ':m'=>$mapel_id, ':k'=>$kelas_id, ':ta'=>$ta_id]
                    );
                    $results[] = ['line'=>$line,'status'=>'ok','msg'=>"{$guru_name} → {$mapel_name} / {$kelas_name} -- ditambahkan"];
                    $successes++;
                } catch (\PDOException $e) {
                    $results[] = ['line'=>$line,'status'=>'error','msg'=>"Baris $line: Penugasan sudah ada atau data tidak valid."];
                }
            }
            if ($successes > 0) flash('success', "$successes penugasan berhasil diimport.");
        }
    }
}
?>
<div class="page-content" style="max-width:860px;margin:0 auto">
    <div class="page-header">
        <div>
            <h1 class="page-title">Import Penugasan Guru</h1>
            <p class="page-subtitle">Upload file Excel (.xlsx) untuk menambahkan penugasan guru sekaligus</p>
        </div>
        <a href="index.php?page=penugasan" class="btn btn-secondary" style="border-radius: 30px">Kembali</a>
    </div>

    <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div class="card">
            <div class="card-header">
                <span>Petunjuk</span>
                <a href="index.php?page=penugasan.import&download_template=1" class="btn btn-sm btn-primary"
                    style="border-radius: 30px">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    Download Template Excel
                </a>
            </div>
            <div class="card-body text-sm" style="line-height:1.8">
                <ol style="padding-left:18px;color:#374151">
                    <li>Klik <strong>Download Template Excel</strong></li>
                    <li>Isi data mulai baris ke-<strong>4</strong></li>
                    <li>Kolom <strong>Nama Guru</strong> — pilih dari dropdown</li>
                    <li>Kolom <strong>Nama Mapel</strong> — pilih dari dropdown (format: <code>[KODE] Nama</code>)</li>
                    <li>Kolom <strong>Nama Kelas</strong> — pilih dari dropdown (kelas TA aktif)</li>
                    <li>Tahun Ajaran otomatis mengikuti pengaturan halaman penugasan</li>
                </ol>

                <div class="p-3 mt-3 rounded-lg text-xs"
                    style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534">
                    <strong>✔ Didukung:</strong> .xlsx, .xls, .csv<br>
                    <strong>✔ Dropdown</strong> langsung di setiap kolom<br>
                    <strong>✔ Duplikat</strong> otomatis dilewati
                </div>

                <div class="divider"></div>
                <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                    <tr style="background:#f3f4f6">
                        <th style="padding:5px 8px;text-align:left">Kolom</th>
                        <th style="padding:5px 8px;text-align:left">Keterangan</th>
                    </tr>
                    <?php foreach ([
                        ['Nama Guru',  'Nama lengkap guru (dropdown) -- wajib'],
                        ['Nama Mapel', 'Nama mapel, format [KODE] Nama (dropdown) -- wajib'],
                        ['Nama Kelas', 'Nama kelas sesuai TA aktif (dropdown) -- wajib'],
                    ] as $c): ?>
                    <tr style="border-bottom:1px solid #f3f4f6">
                        <td style="padding:4px 8px;color:#f97316;font-weight:600"><?= $c[0] ?></td>
                        <td style="padding:4px 8px"><?= $c[1] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <?php if ($ta_aktif): ?>
                <div class="p-3 mt-3 rounded-lg text-xs" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af">
                    <strong>Tahun Ajaran aktif:</strong> <?= e($ta_aktif['nama']) ?> Sem.<?= $ta_aktif['semester'] ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Upload File</div>
            <div class="card-body">
                <form method="POST" action="index.php?page=penugasan.import" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label">Tahun Ajaran <span class="text-red-500">*</span></label>
                        <select name="tahun_ajaran_id" class="form-control" required>
                            <?php foreach ($ta_list as $ta): ?>
                            <option value="<?= $ta['id'] ?>" <?= ($ta['id']==$ta_id_aktif)?'selected':'' ?>>
                                <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?><?= $ta['is_aktif'] ? ' ✓' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Penugasan akan dimasukkan ke tahun ajaran ini</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">File Excel / CSV <span class="text-red-500">*</span></label>
                        <input type="file" name="excel_file" accept=".xlsx,.xls,.csv,.txt" class="form-control"
                            style="padding:6px" required>
                        <p class="text-xs text-gray-400 mt-1">Format: .xlsx, .xls, .csv | Maks 10MB</p>
                    </div>
                    <div class="p-3 rounded-lg text-xs mb-4"
                        style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e">
                        <strong>⚠️ Catatan:</strong> Penugasan duplikat akan dilewati otomatis.
                    </div>
                    <button type="submit" class="btn btn-primary w-full justify-center" style="border-radius: 30px">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                            <polyline points="17 8 12 3 7 8" />
                            <line x1="12" y1="3" x2="12" y2="15" />
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
                <thead>
                    <tr>
                        <th>Baris</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td class="text-gray-400 text-xs"><?= $r['line'] ?></td>
                        <td><?php
                            if ($r['status']==='ok')       echo '<span class="badge badge-green">✔ OK</span>';
                            elseif ($r['status']==='skip')  echo '<span class="badge badge-gray">-- Skip</span>';
                            else                            echo '<span class="badge badge-red">✖ Error</span>';
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
