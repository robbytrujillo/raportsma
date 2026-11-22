<?php
/**
 * pages/raport/leger_export.php
 * Export Leger Raport ke XLSX menggunakan PhpSpreadsheet (server-side, full styling).
 * Dipanggil via GET dengan parameter sama seperti leger.php.
 * Akses: super_admin, admin, kepala_sekolah, wali_kelas.
 */
require_once __DIR__ . '/../../auth.php';
require_login();

$user_ses = current_user();
$role     = $user_ses['role'];

if (!in_array($role, ['super_admin', 'admin', 'kepala_sekolah', 'wali_kelas'])) {
    http_response_code(403); echo 'Akses ditolak.'; exit;
}

$ta_id    = (int)($_GET['ta']    ?? 0);
$kelas_id = (int)($_GET['kelas'] ?? 0);
$jenis    = $_GET['jenis'] ?? 'bulanan';
$bulan    = (int)($_GET['bulan']  ?? 0);
// sort & filter dari leger.php (opsional, default peringkat)
$sort     = $_GET['sort']   ?? 'rank_asc';
$abjad    = strtoupper(trim($_GET['abjad'] ?? ''));

if (!$ta_id || !$kelas_id) {
    http_response_code(400); echo 'Parameter ta dan kelas wajib diisi.'; exit;
}

require_once __DIR__ . '/../../db.php';

// Verifikasi akses wali_kelas
if ($role === 'wali_kelas') {
    $kelas_check = db_row('SELECT id FROM kelas WHERE id = :kid AND wali_kelas_id = :uid',
        [':kid' => $kelas_id, ':uid' => $user_ses['id']]);
    if (!$kelas_check) { http_response_code(403); echo 'Akses ditolak.'; exit; }
} elseif ($role === 'kepala_sekolah') {
    // Kepala sekolah hanya bisa export leger kelas di unitnya
    $_unit_f_le = get_user_unit_filter($user_ses);
    if (!empty($_unit_f_le['units'])) {
        $kelas_unit_le = db_row('SELECT unit FROM kelas WHERE id = :id', [':id' => $kelas_id])['unit'] ?? 'campuran';
        if (!in_array($kelas_unit_le, $_unit_f_le['units'])) {
            http_response_code(403); echo 'Akses ditolak: kelas ini tidak berada di unit Anda.'; exit;
        }
    }
}

$kelas = db_row('SELECT k.*, u.nama_lengkap as wali_nama FROM kelas k LEFT JOIN users u ON u.id = k.wali_kelas_id WHERE k.id = :id', [':id' => $kelas_id]);
$ta    = db_row('SELECT * FROM tahun_ajaran WHERE id = :id', [':id' => $ta_id]);
if (!$kelas || !$ta) { http_response_code(404); echo 'Data tidak ditemukan.'; exit; }

$sekolah      = sekolah_info();
$jenis_label  = ['bulanan'=>'Bulanan','sts'=>'ASTS','semester'=>'Semester'][$jenis] ?? ucfirst($jenis);
$bulan_nama   = (in_array($jenis, ['bulanan','sts']) && $bulan) ? (BULAN_ID[$bulan] ?? '') : '';
$semester_lbl = ($ta['semester'] ?? 1) == 1 ? 'Ganjil' : 'Genap';

$krit_c = (float)($sekolah['kriteria_c_min'] ?? 76);
$krit_b = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_a = (float)($sekolah['kriteria_a_min'] ?? 92);

// ── Kepala sekolah (sama persis dengan leger.php) ───────────────
$kelas_unit_lg = $kelas['unit'] ?? 'campuran';
if ($kelas_unit_lg === 'putri') {
    $sekolah_si_lg   = db_row('SELECT kepala_sekolah_putri, nip_kepala_putri FROM sekolah_info ORDER BY id LIMIT 1');
    $kepsek_putri_nm = $sekolah_si_lg['kepala_sekolah_putri'] ?? null;
    if ($kepsek_putri_nm) {
        $kepsek = db_row('SELECT nama_lengkap FROM users WHERE role = "kepala_sekolah" AND nama_lengkap = :nm LIMIT 1', [':nm' => $kepsek_putri_nm]);
        if (!$kepsek) $kepsek = ['nama_lengkap' => $kepsek_putri_nm];
    } else {
        $kepsek = db_row('SELECT nama_lengkap FROM users WHERE role = "kepala_sekolah" LIMIT 1');
    }
} else {
    $kepsek = db_row('SELECT nama_lengkap FROM users WHERE role = "kepala_sekolah" LIMIT 1');
    if (!$kepsek) {
        $si = db_row('SELECT kepala_sekolah FROM sekolah_info ORDER BY id LIMIT 1');
        if (!empty($si['kepala_sekolah'])) $kepsek = ['nama_lengkap' => $si['kepala_sekolah']];
    }
}
$kepsek_nama = $kepsek['nama_lengkap'] ?? '________________________';
$wali_nama   = $kelas['wali_nama'] ?? '________________________';

// Tanggal cetak
$bulan_id_map_ex = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
    7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember',
];
$tgl_ts    = time();
$tgl_cetak = ($sekolah['kota'] ?? 'Depok') . ', '
    . (int)date('j', $tgl_ts) . ' '
    . ($bulan_id_map_ex[(int)date('n', $tgl_ts)] ?? date('F', $tgl_ts))
    . ' ' . date('Y', $tgl_ts);

// ── Mapel list dari PENUGASAN GURU (selalu tampil meski belum ada nilai) ──
$mapel_raw = db_query(
    "SELECT DISTINCT m.id, m.nama_mapel, m.urutan, m.kelompok, m.parent_id,
            p.nama_mapel as parent_nama, p.kelompok as parent_kelompok, p.urutan as parent_urutan
     FROM guru_mapel_kelas gmk
     INNER JOIN mapel m ON m.id = gmk.mapel_id
     LEFT  JOIN mapel p ON p.id = m.parent_id
     WHERE gmk.kelas_id=:kid AND gmk.tahun_ajaran_id=:ta
     ORDER BY m.kelompok, m.urutan, m.nama_mapel",
    [':kid'=>$kelas_id,':ta'=>$ta_id]);

// Ambil data parent langsung dari DB agar urutan akurat
$_pids_ex = array_filter(array_unique(array_column($mapel_raw,'parent_id')));
$_pdb_ex  = [];
if (!empty($_pids_ex)) {
    $in_ex = implode(',', array_map('intval', $_pids_ex));
    foreach (db_query("SELECT id,nama_mapel,kelompok,urutan FROM mapel WHERE id IN ($in_ex)") as $pr) {
        $_pdb_ex[$pr['id']] = $pr;
    }
}

$_singles = []; $_children_by_parent = []; $_parent_meta = [];
foreach ($mapel_raw as $mi) {
    if ($mi['parent_id'] !== null) {
        $pid = (int)$mi['parent_id'];
        $_children_by_parent[$pid][] = $mi;
        if (!isset($_parent_meta[$pid])) {
            $pd = $_pdb_ex[$pid] ?? null;
            $_parent_meta[$pid] = [
                'kelompok' => $pd['kelompok'] ?? $mi['parent_kelompok'] ?? $mi['kelompok'],
                'urutan'   => isset($pd['urutan']) ? (int)$pd['urutan'] : (int)($mi['parent_urutan']??0),
                'nama'     => $pd['nama_mapel']  ?? $mi['parent_nama']  ?? '',
            ];
        }
    } else {
        $_singles[] = $mi;
    }
}
$_slots = [];
foreach ($_singles as $mi) $_slots[] = ['kelompok'=>$mi['kelompok'],'urutan'=>(int)($mi['urutan']??0),'type'=>'single','items'=>[$mi]];
foreach ($_children_by_parent as $pid => $children) { $pm=$_parent_meta[$pid]; $_slots[] = ['kelompok'=>$pm['kelompok'],'urutan'=>$pm['urutan'],'type'=>'group','items'=>$children]; }
usort($_slots, function($a,$b){ $kg=strcmp($a['kelompok']??'',$b['kelompok']??''); return $kg!==0?$kg:$a['urutan']<=>$b['urutan']; });
$mapel_list = [];
foreach ($_slots as $slot) foreach ($slot['items'] as $mi) $mapel_list[] = $mi;

if (empty($mapel_list)) { http_response_code(404); echo 'Belum ada penugasan guru untuk kelas ini.'; exit; }

// ── Siswa list (default sort nama A-Z, peringkat dihitung terpisah) ──
$bulan_cond_s = '';
$siswa_params = [':kid'=>$kelas_id,':kid2'=>$kelas_id,':ta'=>$ta_id,':jenis'=>$jenis];
if (in_array($jenis,['bulanan','sts']) && $bulan) { $bulan_cond_s='AND n.bulan=:bulan'; $siswa_params[':bulan']=$bulan; }
$siswa_list = db_query(
    "SELECT u.id,u.nama_lengkap,s.nis,s.nisn,ROUND(AVG(n.nilai_akhir),2) as avg_nilai
     FROM users u INNER JOIN siswa s ON s.user_id=u.id
     LEFT JOIN nilai n ON n.siswa_user_id=u.id AND n.kelas_id=:kid AND n.tahun_ajaran_id=:ta AND n.jenis_raport=:jenis $bulan_cond_s
     WHERE s.kelas_id=:kid2
     GROUP BY u.id,u.nama_lengkap,s.nis,s.nisn ORDER BY u.nama_lengkap ASC", $siswa_params);

// Hitung rank_map dari avg DESC
$_rs2 = $siswa_list;
usort($_rs2, fn($a,$b) => (float)$b['avg_nilai'] <=> (float)$a['avg_nilai']);
$rank_map_ex = [];
foreach ($_rs2 as $_ri => $_rs) { $rank_map_ex[(int)$_rs['id']] = $_ri + 1; }

// ── Nilai map ───────────────────────────────────────────────────
$bulan_cond_v = '';
$nilai_params = [':kid'=>$kelas_id,':ta'=>$ta_id,':jenis'=>$jenis];
if (in_array($jenis,['bulanan','sts']) && $bulan) { $bulan_cond_v='AND bulan=:bulan'; $nilai_params[':bulan']=$bulan; }
$all_nilai = db_query("SELECT siswa_user_id,mapel_id,nilai_akhir FROM nilai WHERE kelas_id=:kid AND tahun_ajaran_id=:ta AND jenis_raport=:jenis $bulan_cond_v", $nilai_params);
$nilai_map = [];
foreach ($all_nilai as $r) $nilai_map[$r['siswa_user_id']][$r['mapel_id']] = (float)$r['nilai_akhir'];

// ── Hitung total/rata untuk setiap siswa ───────────────────────
$rows_data = [];
foreach ($siswa_list as $i => $s) {
    $sid        = (int)$s['id'];
    $peringkat  = $rank_map_ex[$sid] ?? ($i + 1);
    $sn         = $nilai_map[$sid] ?? [];
    $total_val  = 0; $jml = 0;
    $nilai_per_mapel = [];
    foreach ($mapel_list as $m) {
        $v = $sn[$m['id']] ?? 0;
        $nilai_per_mapel[$m['id']] = $v;
        if ($v > 0) { $total_val += $v; $jml++; }
    }
    $rata  = $jml > 0 ? round($total_val / $jml, 1) : 0;
    $total = round($total_val, 1);
    $rows_data[] = [
        'peringkat'  => $peringkat,
        'nisn'       => $s['nisn'] ?? $s['nis'],
        'nama'       => $s['nama_lengkap'],
        'nilai'      => $nilai_per_mapel,
        'total'      => $total,
        'rata'       => $rata,
        'rata_raw'   => $rata,
    ];
}

// ── Terapkan filter abjad ───────────────────────────────────────
if ($abjad && $abjad !== 'ALL') {
    $rows_data = array_values(array_filter($rows_data, function($r) use ($abjad) {
        return strtoupper(mb_substr($r['nama'], 0, 1)) === $abjad;
    }));
}

// ── Terapkan sort ───────────────────────────────────────────────
usort($rows_data, function($a, $b) use ($sort) {
    switch ($sort) {
        case 'rank_desc': return $b['peringkat'] <=> $a['peringkat'];
        case 'nama_asc' : return strcmp(strtolower($a['nama']), strtolower($b['nama']));
        case 'nama_desc': return strcmp(strtolower($b['nama']), strtolower($a['nama']));
        case 'rata_desc': return $b['rata_raw'] <=> $a['rata_raw'];
        case 'rata_asc' : return $a['rata_raw'] <=> $b['rata_raw'];
        default:          return $a['peringkat'] <=> $b['peringkat']; // rank_asc
    }
});

// Renumber NO setelah filter/sort
foreach ($rows_data as $i => &$r) $r['no'] = $i + 1;
unset($r);

// ── PhpSpreadsheet ──────────────────────────────────────────────
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

$spreadsheet = new Spreadsheet();
$ws = $spreadsheet->getActiveSheet();
$ws->setTitle('Leger');

// Helper: col index (1-based) → Excel letter
function col_letter(int $col): string {
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
}

$totalMapel = count($mapel_list);
$totalCols  = 3 + $totalMapel + 3; // NO+NISN+NAMA + mapel + Total+Rata+Peringkat
$colTotal   = 4 + $totalMapel;      // 1-based
$colRata    = 5 + $totalMapel;
$colRank    = 6 + $totalMapel;
$lastColLtr = col_letter($totalCols);

$borderAll = [
    'borders' => [
        'allBorders' => ['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF333333']],
    ],
];
$borderNone = [
    'borders' => ['allBorders' => ['borderStyle'=>Border::BORDER_NONE]],
];

// Judul
$judulLines = [
    'LEGER RAPORT ' . strtoupper($jenis_label) . ($bulan_nama ? ' ' . strtoupper($bulan_nama) : ''),
    strtoupper($sekolah['nama_sekolah'] ?? 'NAMA SEKOLAH'),
    'TAHUN PELAJARAN ' . strtoupper($ta['nama']) . ' SEMESTER ' . strtoupper($semester_lbl),
];
$row = 1;
foreach ($judulLines as $line) {
    $ws->setCellValue('A' . $row, $line);
    $ws->mergeCells('A'.$row.':'.$lastColLtr.$row);
    $ws->getStyle('A'.$row)->applyFromArray([
        'font' => ['bold'=>true,'size'=>12,'name'=>'Arial'],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
    ]);
    $ws->getRowDimension($row)->setRowHeight(18);
    $row++;
}
$row++; // baris kosong

// Meta kelas
$ws->setCellValue('A'.$row, 'Kelas');      $ws->setCellValue('B'.$row, ':');  $ws->setCellValue('C'.$row, $kelas['nama_kelas']);
$ws->setCellValue('E'.$row, 'Wali Kelas'); $ws->setCellValue('F'.$row, ':');  $ws->setCellValue('G'.$row, $kelas['wali_nama'] ?? '-');
foreach(['A','E'] as $ltr) $ws->getStyle($ltr.$row)->getFont()->setBold(true);
$ws->getRowDimension($row)->setRowHeight(16);
$row++;

$ws->setCellValue('A'.$row, 'KKTP'); $ws->setCellValue('B'.$row, ':'); $ws->setCellValue('C'.$row, (int)$krit_c);
$ws->getStyle('A'.$row)->getFont()->setBold(true);
$ws->getRowDimension($row)->setRowHeight(16);
$row++;
$row++; // baris kosong

// Header baris 1 — merge NO, NISN, NAMA, Total, Rata-rata, Peringkat (rowspan 2)
$hRow1 = $row;
$hRow2 = $row + 1;

// Helper: apply border outline pada merged range (agar garis luar merged cell rapih)
// PhpSpreadsheet: style range = style seluruh sel dalam range; outline = tepi luar merged
$thin = ['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF333333']];

function applyMergedStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, string $range, array $style): void {
    // Apply fill+font+alignment ke range (semua sel)
    $ws->getStyle($range)->applyFromArray($style);
    // Jika ada border, apply outline via range supaya garis luar penuh
    if (!empty($style['borders'])) {
        $ws->getStyle($range)->applyFromArray(['borders' => $style['borders']]);
    }
}

$colMapelStart = 4; $colMapelEnd = 3 + $totalMapel;

// Base style header
$baseHdrStyle = [
    'font'      => ['bold'=>true,'size'=>10,'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'fgColor'=>['argb'=>'FFE8F5E9']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
    'borders'   => ['outline'=>$thin,'inside'=>$thin],
];

// NO (rowspan 2)
$ws->setCellValue('A'.$hRow1, 'NO');
$ws->mergeCells('A'.$hRow1.':A'.$hRow2);
$ws->getStyle('A'.$hRow1.':A'.$hRow2)->applyFromArray($baseHdrStyle);

// NISN (rowspan 2)
$ws->setCellValue('B'.$hRow1, 'NISN');
$ws->mergeCells('B'.$hRow1.':B'.$hRow2);
$ws->getStyle('B'.$hRow1.':B'.$hRow2)->applyFromArray($baseHdrStyle);

// NAMA MURID (rowspan 2)
$ws->setCellValue('C'.$hRow1, 'NAMA MURID');
$ws->mergeCells('C'.$hRow1.':C'.$hRow2);
$ws->getStyle('C'.$hRow1.':C'.$hRow2)->applyFromArray($baseHdrStyle);

// PENGETAHUAN (colspan mapel, baris 1 saja)
$ws->setCellValue(col_letter($colMapelStart).$hRow1, 'PENGETAHUAN');
$ws->mergeCells(col_letter($colMapelStart).$hRow1.':'.col_letter($colMapelEnd).$hRow1);
$ws->getStyle(col_letter($colMapelStart).$hRow1.':'.col_letter($colMapelEnd).$hRow1)->applyFromArray([
    'font'      => ['bold'=>true,'size'=>11,'name'=>'Arial','color'=>['argb'=>'FF1B5E20']],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'fgColor'=>['argb'=>'FFC8E6C9']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
    'borders'   => ['outline'=>$thin,'inside'=>$thin],
]);

// Total (rowspan 2)
$ws->setCellValue(col_letter($colTotal).$hRow1, 'Total');
$ws->mergeCells(col_letter($colTotal).$hRow1.':'.col_letter($colTotal).$hRow2);
$ws->getStyle(col_letter($colTotal).$hRow1.':'.col_letter($colTotal).$hRow2)->applyFromArray($baseHdrStyle);

// Rata-rata (rowspan 2)
$ws->setCellValue(col_letter($colRata).$hRow1, 'Rata-rata');
$ws->mergeCells(col_letter($colRata).$hRow1.':'.col_letter($colRata).$hRow2);
$ws->getStyle(col_letter($colRata).$hRow1.':'.col_letter($colRata).$hRow2)->applyFromArray(array_merge($baseHdrStyle, [
    'font' => ['bold'=>true,'size'=>10,'name'=>'Arial','color'=>['argb'=>'FFE65100']],
    'fill' => ['fillType'=>Fill::FILL_SOLID,'fgColor'=>['argb'=>'FFFFF3E0']],
]));

// Peringkat (rowspan 2)
$ws->setCellValue(col_letter($colRank).$hRow1, 'Peringkat');
$ws->mergeCells(col_letter($colRank).$hRow1.':'.col_letter($colRank).$hRow2);
$ws->getStyle(col_letter($colRank).$hRow1.':'.col_letter($colRank).$hRow2)->applyFromArray($baseHdrStyle);

$ws->getRowDimension($hRow1)->setRowHeight(20);

// Header baris 2 — nama mapel (sel individual, bukan merged)
$mapelHdrStyle = [
    'font'      => ['bold'=>true,'size'=>9,'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'fgColor'=>['argb'=>'FFE8F5E9']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
    'borders'   => ['allBorders'=>$thin],
];
foreach ($mapel_list as $idx => $m) {
    $col = col_letter($colMapelStart + $idx);
    $ws->setCellValue($col.$hRow2, $m['nama_mapel']);
    $ws->getStyle($col.$hRow2)->applyFromArray($mapelHdrStyle);
}
$ws->getRowDimension($hRow2)->setRowHeight(40);
$row += 2;

// Baris nomor kolom
$numColStyle = [
    'font'      => ['bold'=>true,'size'=>10,'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'fgColor'=>['argb'=>'FFFFF9C4']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF333333']]],
];
$ws->setCellValue('A'.$row, 1); $ws->getStyle('A'.$row)->applyFromArray($numColStyle);
$ws->setCellValue('B'.$row, 2); $ws->getStyle('B'.$row)->applyFromArray($numColStyle);
$ws->setCellValue('C'.$row, 3); $ws->getStyle('C'.$row)->applyFromArray($numColStyle);
foreach ($mapel_list as $idx => $m) {
    $col = col_letter($colMapelStart + $idx);
    $ws->setCellValue($col.$row, $colMapelStart + $idx);
    $ws->getStyle($col.$row)->applyFromArray($numColStyle);
}
$ws->setCellValue(col_letter($colTotal).$row, $colTotal); $ws->getStyle(col_letter($colTotal).$row)->applyFromArray($numColStyle);
$ws->setCellValue(col_letter($colRata ).$row, $colRata);  $ws->getStyle(col_letter($colRata ).$row)->applyFromArray($numColStyle);
$ws->setCellValue(col_letter($colRank ).$row, $colRank);  $ws->getStyle(col_letter($colRank ).$row)->applyFromArray($numColStyle);
$ws->getRowDimension($row)->setRowHeight(14);
$row++;

// Data siswa
foreach ($rows_data as $ri => $rd) {
    $isOdd     = ($ri % 2 === 0);
    $bgBase    = $isOdd ? 'FFFAFAFA' : 'FFFFFFFF';

    $baseStyle = [
        'font'      => ['size'=>10,'name'=>'Arial'],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'fgColor'=>['argb'=>$bgBase]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF333333']]],
    ];
    $namaStyle = array_merge($baseStyle, [
        'font'      => ['size'=>10,'name'=>'Arial','bold'=>true],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER],
    ]);
    $lowStyle  = array_merge($baseStyle, [
        'font'      => ['size'=>10,'name'=>'Arial','color'=>['argb'=>'FFC62828']],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'fgColor'=>['argb'=>'FFFFE0E0']],
    ]);
    $rataStyle = array_merge($baseStyle, [
        'font'      => ['size'=>10,'name'=>'Arial','bold'=>true,'color'=>['argb'=>'FFE65100']],
    ]);
    $boldStyle = array_merge($baseStyle, ['font' => ['size'=>10,'name'=>'Arial','bold'=>true]]);

    $ws->setCellValue('A'.$row, $rd['no']);     $ws->getStyle('A'.$row)->applyFromArray($baseStyle);
    $ws->setCellValue('B'.$row, $rd['nisn']);   $ws->getStyle('B'.$row)->applyFromArray($baseStyle);
    $ws->setCellValue('C'.$row, $rd['nama']);   $ws->getStyle('C'.$row)->applyFromArray($namaStyle);

    foreach ($mapel_list as $idx => $m) {
        $v   = $rd['nilai'][$m['id']] ?? 0;
        $col = col_letter($colMapelStart + $idx);
        $ws->setCellValue($col.$row, $v > 0 ? (int)round($v) : '');
        $ws->getStyle($col.$row)->applyFromArray($v > 0 && $v < $krit_c ? $lowStyle : $baseStyle);
    }

    $ws->setCellValue(col_letter($colTotal).$row, $rd['total'] > 0 ? (int)$rd['total'] : '-');
    $ws->getStyle(col_letter($colTotal).$row)->applyFromArray($boldStyle);

    $ws->setCellValue(col_letter($colRata).$row, $rd['rata'] > 0 ? $rd['rata'] : '-');
    $ws->getStyle(col_letter($colRata).$row)->applyFromArray($rataStyle);

    $ws->setCellValue(col_letter($colRank).$row, $rd['peringkat']);
    $ws->getStyle(col_letter($colRank).$row)->applyFromArray($boldStyle);

    $ws->getRowDimension($row)->setRowHeight(14);
    $row++;
}

// ── Tanda tangan di bawah tabel ────────────────────────────────
$row++; // baris kosong setelah data

// Hitung berapa kolom untuk kiri (Kepsek) dan kanan (Wali Kelas)
// Kiri: kolom 1 s.d ceil(totalCols/2), Kanan: sisanya
$ttdKiriEnd  = (int)floor($totalCols / 2);        // col 1 .. ttdKiriEnd
$ttdKananStart = $ttdKiriEnd + 2;                   // ada 1 kolom gap
$ttdKananEnd = $totalCols;

$ttdStyle = [
    'font'      => ['size'=>10,'name'=>'Arial'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
];

// Baris "Mengetahui,"  |  tanggal cetak
$ws->setCellValue('A'.$row, 'Mengetahui,');
$ws->mergeCells('A'.$row.':'.col_letter($ttdKiriEnd).$row);
$ws->getStyle('A'.$row.':'.col_letter($ttdKiriEnd).$row)->applyFromArray($ttdStyle);

$ws->setCellValue(col_letter($ttdKananStart).$row, $tgl_cetak);
$ws->mergeCells(col_letter($ttdKananStart).$row.':'.col_letter($ttdKananEnd).$row);
$ws->getStyle(col_letter($ttdKananStart).$row.':'.col_letter($ttdKananEnd).$row)->applyFromArray($ttdStyle);
$ws->getRowDimension($row)->setRowHeight(16);
$row++;

// Baris "Kepala Sekolah,"  |  "Wali Kelas,"
$ws->setCellValue('A'.$row, 'Kepala Sekolah,');
$ws->mergeCells('A'.$row.':'.col_letter($ttdKiriEnd).$row);
$ws->getStyle('A'.$row.':'.col_letter($ttdKiriEnd).$row)->applyFromArray($ttdStyle);

$ws->setCellValue(col_letter($ttdKananStart).$row, 'Wali Kelas,');
$ws->mergeCells(col_letter($ttdKananStart).$row.':'.col_letter($ttdKananEnd).$row);
$ws->getStyle(col_letter($ttdKananStart).$row.':'.col_letter($ttdKananEnd).$row)->applyFromArray($ttdStyle);
$ws->getRowDimension($row)->setRowHeight(16);
$row++;

// Ruang kosong tanda tangan (4 baris)
for ($i = 0; $i < 4; $i++) {
    $ws->getRowDimension($row)->setRowHeight(16);
    $row++;
}

// Nama kepsek & wali kelas — border atas di-apply ke range penuh agar garis panjang
$ttdNamaStyle = [
    'font'      => ['bold'=>true,'size'=>10,'name'=>'Arial'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
    'borders'   => ['top'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF333333']]],
];

$ws->setCellValue('A'.$row, $kepsek_nama);
$ws->mergeCells('A'.$row.':'.col_letter($ttdKiriEnd).$row);
// Apply border top ke seluruh range agar garis bawah nama panjang
$ws->getStyle('A'.$row.':'.col_letter($ttdKiriEnd).$row)->applyFromArray($ttdNamaStyle);

$ws->setCellValue(col_letter($ttdKananStart).$row, $wali_nama);
$ws->mergeCells(col_letter($ttdKananStart).$row.':'.col_letter($ttdKananEnd).$row);
$ws->getStyle(col_letter($ttdKananStart).$row.':'.col_letter($ttdKananEnd).$row)->applyFromArray($ttdNamaStyle);
$ws->getRowDimension($row)->setRowHeight(18);

// ── Lebar kolom ─────────────────────────────────────────────────
$ws->getColumnDimension('A')->setWidth(6);
$ws->getColumnDimension('B')->setWidth(14);
$ws->getColumnDimension('C')->setWidth(24);
foreach ($mapel_list as $idx => $m) {
    $ws->getColumnDimension(col_letter($colMapelStart + $idx))->setWidth(7);
}
$ws->getColumnDimension(col_letter($colTotal))->setWidth(8);
$ws->getColumnDimension(col_letter($colRata ))->setWidth(9);
$ws->getColumnDimension(col_letter($colRank ))->setWidth(8);

// Page setup landscape
$ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$ws->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
$ws->getPageSetup()->setFitToPage(true);
$ws->getPageSetup()->setFitToWidth(1);
$ws->getPageSetup()->setFitToHeight(0);

// Output file
$fname  = 'Leger_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $kelas['nama_kelas'])
        . '_' . preg_replace('/[^A-Za-z0-9_-]/', '-', $ta['nama'])
        . '_' . $jenis_label
        . ($bulan_nama ? '_' . $bulan_nama : '')
        . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');

$writer = new XlsxWriter($spreadsheet);
$writer->save('php://output');
exit;
