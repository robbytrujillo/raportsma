<?php
/**
 * excel.php
 * Helper untuk generate template Excel (.xlsx) dan parse upload Excel/CSV
 * Menggunakan PhpSpreadsheet untuk format .xlsx yang proper
 *
 * Cara pakai:
 *   // Download template Excel dengan styling
 *   xlsx_template('template_siswa.xlsx', $config);
 *
 *   // Parse file yang diupload (otomatis deteksi .xlsx / .csv)
 *   $result = excel_parse($_FILES['file']);
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

// ── Warna tema Raportku ──────────────────────────────────────────
const XLS_COLOR_ORANGE  = 'FFF97316'; // orange-500 Tailwind
const XLS_COLOR_HEADER  = 'FFFFFFFF'; // teks header (putih)
const XLS_COLOR_ALT_ROW = 'FFFFF7ED'; // baris ganjil (orange-50)
const XLS_COLOR_REQUIRED= 'FFFEF2F2'; // kolom wajib (red-50)
const XLS_COLOR_BORDER  = 'FFE5E7EB'; // border abu

/**
 * Generate dan download file template Excel (.xlsx) dengan styling lengkap.
 *
 * @param string $filename  Nama file yang didownload, misal 'template_siswa.xlsx'
 * @param array  $config    Konfigurasi template:
 *   'title'       => string  &mdash; Judul sheet (row 1)
 *   'subtitle'    => string  &mdash; Sub-judul (row 2)
 *   'columns'     => array   &mdash; Definisi kolom, setiap item:
 *       'header'   => string  &mdash; Label header kolom
 *       'field'    => string  &mdash; Nama field (kode)
 *       'width'    => int     &mdash; Lebar kolom (karakter, default 20)
 *       'required' => bool    &mdash; Tandai wajib? (default false)
 *       'example'  => string  &mdash; Nilai contoh di baris pertama data
 *       'note'     => string  &mdash; Komentar/tooltip di header
 *       'type'     => string  &mdash; 'text'|'number'|'date'|'dropdown'
 *       'dropdown' => array   &mdash; Opsi dropdown jika type=dropdown
 *       'format'   => string  &mdash; Format sel Excel misal '@' untuk teks, '0' angka
 *   'examples'    => array   &mdash; Array of arrays baris data contoh (opsional, tambahan row 2+)
 *   'notes_sheet' => array   &mdash; Konten sheet "Referensi" (opsional):
 *       'title'    => string
 *       'rows'     => [[col1, col2, ...], ...]
 *       'headers'  => [header1, header2, ...]
 */
function xlsx_template(string $filename, array $config): void {
    // Bersihkan buffer output
    if (ob_get_level()) ob_end_clean();

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Raportku')
        ->setTitle($config['title'] ?? 'Template Import')
        ->setDescription('Template import data &mdash; dibuat otomatis oleh Raportku');

    // ── Sheet utama ────────────────────────────────────────────
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Data');

    $columns    = $config['columns'] ?? [];
    $totalCols  = count($columns);
    $lastColLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalCols);

    // ── Baris 1: Judul ─────────────────────────────────────────
    $title    = $config['title']    ?? 'Template Import Data';
    $subtitle = $config['subtitle'] ?? 'Hapus baris contoh sebelum mengupload. Kolom merah = wajib diisi.';
    $sheet->mergeCells("A1:{$lastColLtr}1");
    $sheet->setCellValue('A1', $title);
    $sheet->getStyle('A1')->applyFromArray([
        'font'      => ['bold'=>true,'size'=>14,'color'=>['argb'=>XLS_COLOR_HEADER]],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>XLS_COLOR_ORANGE]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // ── Baris 2: Subtitle / petunjuk ───────────────────────────
    $sheet->mergeCells("A2:{$lastColLtr}2");
    $sheet->setCellValue('A2', $subtitle);
    $sheet->getStyle('A2')->applyFromArray([
        'font'      => ['italic'=>true,'size'=>9,'color'=>['argb'=>'FF92400E']],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFFFF7ED']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(22);

    // ── Baris 3: Header kolom ──────────────────────────────────
    foreach ($columns as $i => $col) {
        $colIdx = $i + 1;
        $colLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
        $isRequired = $col['required'] ?? false;

        // Isi header
        $headerText = ($col['header'] ?? $col['field'] ?? '');
        if ($isRequired) $headerText .= ' *';
        $sheet->setCellValue("{$colLtr}3", $headerText);

        // Style header
        $sheet->getStyle("{$colLtr}3")->applyFromArray([
            'font'      => ['bold'=>true,'size'=>10,'color'=>['argb'=>$isRequired ? 'FFDC2626' : XLS_COLOR_HEADER]],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>$isRequired ? 'FFFEE2E2' : XLS_COLOR_ORANGE]],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>XLS_COLOR_BORDER]]],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(24);

        // Lebar kolom
        $sheet->getColumnDimension($colLtr)->setWidth($col['width'] ?? 22);

        // Komentar tooltip di header
        if (!empty($col['note'])) {
            $comment = $sheet->getComment("{$colLtr}3");
            $comment->getText()->createTextRun($col['note']);
            $comment->setWidth('200pt');
            $comment->setHeight('80pt');
        }

        // Format sel data (kolom 4 ke bawah)
        if (!empty($col['format'])) {
            $sheet->getStyle("{$colLtr}4:{$colLtr}1000")
                ->getNumberFormat()->setFormatCode($col['format']);
        }

        // Dropdown validation
        if (($col['type'] ?? '') === 'dropdown' && !empty($col['dropdown'])) {
            $validation = $sheet->getDataValidation("{$colLtr}4:{$colLtr}1000");
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
            $validation->setAllowBlank(true);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(true);
            $validation->setShowDropDown(true);
            $validation->setErrorTitle('Nilai tidak valid');
            $validation->setError('Pilih dari daftar yang tersedia.');
            $validation->setPromptTitle($col['header'] ?? '');
            $validation->setPrompt('Klik dropdown untuk memilih nilai.');
            $validation->setFormula1('"' . implode(',', $col['dropdown']) . '"');
        }
    }

    // ── Baris data contoh ──────────────────────────────────────
    $exampleRows = $config['examples'] ?? [];

    // Tambahkan baris contoh ke-1 dari field 'example' masing-masing kolom
    $firstExample = [];
    foreach ($columns as $col) {
        $firstExample[] = $col['example'] ?? '';
    }
    if (array_filter($firstExample)) {
        array_unshift($exampleRows, $firstExample);
    }

    foreach ($exampleRows as $rowIdx => $rowData) {
        $excelRow = $rowIdx + 4; // row 4 = contoh pertama
        $isOdd    = ($rowIdx % 2 === 0);

        foreach ($columns as $colIdx => $col) {
            $colLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $val    = $rowData[$colIdx] ?? '';

            // Set tipe data sel agar NIS/NISN yang panjang tidak dijadikan float
            // PhpSpreadsheet v2.x: TYPE_NUMERIC tidak boleh diisi string kosong
            if (($col['type'] ?? '') === 'number') {
                if ($val !== '' && $val !== null) {
                    $sheet->setCellValueExplicit("{$colLtr}{$excelRow}", $val, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValue("{$colLtr}{$excelRow}", '');
                }
            } elseif (($col['type'] ?? '') === 'text' || ($col['format'] ?? '') === '@') {
                $sheet->setCellValueExplicit("{$colLtr}{$excelRow}", (string)$val, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue("{$colLtr}{$excelRow}", $val);
            }
        }

        // Warna baris alternating
        $bgColor = $isOdd ? XLS_COLOR_ALT_ROW : 'FFFFFFFF';
        $sheet->getStyle("A{$excelRow}:{$lastColLtr}{$excelRow}")->applyFromArray([
            'fill'    => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>$bgColor]],
            'font'    => ['size'=>9,'color'=>['argb'=>'FF6B7280'],'italic'=>true],
            'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>XLS_COLOR_BORDER]]],
        ]);
    }

    // ── Border seluruh tabel header ────────────────────────────
    $lastRow = 3 + count($exampleRows);
    $sheet->getStyle("A3:{$lastColLtr}{$lastRow}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>XLS_COLOR_BORDER]]],
    ]);

    // ── Freeze pane setelah header ─────────────────────────────
    $sheet->freezePane('A4');

    // ── Sheet referensi (opsional) ─────────────────────────────
    if (!empty($config['notes_sheet'])) {
        $ns = $config['notes_sheet'];
        $refSheet = $spreadsheet->createSheet();
        $refSheet->setTitle('Referensi');

        // Judul referensi
        $refTitle = $ns['title'] ?? 'Data Referensi';
        $refHeaders = $ns['headers'] ?? [];
        $refRows    = $ns['rows']    ?? [];
        $refLastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($refHeaders)));

        $refSheet->mergeCells("A1:{$refLastCol}1");
        $refSheet->setCellValue('A1', $refTitle);
        $refSheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold'=>true,'size'=>12,'color'=>['argb'=>XLS_COLOR_HEADER]],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>XLS_COLOR_ORANGE]],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
        ]);

        // Header kolom referensi
        foreach ($refHeaders as $hi => $hLabel) {
            $hCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($hi + 1);
            $refSheet->setCellValue("{$hCol}2", $hLabel);
            $refSheet->getStyle("{$hCol}2")->applyFromArray([
                'font' => ['bold'=>true,'color'=>['argb'=>XLS_COLOR_HEADER]],
                'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>XLS_COLOR_ORANGE]],
            ]);
            $refSheet->getColumnDimension($hCol)->setAutoSize(true);
        }

        // Baris data referensi
        foreach ($refRows as $ri => $rRow) {
            $excelR = $ri + 3;
            $bg = ($ri % 2 === 0) ? 'FFFFF7ED' : 'FFFFFFFF';
            foreach ($rRow as $ci => $cVal) {
                $cLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
                $refSheet->setCellValue("{$cLtr}{$excelR}", $cVal);
            }
            $refLastC = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($rRow));
            $refSheet->getStyle("A{$excelR}:{$refLastC}{$excelR}")->applyFromArray([
                'fill'    => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>$bg]],
                'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>XLS_COLOR_BORDER]]],
            ]);
        }
    }

    // ── Kembali ke sheet Data ──────────────────────────────────
    $spreadsheet->setActiveSheetIndex(0);

    // ── Kirim ke browser ───────────────────────────────────────
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    exit;
}

/**
 * Parse file Excel (.xlsx) atau CSV yang diupload via $_FILES.
 * Mengembalikan array baris data (skip header row 1).
 *
 * @param array $file          Entry dari $_FILES['namafield']
 * @param int   $header_rows   Jumlah baris header yang dilewati (default: 3 &mdash; untuk template kita)
 * @return array ['ok'=>bool, 'rows'=>array, 'error'=>string]
 */
function excel_parse(array $file, int $header_rows = 3): array {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok'=>false,'rows'=>[],'error'=>'File gagal diupload. Kode: ' . ($file['error'] ?? '?')];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok'=>false,'rows'=>[],'error'=>'Ukuran file maksimal 10 MB.'];
    }

    // ── Deteksi tipe dan baca ──────────────────────────────────
    if ($ext === 'xlsx' || $ext === 'xlsm') {
        return _parse_xlsx($file['tmp_name'], $header_rows);
    } elseif ($ext === 'xls') {
        return _parse_xls($file['tmp_name'], $header_rows);
    } elseif (in_array($ext, ['csv', 'txt'], true)) {
        return _parse_csv($file['tmp_name'], $header_rows);
    } else {
        return ['ok'=>false,'rows'=>[],'error'=>'Format tidak didukung. Gunakan .xlsx, .xls, atau .csv'];
    }
}

/** Parse .xlsx menggunakan PhpSpreadsheet */
function _parse_xlsx(string $tmpPath, int $skip): array {
    try {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        return _sheet_to_rows($sheet, $skip);
    } catch (\Throwable $e) {
        return ['ok'=>false,'rows'=>[],'error'=>'Gagal membaca file Excel: ' . $e->getMessage()];
    }
}

/** Parse .xls (Excel 97-2003) */
function _parse_xls(string $tmpPath, int $skip): array {
    try {
        $reader = IOFactory::createReader('Xls');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        return _sheet_to_rows($sheet, $skip);
    } catch (\Throwable $e) {
        return ['ok'=>false,'rows'=>[],'error'=>'Gagal membaca file Excel (xls): ' . $e->getMessage()];
    }
}

/** Konversi worksheet PhpSpreadsheet ke array rows */
function _sheet_to_rows($sheet, int $skip): array {
    $rows = [];
    $maxRow = $sheet->getHighestDataRow();
    $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
        $sheet->getHighestDataColumn()
    );

    for ($r = $skip + 1; $r <= $maxRow; $r++) {
        $rowData = [];
        $empty   = true;

        for ($c = 1; $c <= $maxCol; $c++) {
            // PhpSpreadsheet 2.x+ menghapus getCellByColumnAndRow()
            // Gunakan getCell() dengan koordinat string (misal: getCell('A1'))
            $colStr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $cell   = $sheet->getCell($colStr . $r);

            // Gunakan nilai yang sudah diformulasi, bukan rumus
            $val = (string) $cell->getFormattedValue();
            // Fallback: getValue() untuk teks yang tidak perlu format angka
            if ($val === '' || $val === '0') {
                $raw = $cell->getValue();
                if ($raw !== null && $raw !== '') $val = (string) $raw;
            }
            $val = trim($val);
            if ($val !== '') $empty = false;
            $rowData[] = $val;
        }

        if ($empty) continue;
        // Skip baris komentar (diawali #)
        if (isset($rowData[0]) && str_starts_with($rowData[0], '#')) continue;

        $rows[] = $rowData;
    }

    if (empty($rows)) {
        return ['ok'=>false,'rows'=>[],'error'=>'File Excel kosong atau hanya berisi baris header/contoh.'];
    }
    return ['ok'=>true,'rows'=>$rows,'error'=>''];
}

/** Parse CSV dengan deteksi delimiter & BOM */
function _parse_csv(string $tmpPath, int $skip): array {
    $handle = fopen($tmpPath, 'r');
    if (!$handle) return ['ok'=>false,'rows'=>[],'error'=>'Tidak dapat membaca file CSV.'];

    // Strip BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    // Deteksi delimiter: coba koma, titik koma, tab
    $firstLine = fgets($handle);
    rewind($handle);
    if (str_starts_with($firstLine, "\xEF\xBB\xBF")) {
        fread($handle, 3);
        $firstLine = fgets($handle);
    }
    $delim = ',';
    if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) $delim = ';';
    elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) $delim = "\t";
    rewind($handle);
    // strip BOM again
    $bom2 = fread($handle, 3);
    if ($bom2 !== "\xEF\xBB\xBF") rewind($handle);

    $rows   = [];
    $lineNo = 0;
    while (($cols = fgetcsv($handle, 4096, $delim)) !== false) {
        $lineNo++;
        if ($lineNo <= $skip) continue;
        if ($cols === [null] || implode('', $cols) === '') continue;
        if (isset($cols[0]) && str_starts_with(trim($cols[0]), '#')) continue;
        $rows[] = array_map('trim', $cols);
    }
    fclose($handle);

    if (empty($rows)) {
        return ['ok'=>false,'rows'=>[],'error'=>'File CSV kosong atau hanya berisi header.'];
    }
    return ['ok'=>true,'rows'=>$rows,'error'=>''];
}

// ── Helper ambil nilai dari baris ──────────────────────────────

/** Ambil string dari kolom, trim + batas max length */
function excel_str(array $row, int $idx, int $max = 255): string {
    return mb_substr(trim($row[$idx] ?? ''), 0, $max);
}

/** Ambil integer dari kolom */
function excel_int(array $row, int $idx): int {
    $v = trim($row[$idx] ?? '');
    // Hapus pemisah ribuan jika ada
    $v = str_replace(['.', ',', ' '], ['', '', ''], $v);
    return (int)$v;
}

/** Ambil float dari kolom (toleran format Indonesia: koma sebagai desimal) */
function excel_float(array $row, int $idx): float {
    $v = trim($row[$idx] ?? '0');
    // Jika format "85,5" (koma desimal) ubah ke "85.5"
    if (preg_match('/^\d+,\d{1,2}$/', $v)) $v = str_replace(',', '.', $v);
    return (float)$v;
}

// ── Backward-compat: fungsi CSV lama ───────────────────────────
// Supaya halaman yang belum diupdate tidak error
function csv_download(string $filename, array $headers, array $rows = []): void {
    // Redirect ke xlsx_template dengan config minimal
    $columns = array_map(fn($h) => ['header'=>$h,'field'=>$h,'width'=>18], $headers);
    xlsx_template(str_replace('.csv', '.xlsx', $filename), [
        'title'    => 'Template Import',
        'columns'  => $columns,
        'examples' => $rows,
    ]);
}

function csv_parse(array $file, bool $skip_header = true): array {
    // Header baris: template lama hanya 1 baris header
    $result = excel_parse($file, $skip_header ? 1 : 0);
    return $result;
}

/** Alias backward-compat */
function csv_str(array $row, int $idx, int $max = 255): string { return excel_str($row, $idx, $max); }
function csv_int(array $row, int $idx): int   { return excel_int($row, $idx); }
function csv_float(array $row, int $idx): float { return excel_float($row, $idx); }
