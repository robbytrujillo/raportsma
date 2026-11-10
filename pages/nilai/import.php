<?php
/**
 * pages/nilai/import.php
 * Import nilai — dua mode:
 *   1. Template Generik  : format lama (NIS, mapel, kelas, ta_id, jenis, bulan, ...)
 *   2. Template Bulk     : format baru per-kelas/mapel/jenis — baris siswa sudah terisi
 *                          metadata tersimpan di baris tersembunyi (baris 4-6)
 */
require_role(['admin', 'super_admin', 'guru', 'wali_kelas']);
require_once __DIR__ . '/../../excel.php';

$user        = current_user();
$role        = $user['role'];
$ta_aktif    = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$sekolah     = sekolah_info();

$krit_a = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c = (float)($sekolah['kriteria_c_min'] ?? 76);

$ta_list = db_query('SELECT * FROM tahun_ajaran ORDER BY is_aktif DESC, id DESC');

// ── Parameter filter untuk template bulk ────────────────────────
$guru_detail  = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user['id']]);
$guru_status  = $guru_detail['status'] ?? 'guru';
$wali_kelas_ids = [];
if ($guru_status === 'wali_kelas') {
    $wk = db_query('SELECT id FROM kelas WHERE wali_kelas_id = :uid', [':uid' => $user['id']]);
    $wali_kelas_ids = array_column($wk, 'id');
}

// Support passthrough dari bulk.php: ?ta=&kelas=&mapel=&jenis=&bulan= (tanpa prefix f_)
// Jika ada param f_* pakai itu, fallback ke ta/kelas/mapel/jenis/bulan
$f_ta_id   = (int)($_GET['f_ta']    ?? $_GET['ta']    ?? ($ta_aktif['id'] ?? 0));
$f_kelas   = (int)($_GET['f_kelas'] ?? $_GET['kelas'] ?? 0);
$f_mapel   = (int)($_GET['f_mapel'] ?? $_GET['mapel'] ?? 0);
$_jenis_raw = $_GET['f_jenis'] ?? $_GET['jenis'] ?? 'bulanan';
$f_jenis   = in_array($_jenis_raw, ['bulanan','sts','semester']) ? $_jenis_raw : 'bulanan';
$f_bulan   = (int)($_GET['f_bulan'] ?? $_GET['bulan'] ?? 0);

// Kelas list sesuai hak akses
if (in_array($role, ['admin','super_admin'])) {
    $kelas_list = db_query('SELECT * FROM kelas WHERE tahun_ajaran_id=:ta ORDER BY nama_kelas', [':ta'=>$f_ta_id]);
} elseif ($guru_status === 'wali_kelas') {
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         LEFT JOIN guru_mapel_kelas gmk ON gmk.kelas_id=k.id AND gmk.guru_user_id=:uid
         WHERE k.tahun_ajaran_id=:ta AND (k.wali_kelas_id=:uid2 OR gmk.id IS NOT NULL)
         ORDER BY k.nama_kelas',
        [':uid'=>$user['id'],':uid2'=>$user['id'],':ta'=>$f_ta_id]
    );
} else {
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id=k.id
         WHERE gmk.guru_user_id=:uid AND gmk.tahun_ajaran_id=:ta ORDER BY k.nama_kelas',
        [':uid'=>$user['id'],':ta'=>$f_ta_id]
    );
}

// Mapel list sesuai kelas & hak akses
$mapel_list = [];
if ($f_kelas) {
    if (in_array($role, ['admin','super_admin'])) {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':kid'=>$f_kelas]
        );
    } elseif ($guru_status === 'wali_kelas' && in_array($f_kelas, $wali_kelas_ids)) {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':kid'=>$f_kelas]
        );
    } else {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.guru_user_id=:uid AND gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':uid'=>$user['id'],':kid'=>$f_kelas]
        );
    }
}

// ── Download Template Bulk (per-kelas/mapel) ─────────────────────
if (isset($_GET['download_bulk']) && $f_kelas && $f_mapel) {
    $kelas_row  = db_row('SELECT * FROM kelas WHERE id=:id', [':id'=>$f_kelas]);
    $mapel_row  = db_row('SELECT * FROM mapel WHERE id=:id', [':id'=>$f_mapel]);

    $guru_pgm = db_row(
        'SELECT u.nama_lengkap FROM users u
         INNER JOIN guru_mapel_kelas gmk ON gmk.guru_user_id=u.id
         WHERE gmk.kelas_id=:k AND gmk.mapel_id=:m AND gmk.tahun_ajaran_id=:ta LIMIT 1',
        [':k'=>$f_kelas,':m'=>$f_mapel,':ta'=>$f_ta_id]
    );

    $siswa_bulk = db_query(
        'SELECT u.id, u.nama_lengkap, s.nis FROM users u
         INNER JOIN siswa s ON s.user_id=u.id
         WHERE s.kelas_id=:kid ORDER BY u.nama_lengkap',
        [':kid'=>$f_kelas]
    );

    $jenis_label = ['bulanan'=>'Bulanan (NH + Tugas)','sts'=>'STS – Tengah Semester','semester'=>'Semester (SAS)'];
    $bulan_nama  = ($f_bulan > 0) ? (BULAN_ID[$f_bulan] ?? '') : '';
    $periode_str = ($f_jenis !== 'semester' && $f_bulan > 0) ? $bulan_nama : ($f_jenis === 'semester' ? 'Semester' : '');

    // Nilai yang sudah ada (untuk prefill)
    $existing = [];
    if ($f_bulan > 0 || $f_jenis === 'semester') {
        $exrows = db_query(
            'SELECT * FROM nilai WHERE kelas_id=:k AND mapel_id=:m AND tahun_ajaran_id=:ta
              AND jenis_raport=:j AND (bulan=:b OR (:b2 IS NULL AND bulan IS NULL))',
            [':k'=>$f_kelas,':m'=>$f_mapel,':ta'=>$f_ta_id,
             ':j'=>$f_jenis,':b'=>$f_bulan?:null,':b2'=>$f_bulan?:null]
        );
        foreach ($exrows as $er2) $existing[$er2['siswa_user_id']] = $er2;
    }

    // Deskripsi kriteria yang sudah tersimpan di DB (prefill ke template)
    $desk_existing = [];
    $desk_rows_db = db_query(
        'SELECT kriteria, deskripsi FROM nilai_deskripsi
         WHERE mapel_id=:m AND kelas_id=:k AND tahun_ajaran_id=:ta
           AND jenis_raport=:j AND (bulan=:b OR (:b2 IS NULL AND bulan IS NULL))',
        [':m'=>$f_mapel,':k'=>$f_kelas,':ta'=>$f_ta_id,
         ':j'=>$f_jenis,':b'=>$f_bulan?:null,':b2'=>$f_bulan?:null]
    );
    foreach ($desk_rows_db as $dr) $desk_existing[$dr['kriteria']] = $dr['deskripsi'];

    // Kolom aktif (baca dari GET f_aktif_nh1 dst., default semua aktif)
    $aktif_nh1   = (int)($_GET['f_aktif_nh1']   ?? 1);
    $aktif_nh2   = (int)($_GET['f_aktif_nh2']   ?? 1);
    $aktif_nh3   = (int)($_GET['f_aktif_nh3']   ?? 1);
    $aktif_tugas = (int)($_GET['f_aktif_tugas'] ?? 1);

    // Baris siswa — nilai di-prefill jika sudah ada
    $siswa_rows = [];
    foreach ($siswa_bulk as $idx => $s) {
        $nv = $existing[$s['id']] ?? null;
        $row = [
            $idx + 1,                                  // 0: No
            $s['nis'],                                  // 1: NIS
            $s['nama_lengkap'],                         // 2: Nama Siswa
        ];
        if ($f_jenis === 'bulanan') {
            $row[] = $nv ? (float)$nv['nilai_harian_1'] : ''; // NH1
            $row[] = $nv ? (float)$nv['nilai_harian_2'] : ''; // NH2
            $row[] = $nv ? (float)$nv['nilai_harian_3'] : ''; // NH3
            $row[] = $nv ? (float)$nv['nilai_tugas']    : ''; // Tugas
        } elseif ($f_jenis === 'sts') {
            $row[] = $nv ? (float)$nv['nilai_sts']  : ''; // STS
        } else {
            $row[] = $nv ? (float)$nv['nilai_sas']  : ''; // SAS
        }
        $siswa_rows[] = $row;
    }

    // Definisi kolom
    $col_defs = [
        ['header'=>'No',         'field'=>'no',   'width'=>5,  'required'=>false,'type'=>'number'],
        ['header'=>'NIS',        'field'=>'nis',  'width'=>14, 'required'=>true, 'type'=>'text','format'=>'@',
         'note'=>'Nomor Induk Siswa — jangan diubah'],
        ['header'=>'Nama Siswa', 'field'=>'nama', 'width'=>28, 'required'=>false,'type'=>'text','format'=>'@',
         'note'=>'Nama siswa — hanya referensi, tidak dibaca saat import'],
    ];
    // Tentukan posisi kolom nilai (1-based) untuk extra rows
    $col_nh1 = $col_nh2 = $col_nh3 = $col_tugas = $col_sts = $col_sas = 0;
    if ($f_jenis === 'bulanan') {
        $col_nh1   = count($col_defs) + 1; $col_defs[] = ['header'=>'NH1',  'field'=>'nh1',  'width'=>10,'required'=>false,'type'=>'number','note'=>'Nilai Harian 1. Centang baris AKTIF di bawah untuk mengaktifkan kolom ini.'];
        $col_nh2   = count($col_defs) + 1; $col_defs[] = ['header'=>'NH2',  'field'=>'nh2',  'width'=>10,'required'=>false,'type'=>'number','note'=>'Nilai Harian 2'];
        $col_nh3   = count($col_defs) + 1; $col_defs[] = ['header'=>'NH3',  'field'=>'nh3',  'width'=>10,'required'=>false,'type'=>'number','note'=>'Nilai Harian 3'];
        $col_tugas = count($col_defs) + 1; $col_defs[] = ['header'=>'Tugas','field'=>'tugas','width'=>10,'required'=>false,'type'=>'number','note'=>'Nilai Tugas'];
    } elseif ($f_jenis === 'sts') {
        $col_sts   = count($col_defs) + 1; $col_defs[] = ['header'=>'Nilai STS','field'=>'sts','width'=>12,'required'=>true,'type'=>'number','note'=>'Nilai STS langsung'];
    } else {
        $col_sas   = count($col_defs) + 1; $col_defs[] = ['header'=>'Nilai SAS','field'=>'sas','width'=>12,'required'=>true,'type'=>'number','note'=>'Nilai SAS langsung'];
    }
    $sekolah_tmp = sekolah_info();
    $krit_a_min = (float)($sekolah_tmp['kriteria_a_min'] ?? 92);
    $krit_b_min = (float)($sekolah_tmp['kriteria_b_min'] ?? 83);
    $krit_c_min = (float)($sekolah_tmp['kriteria_c_min'] ?? 76);

    // Extra rows: section header + baris AKTIF (checklist) + 4 baris deskripsi
    $extra_rows = [];

    // ── Section header ──────────────────────────────────────────
    $extra_rows[] = [
        'type'       => 'section',
        'label'      => '  PENGATURAN KOLOM & DESKRIPSI  —  Isi bagian ini, dibaca otomatis saat import',
        'bg'         => 'FF334155',
        'label_bg'   => 'FF334155',
        'font_color' => 'FFFFFFFF',
        'bold'       => true,
        'height'     => 20,
    ];

    // ── Baris AKTIF kolom (hanya untuk bulanan) ─────────────────
    if ($f_jenis === 'bulanan') {
        // value_cols: key = 1-based column index, value = 'YA'|'TIDAK'
        // label_cols=2 → label merge A+B (No+NIS), YA/TIDAK mulai dari col 3 (Nama Siswa=★, NH1+=value)
        $aktif_vals = [];
        $aktif_bgs  = [];
        if ($col_nh1) {
            $aktif_vals[$col_nh1] = $aktif_nh1   ? 'YA' : 'TIDAK';
            $aktif_bgs[$col_nh1]  = $aktif_nh1   ? 'FFD1FAE5' : 'FFF1F5F9';
        }
        if ($col_nh2) {
            $aktif_vals[$col_nh2] = $aktif_nh2   ? 'YA' : 'TIDAK';
            $aktif_bgs[$col_nh2]  = $aktif_nh2   ? 'FFD1FAE5' : 'FFF1F5F9';
        }
        if ($col_nh3) {
            $aktif_vals[$col_nh3] = $aktif_nh3   ? 'YA' : 'TIDAK';
            $aktif_bgs[$col_nh3]  = $aktif_nh3   ? 'FFD1FAE5' : 'FFF1F5F9';
        }
        if ($col_tugas) {
            $aktif_vals[$col_tugas] = $aktif_tugas ? 'YA' : 'TIDAK';
            $aktif_bgs[$col_tugas]  = $aktif_tugas ? 'FFD1FAE5' : 'FFF1F5F9';
        }
        $extra_rows[] = [
            'type'       => 'checklist',
            'label'      => 'KOLOM AKTIF (Ya/Tidak)',
            'label_cols' => 3,   // merge No + NIS + Nama Siswa → sejajar header 3 kolom kiri
            'label_bg'   => 'FFF1F5F9',
            'value_cols' => $aktif_vals,
            'cell_bg'    => $aktif_bgs,
            'font_color' => 'FF1E293B',
            'bold'       => true,
            'height'     => 24,
        ];
    }

    // ── 4 baris deskripsi per predikat ──────────────────────────
    // type='input': label merge 3 kolom kiri (No+NIS+Nama), area input dari col 4→last (putih)
    $desk_defs = [
        'A' => ['label'=>'Deskripsi Predikat A  (nilai ≥'.$krit_a_min.')', 'label_bg'=>'FFF0FDF4', 'font'=>'FF166534'],
        'B' => ['label'=>'Deskripsi Predikat B  (nilai ≥'.$krit_b_min.')', 'label_bg'=>'FFEFF6FF', 'font'=>'FF1D4ED8'],
        'C' => ['label'=>'Deskripsi Predikat C  (nilai ≥'.$krit_c_min.')', 'label_bg'=>'FFFEFCE8', 'font'=>'FF854D0E'],
        'D' => ['label'=>'Deskripsi Predikat D  (nilai < '.$krit_c_min.')', 'label_bg'=>'FFFFF1F2', 'font'=>'FFB91C1C'],
    ];
    foreach ($desk_defs as $kr => $dd) {
        $extra_rows[] = [
            'type'        => 'input',
            'label'       => $dd['label'],
            'label_cols'  => 3,   // merge No + NIS + Nama Siswa → sejajar header 3 kolom kiri
            'label_bg'    => $dd['label_bg'],
            'font_color'  => $dd['font'],
            'bold'        => true,
            'input_value' => $desk_existing[$kr] ?? '',
            'input_bg'    => 'FFFFFFFF',
            'height'      => 32,
        ];
    }

    // Format: template_nilai_[mapel]_[periode].xlsx
    $mapel_slug   = preg_replace('/[^a-z0-9]+/i', '_', substr($mapel_row['nama_mapel'] ?? 'mapel', 0, 25));
    $periode_slug = ($f_bulan > 0) ? strtolower($bulan_nama) : ($f_jenis === 'semester' ? 'semester' : 'sts');
    $filename = 'template_nilai_' . $mapel_slug . '_' . $periode_slug . '.xlsx';

    $ta_info    = db_row('SELECT * FROM tahun_ajaran WHERE id=:id',[':id'=>$f_ta_id]);
    $sem_label  = ($ta_info['semester'] ?? 1) == 1 ? 'Semester Ganjil' : 'Semester Genap';
    $ta_nama    = $ta_info['nama'] ?? '';

    // 4 info rows → infoRowCount = ceil(8/2) = 4
    // layout: baris 1=title, 2-5=info(4 baris), 6=note, 7=header, 8=data
    // excel_parse harus skip 7 baris (1 title + 4 info + 1 note + 1 header)
    $bulk_info_rows = [
        ['Mata Pelajaran', $mapel_row['nama_mapel'] ?? '-'],
        ['Kelas',          $kelas_row['nama_kelas'] ?? '-'],
        ['Tahun Ajaran',   $ta_nama . ' ' . $sem_label],
        ['Guru Pengampu',  $guru_pgm['nama_lengkap'] ?? '-'],
        ['Jenis Raport',   $jenis_label[$f_jenis] ?? $f_jenis],
        ['Periode',        $periode_str ?: '-'],
        ['Jumlah Siswa',   count($siswa_bulk) . ' siswa'],
        ['Dibuat oleh',    'Raportku'],
    ];
    $bulk_info_row_count = (int)ceil(count($bulk_info_rows) / 2); // = 4
    $bulk_header_skip    = 1 + $bulk_info_row_count + 1 + 1;      // title + info + note + header = 7

    xlsx_template($filename, [
        'title'      => 'Template Nilai: ' . ($mapel_row['nama_mapel'] ?? '') . ' — Kelas ' . ($kelas_row['nama_kelas'] ?? ''),
        'info_rows'  => $bulk_info_rows,
        'note'       => 'Isi kolom nilai  ·  Ubah KOLOM AKTIF (Ya/Tidak)  ·  Isi Deskripsi per Predikat ',
        'columns'        => $col_defs,
        'examples'       => $siswa_rows,
        'extra_rows'     => $extra_rows,
        'notes_sheet' => [
            'title'   => 'META (jangan diubah)',
            'headers' => ['KEY','VALUE'],
            'rows'    => [
                ['BULK_MODE',   '1'],
                ['TA_ID',       $f_ta_id],
                ['KELAS_ID',    $f_kelas],
                ['MAPEL_ID',    $f_mapel],
                ['JENIS',       $f_jenis],
                ['BULAN',       $f_bulan ?: ''],
                ['KELAS_NAMA',  $kelas_row['nama_kelas'] ?? ''],
                ['MAPEL_NAMA',  $mapel_row['nama_mapel'] ?? ''],
                ['JENIS_LABEL', $jenis_label[$f_jenis] ?? $f_jenis],
                ['PERIODE',     $periode_str],
                ['GURU_NAMA',   $guru_pgm['nama_lengkap'] ?? ''],
                // Jumlah siswa → parser tahu sampai baris berapa data siswa
                ['SISWA_COUNT', count($siswa_bulk)],
            ],
        ],
    ]);
}

// ── Download Template Generik (lama) ─────────────────────────────
if (isset($_GET['download_template'])) {
    $mapel_list_all = db_query('SELECT id, nama_mapel, kelompok FROM mapel ORDER BY kelompok, urutan, nama_mapel');
    $kelas_list_all = db_query(
        'SELECT k.id, k.nama_kelas, k.tingkat, ta.nama as ta_nama, ta.id as ta_id
         FROM kelas k LEFT JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id
         ORDER BY ta.is_aktif DESC, k.nama_kelas'
    );
    $ta_ex    = $ta_aktif ?? ($ta_list[0] ?? ['id'=>1,'nama'=>'2024/2025']);
    $mapel_ex = $mapel_list_all[0]['nama_mapel'] ?? 'Matematika';
    $kelas_ex = $kelas_list_all[0]['nama_kelas'] ?? 'X IPA 1';
    $nis_ex   = '2024001';

    $ref_ta    = array_map(fn($ta) => [$ta['id'], $ta['nama'], 'Sem.'.$ta['semester'], $ta['is_aktif']?'★':''], $ta_list);
    $ref_mapel = array_map(fn($m) => [$m['nama_mapel'], $m['kelompok']], $mapel_list_all);
    $ref_kelas = array_map(fn($k) => [$k['nama_kelas'], $k['tingkat'], $k['ta_nama'], $k['ta_id']], $kelas_list_all);

    $max_ref = max(count($ref_ta), count($ref_mapel), count($ref_kelas));
    $ref_rows = [];
    for ($i = 0; $i < $max_ref; $i++) {
        $ta = $ref_ta[$i]    ?? ['','','',''];
        $m  = $ref_mapel[$i] ?? ['',''];
        $k  = $ref_kelas[$i] ?? ['','','',''];
        $ref_rows[] = [$ta[0],$ta[1],$ta[2],$ta[3],'', $m[0],$m[1],'', $k[0],$k[1],$k[2],'(TA ID: '.$k[3].')'];
    }

    xlsx_template('template_nilai.xlsx', [
        'title'    => 'Template Import Nilai - Raportku',
        'subtitle' => 'Kolom merah = wajib  |  Lihat sheet "Referensi" untuk nama mapel, kelas, dan ID tahun ajaran yang valid',
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
             'example'=>7, 'note'=>'1-12. Wajib jika jenis=bulanan/sts.'],
            ['header'=>'Nilai Harian 1',  'field'=>'nh1',             'width'=>13,'required'=>false, 'type'=>'number','example'=>85],
            ['header'=>'Nilai Harian 2',  'field'=>'nh2',             'width'=>13,'required'=>false, 'type'=>'number','example'=>88],
            ['header'=>'Nilai Harian 3',  'field'=>'nh3',             'width'=>13,'required'=>false, 'type'=>'number','example'=>90],
            ['header'=>'Nilai Tugas',     'field'=>'tugas',           'width'=>12,'required'=>false, 'type'=>'number','example'=>80],
            ['header'=>'Nilai STS',       'field'=>'sts',             'width'=>12,'required'=>false, 'type'=>'number','example'=>0],
            ['header'=>'Nilai SAS',       'field'=>'sas',             'width'=>12,'required'=>false, 'type'=>'number','example'=>0],
            ['header'=>'Catatan',         'field'=>'catatan',         'width'=>28,'required'=>false, 'type'=>'text','format'=>'@','example'=>''],
        ],
        'examples' => [
            [$nis_ex, $mapel_ex, $kelas_ex, $ta_ex['id'], 'bulanan', 10, 90, 88, 0, 0, 0, 0, 'Contoh: 2 nilai harian'],
            [$nis_ex, $mapel_ex, $kelas_ex, $ta_ex['id'], 'semester', '', 0, 0, 0, 0, 0, 88, 'Contoh: semester'],
        ],
        'notes_sheet' => [
            'title'   => 'Referensi: Tahun Ajaran | Nama Mapel | Nama Kelas',
            'headers' => ['ID TA','Nama TA','Semester','Status','','Nama Mapel','Kelompok','','Nama Kelas','Tingkat','Tahun Ajaran','(TA ID)'],
            'rows'    => $ref_rows,
        ],
    ]);
}

/* ── Proses Import ──────────────────────────────────────────────── */
$results   = [];
$errors    = [];
$successes = 0;
$siswa_cache = [];
$mapel_cache = [];
$kelas_cache = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    if (!verify_csrf()) {
        $errors[] = 'Token tidak valid.';
    } else {
        // Bulk template: 1(title)+4(info)+1(note)+1(header) = 7 baris dilewati
        // Generic template: 3 baris dilewati
        $skip_rows = !empty($_POST['bulk_ta_id']) ? 7 : 3;
        $parsed = excel_parse($_FILES['excel_file'], $skip_rows);

        if (!$parsed['ok']) {
            $errors[] = $parsed['error'];
        } else {
            $rows_all = $parsed['rows'];

            // ── Deteksi mode: cek apakah ada sheet META ──────────────
            // Kita embed BULK_MODE di baris pertama data jika ada flag tersembunyi.
            // Deteksi: jika baris pertama kolom 0 = angka (No) dan kolom 1 = NIS singkat tanpa kolom kelas/ta
            // Cara sederhana: coba baca kolom 3 — jika bukan angka besar (bukan TA ID), kemungkinan bulk
            $is_bulk = false;
            $bulk_ta_id = 0; $bulk_kelas_id = 0; $bulk_mapel_id = 0;
            $bulk_jenis = ''; $bulk_bulan = null;

            // Cek dari hidden field POST yang dikirim bersama file upload
            if (!empty($_POST['bulk_ta_id'])) {
                $is_bulk       = true;
                $bulk_ta_id    = (int)$_POST['bulk_ta_id'];
                $bulk_kelas_id = (int)$_POST['bulk_kelas_id'];
                $bulk_mapel_id = (int)$_POST['bulk_mapel_id'];
                $bulk_jenis    = $_POST['bulk_jenis'] ?? 'bulanan';
                $bulk_bulan    = $_POST['bulk_bulan'] ? (int)$_POST['bulk_bulan'] : null;
            }

            if ($is_bulk) {
                // ── Mode Bulk: 1 baris per siswa ──────────────────────
                // Template baru: No(0)|NIS(1)|Nama(2)|NH1/STS/SAS(3)|NH2(4)?|NH3(5)?|Tugas(6)?
                // Extra rows setelah data: SECTION HEADER, AKTIF (YA/TIDAK), DESK_A, DESK_B, DESK_C, DESK_D
                $kelas_b = db_row('SELECT * FROM kelas WHERE id=:id',[':id'=>$bulk_kelas_id]);
                $mapel_b = db_row('SELECT * FROM mapel WHERE id=:id',[':id'=>$bulk_mapel_id]);

                if (!$kelas_b || !$mapel_b) {
                    $errors[] = 'Kelas atau mapel tidak valid.';
                } else {
                    // Pisahkan baris siswa dari baris extra (extra mulai setelah baris kosong)
                    // Ciri baris extra: kolom B (index 1) berisi teks non-NIS seperti 'AKTIF', atau label deskripsi
                    // Strategi: baris siswa = baris yang kolom 1 berisi angka (NIS)
                    $siswa_row_data  = [];
                    $extra_row_data  = [];
                    foreach ($rows_all as $row) {
                        $col1 = trim($row[1] ?? '');
                        if ($col1 !== '' && is_numeric($col1)) {
                            $siswa_row_data[] = $row;
                        } elseif ($col1 !== '' || trim($row[0] ?? '') !== '') {
                            $extra_row_data[] = $row;
                        }
                    }

                    // Parse extra rows: AKTIF row & deskripsi
                    $bulk_aktif_nh1 = $bulk_aktif_nh2 = $bulk_aktif_nh3 = $bulk_aktif_tugas = true;
                    $bulk_desk = ['A'=>'','B'=>'','C'=>'','D'=>''];
                    foreach ($extra_row_data as $er) {
                        $label = strtoupper(trim($er[0] ?? ''));
                        if (strpos($label, 'AKTIF') !== false) {
                            // Kolom 3=NH1, 4=NH2, 5=NH3, 6=Tugas (1-based: col3=index2 in 0-based row)
                            $bulk_aktif_nh1   = strtoupper(trim($er[3] ?? 'YA')) === 'YA';
                            $bulk_aktif_nh2   = strtoupper(trim($er[4] ?? 'YA')) === 'YA';
                            $bulk_aktif_nh3   = strtoupper(trim($er[5] ?? 'YA')) === 'YA';
                            $bulk_aktif_tugas = strtoupper(trim($er[6] ?? 'YA')) === 'YA';
                        } elseif (strpos($label, 'DESKRIPSI PREDIKAT A') !== false || strpos($label, 'DESK_A') !== false) {
                            // Input area starts at col 4 (index 3) karena label_cols=3 (No+NIS+Nama)
                            // Merged cell: PhpSpreadsheet hanya mengisi value di sel pertama merge (col D = index 3)
                            $bulk_desk['A'] = _first_nonempty($er, [3, 4, 5, 6, 1, 2]);
                        } elseif (strpos($label, 'DESKRIPSI PREDIKAT B') !== false || strpos($label, 'DESK_B') !== false) {
                            $bulk_desk['B'] = _first_nonempty($er, [3, 4, 5, 6, 1, 2]);
                        } elseif (strpos($label, 'DESKRIPSI PREDIKAT C') !== false || strpos($label, 'DESK_C') !== false) {
                            $bulk_desk['C'] = _first_nonempty($er, [3, 4, 5, 6, 1, 2]);
                        } elseif (strpos($label, 'DESKRIPSI PREDIKAT D') !== false || strpos($label, 'DESK_D') !== false) {
                            $bulk_desk['D'] = _first_nonempty($er, [3, 4, 5, 6, 1, 2]);
                        }
                    }

                    // Simpan deskripsi jika ada yang diisi
                    $desk_saved_count = 0;
                    foreach ($bulk_desk as $kr => $desk_text) {
                        if ($desk_text !== '') {
                            try {
                                db_execute(
                                    'INSERT INTO nilai_deskripsi
                                        (mapel_id,kelas_id,tahun_ajaran_id,jenis_raport,bulan,kriteria,deskripsi)
                                     VALUES (:m,:k,:ta,:j,:b,:kr,:d)
                                     ON DUPLICATE KEY UPDATE deskripsi=VALUES(deskripsi)',
                                    [':m'=>$bulk_mapel_id,':k'=>$bulk_kelas_id,':ta'=>$bulk_ta_id,
                                     ':j'=>$bulk_jenis,':b'=>$bulk_bulan,':kr'=>$kr,':d'=>$desk_text]
                                );
                                $desk_saved_count++;
                            } catch (\PDOException $ex) { /* silent */ }
                        }
                    }
                    if ($desk_saved_count > 0) {
                        $results[] = ['line'=>'—','status'=>'ok','msg'=>"$desk_saved_count deskripsi predikat berhasil disimpan"];
                    }

                    // Proses baris siswa
                    foreach ($siswa_row_data as $i => $row) {
                        $line = $i + 4;
                        $nis  = trim($row[1] ?? '');
                        if (!$nis) {
                            $results[] = ['line'=>$line,'status'=>'skip','msg'=>'NIS kosong — dilewati'];
                            continue;
                        }

                        // Lookup siswa
                        if (!isset($siswa_cache[$nis])) {
                            $s = db_row('SELECT s.user_id FROM siswa s WHERE s.nis=:nis LIMIT 1',[':nis'=>$nis]);
                            $siswa_cache[$nis] = $s ? (int)$s['user_id'] : null;
                        }
                        if (!$siswa_cache[$nis]) {
                            $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: siswa tidak ditemukan — dilewati"];
                            continue;
                        }

                        // Baca nilai sesuai jenis + status aktif kolom
                        $h1 = $h2 = $h3 = $tug = $sts_v = $sas_v = 0.0;
                        if ($bulk_jenis === 'bulanan') {
                            $h1  = $bulk_aktif_nh1   ? excel_float($row, 3) : 0.0;
                            $h2  = $bulk_aktif_nh2   ? excel_float($row, 4) : 0.0;
                            $h3  = $bulk_aktif_nh3   ? excel_float($row, 5) : 0.0;
                            $tug = $bulk_aktif_tugas ? excel_float($row, 6) : 0.0;
                        } elseif ($bulk_jenis === 'sts') {
                            $sts_v = excel_float($row, 3);
                        } else {
                            $sas_v = excel_float($row, 3);
                        }

                        // Deskripsi per-siswa: kosong — akan diisi otomatis dari predikat saat save di bulk.php
                        // (import hanya menyimpan nilai; deskripsi per-siswa diserahkan ke bulk.php saat sinkronisasi)
                        $catatan = '';

                        // Hitung NA
                        if ($bulk_jenis === 'semester') {
                            $na = round($sas_v, 2);
                        } elseif ($bulk_jenis === 'sts') {
                            $na = round($sts_v, 2);
                        } else {
                            $komp = array_filter([$h1,$h2,$h3,$tug], fn($v) => $v > 0);
                            $na   = count($komp) > 0 ? round(array_sum($komp)/count($komp), 2) : 0;
                        }
                        $pred = ($na>=$krit_a)?'A':(($na>=$krit_b)?'B':(($na>=$krit_c)?'C':'D'));

                        // Deskripsi otomatis dari predikat
                        $auto_desk = $bulk_desk[$pred] ?? '';

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
                                [':sid'=>$siswa_cache[$nis],':m'=>$bulk_mapel_id,
                                 ':k'=>$bulk_kelas_id,':ta'=>$bulk_ta_id,':guru'=>$user['id'],
                                 ':j'=>$bulk_jenis,':b'=>$bulk_bulan,
                                 ':h1'=>$h1,':h2'=>$h2,':h3'=>$h3,
                                 ':tug'=>$tug,':sts'=>$sts_v,':sas'=>$sas_v,
                                 ':na'=>$na,':pred'=>$pred,':cat'=>$catatan]
                            );
                            // Update deskripsi per-siswa jika ada auto_desk
                            if ($auto_desk !== '' && $siswa_cache[$nis]) {
                                try {
                                    db_execute(
                                        'UPDATE nilai SET catatan=:d
                                         WHERE siswa_user_id=:sid AND mapel_id=:m AND kelas_id=:k
                                           AND tahun_ajaran_id=:ta AND jenis_raport=:j
                                           AND (bulan=:b OR (:b2 IS NULL AND bulan IS NULL))',
                                        [':d'=>$auto_desk,':sid'=>$siswa_cache[$nis],':m'=>$bulk_mapel_id,
                                         ':k'=>$bulk_kelas_id,':ta'=>$bulk_ta_id,':j'=>$bulk_jenis,
                                         ':b'=>$bulk_bulan,':b2'=>$bulk_bulan]
                                    );
                                } catch (\PDOException $ex) { /* silent */ }
                            }
                            $nama_siswa = trim($row[2] ?? $nis);
                            $results[]  = ['line'=>$line,'status'=>'ok',
                                'msg'=>"$nama_siswa (NIS $nis) | NA: $na ($pred)" . ($auto_desk ? ' | deskripsi ✓' : '') . " — berhasil"];
                            $successes++;
                        } catch (\PDOException $ex) {
                            $results[] = ['line'=>$line,'status'=>'error','msg'=>"NIS $nis: ".$ex->getMessage()];
                        }
                    }
                }
            } else {
                // ── Mode Generik (lama) ────────────────────────────────
                foreach ($rows_all as $i => $row) {
                    $line       = $i + 4;
                    $nis        = excel_str($row, 0, 20);
                    $mapel_nama = excel_str($row, 1, 255);
                    $kelas_nama = excel_str($row, 2, 100);
                    $ta_id_r    = excel_int($row, 3);
                    $jenis_r    = excel_str($row, 4, 20);
                    $bulan_raw  = trim($row[5] ?? '');
                    $h1         = excel_float($row, 6);
                    $h2         = excel_float($row, 7);
                    $h3         = excel_float($row, 8);
                    $tugas      = excel_float($row, 9);
                    $sts_val    = excel_float($row, 10);
                    $sas        = excel_float($row, 11);
                    $catatan    = excel_str($row, 12, 500);

                    if (!$nis)        { $results[] = ['line'=>$line,'status'=>'skip','msg'=>'NIS kosong — dilewati']; continue; }
                    if (!$mapel_nama) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: nama mapel kosong — dilewati"]; continue; }
                    if (!$kelas_nama) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: nama kelas kosong — dilewati"]; continue; }
                    if (!$ta_id_r)    { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: tahun_ajaran_id kosong — dilewati"]; continue; }
                    if (!in_array($jenis_r, ['bulanan','sts','semester'], true)) {
                        $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: jenis_raport '$jenis_r' tidak valid — dilewati"]; continue;
                    }
                    $bulan_r = null;
                    if ($jenis_r !== 'semester') {
                        $bulan_r = (int)$bulan_raw;
                        if ($bulan_r < 1 || $bulan_r > 12) {
                            $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: bulan harus 1-12 — dilewati"]; continue;
                        }
                    }
                    if (!isset($siswa_cache[$nis])) {
                        $s = db_row('SELECT s.user_id FROM siswa s WHERE s.nis=:nis LIMIT 1',[':nis'=>$nis]);
                        $siswa_cache[$nis] = $s ? (int)$s['user_id'] : null;
                    }
                    if (!$siswa_cache[$nis]) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: siswa tidak ditemukan — dilewati"]; continue; }

                    $mk = mb_strtolower($mapel_nama);
                    if (!isset($mapel_cache[$mk])) {
                        $m = db_row('SELECT id FROM mapel WHERE LOWER(nama_mapel)=LOWER(:n) LIMIT 1',[':n'=>$mapel_nama]);
                        $mapel_cache[$mk] = $m ? (int)$m['id'] : null;
                    }
                    if (!$mapel_cache[$mk]) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: mapel '$mapel_nama' tidak ditemukan — dilewati"]; continue; }

                    $kk = mb_strtolower($kelas_nama).'|'.$ta_id_r;
                    if (!isset($kelas_cache[$kk])) {
                        $k = db_row('SELECT id FROM kelas WHERE LOWER(nama_kelas)=LOWER(:n) AND tahun_ajaran_id=:ta LIMIT 1',
                            [':n'=>$kelas_nama,':ta'=>$ta_id_r]);
                        $kelas_cache[$kk] = $k ? (int)$k['id'] : null;
                    }
                    if (!$kelas_cache[$kk]) { $results[] = ['line'=>$line,'status'=>'skip','msg'=>"NIS $nis: kelas '$kelas_nama' tidak ditemukan — dilewati"]; continue; }

                    if ($jenis_r === 'semester')    $na = round($sas, 2);
                    elseif ($jenis_r === 'sts')     $na = round($sts_val, 2);
                    else {
                        $komp = array_filter([$h1,$h2,$h3,$tugas], fn($v) => $v > 0);
                        $na   = count($komp) > 0 ? round(array_sum($komp)/count($komp), 2) : 0;
                    }
                    $pred = ($na>=$krit_a)?'A':(($na>=$krit_b)?'B':(($na>=$krit_c)?'C':'D'));

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
                             ':k'=>$kelas_cache[$kk],':ta'=>$ta_id_r,':guru'=>$user['id'],
                             ':j'=>$jenis_r,':b'=>$bulan_r,
                             ':h1'=>$h1,':h2'=>$h2,':h3'=>$h3,
                             ':tug'=>$tugas,':sts'=>$sts_val,':sas'=>$sas,
                             ':na'=>$na,':pred'=>$pred,':cat'=>$catatan]
                        );
                        $results[] = ['line'=>$line,'status'=>'ok','msg'=>"NIS $nis | $mapel_nama | NA: $na ($pred) — berhasil"];
                        $successes++;
                    } catch (\PDOException $ex) {
                        $results[] = ['line'=>$line,'status'=>'error','msg'=>"NIS $nis: ".$ex->getMessage()];
                    }
                }
            }
            if ($successes > 0) {
                $desk_count = count(array_filter($bulk_desk ?? [], fn($d) => $d !== ''));
                $msg = "$successes data nilai berhasil diimport.";
                if ($desk_count > 0) $msg .= " $desk_count deskripsi predikat tersimpan.";
                flash('success', $msg);

                // Redirect ke halaman asal (bulk.php jika mode bulk, sinon daftar nilai)
                if ($is_bulk && $bulk_kelas_id && $bulk_mapel_id) {
                    // Sertakan state kolom aktif agar bulk.php bisa restore checkbox
                    $redir = 'index.php?page=nilai.bulk'
                        . '&ta='          . $bulk_ta_id
                        . '&kelas='       . $bulk_kelas_id
                        . '&mapel='       . $bulk_mapel_id
                        . '&jenis='       . urlencode($bulk_jenis)
                        . '&bulan='       . ($bulk_bulan ?? 0)
                        . '&aktif_nh1='   . ($bulk_aktif_nh1   ?? true ? 1 : 0)
                        . '&aktif_nh2='   . ($bulk_aktif_nh2   ?? true ? 1 : 0)
                        . '&aktif_nh3='   . ($bulk_aktif_nh3   ?? true ? 1 : 0)
                        . '&aktif_tugas=' . ($bulk_aktif_tugas ?? true ? 1 : 0);
                } else {
                    $redir = 'index.php?page=nilai.import'
                        . '&f_ta='    . $f_ta_id
                        . '&f_kelas=' . $f_kelas
                        . '&f_mapel=' . $f_mapel
                        . '&f_jenis=' . urlencode($f_jenis)
                        . '&f_bulan=' . $f_bulan;
                }
                header('Location: ' . $redir);
                exit;
            }
        }
    }
}

// ── Info untuk tampilan ──────────────────────────────────────────
$f_kelas_info   = $f_kelas ? db_row('SELECT * FROM kelas WHERE id=:id',[':id'=>$f_kelas]) : null;
$f_mapel_info   = $f_mapel ? db_row('SELECT * FROM mapel WHERE id=:id',[':id'=>$f_mapel]) : null;
$f_ta_info      = db_row('SELECT * FROM tahun_ajaran WHERE id=:id',[':id'=>$f_ta_id]);
$f_guru_pgm     = null;
if ($f_kelas && $f_mapel) {
    $f_guru_pgm = db_row(
        'SELECT u.nama_lengkap FROM users u
         INNER JOIN guru_mapel_kelas gmk ON gmk.guru_user_id=u.id
         WHERE gmk.kelas_id=:k AND gmk.mapel_id=:m AND gmk.tahun_ajaran_id=:ta LIMIT 1',
        [':k'=>$f_kelas,':m'=>$f_mapel,':ta'=>$f_ta_id]
    );
}
$f_siswa_count = $f_kelas ? (int)(db_row('SELECT COUNT(*) as n FROM siswa WHERE kelas_id=:k',[':k'=>$f_kelas])['n'] ?? 0) : 0;
$jenis_opts    = ['bulanan'=>'Bulanan (NH + Tugas)','sts'=>'STS – Tengah Semester','semester'=>'Semester (SAS)'];
$bulk_ready    = $f_kelas && $f_mapel && ($f_jenis === 'semester' || $f_bulan > 0);
$bulk_url      = 'index.php?page=nilai.import&download_bulk=1&f_ta='.$f_ta_id.'&f_kelas='.$f_kelas.'&f_mapel='.$f_mapel.'&f_jenis='.$f_jenis.'&f_bulan='.$f_bulan;
?>

<div class="page-content" style="max-width:1100px;margin:0 auto">
    <div class="page-header">
        <div>
            <h1 class="page-title">Import Nilai</h1>
            <p class="page-subtitle">Download template per kelas/mapel dengan daftar siswa otomatis, lalu upload kembali
            </p>
        </div>
        <?php
        // Tombol kembali: ke bulk.php dengan parameter jika dari sana, atau ke daftar nilai
        $back_url = ($f_kelas && $f_mapel)
            ? 'index.php?page=nilai.bulk&ta='.$f_ta_id.'&kelas='.$f_kelas.'&mapel='.$f_mapel.'&jenis='.e($f_jenis).'&bulan='.$f_bulan
            : 'index.php?page=nilai';
        ?>
        <a href="<?= $back_url ?>" class="btn btn-secondary" style="border-radius:30px">&larr; Kembali</a>
    </div>

    <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <!-- ── BAGIAN 1: Template Pintar per Kelas/Mapel ──────────────── -->
    <div class="card mb-5">
        <div class="card-header">
            <span class="flex items-center gap-2">
                <svg width="16" height="16" fill="none" stroke="#f97316" stroke-width="2.2" viewBox="0 0 24 24">
                    <path d="M9 11l3 3L22 4" />
                    <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" />
                </svg>
                <strong>Template</strong>
                <span class="badge badge-orange" style="font-size:.68rem">Rekomendasi</span>
            </span>
            <span class="text-xs text-gray-400">Daftar siswa sudah terisi otomatis</span>
        </div>
        <div class="card-body">
            <!-- Filter pilih kelas/mapel/jenis/bulan -->
            <form method="GET" action="index.php" id="formBulkFilter">
                <input type="hidden" name="page" value="nilai.import">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
                    <!-- Tahun Ajaran -->
                    <div>
                        <label class="form-label">Tahun Ajaran</label>
                        <select name="f_ta" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($ta_list as $ta): ?>
                            <option value="<?= $ta['id'] ?>" <?= $ta['id']==$f_ta_id?'selected':'' ?>>
                                <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Kelas -->
                    <div>
                        <label class="form-label">Kelas</label>
                        <select name="f_kelas" class="form-control" onchange="this.form.submit()">
                            <option value="">— Pilih —</option>
                            <?php foreach ($kelas_list as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $k['id']==$f_kelas?'selected':'' ?>>
                                <?= e($k['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Mapel -->
                    <div>
                        <label class="form-label">Mata Pelajaran</label>
                        <select name="f_mapel" class="form-control" onchange="this.form.submit()">
                            <option value="">— Pilih —</option>
                            <?php foreach ($mapel_list as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $m['id']==$f_mapel?'selected':'' ?>>
                                <?= e($m['nama_mapel']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Jenis -->
                    <div>
                        <label class="form-label">Jenis Raport</label>
                        <select name="f_jenis" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($jenis_opts as $jk => $jv): ?>
                            <option value="<?= $jk ?>" <?= $f_jenis===$jk?'selected':'' ?>><?= $jv ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Bulan (hanya untuk bulanan/sts) -->
                    <?php if ($f_jenis !== 'semester'): ?>
                    <div>
                        <label class="form-label">Bulan</label>
                        <select name="f_bulan" class="form-control" onchange="this.form.submit()">
                            <option value="">— Pilih —</option>
                            <?php foreach (BULAN_ID as $bk => $bv): ?>
                            <option value="<?= $bk ?>" <?= $bk==$f_bulan?'selected':'' ?>><?= $bv ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="f_bulan" value="0">
                    <?php endif; ?>
                    <div class="flex items-end">
                        <button type="submit" class="btn btn-secondary w-full"
                            style="border-radius:30px">Terapkan</button>
                    </div>
                </div>
            </form>

            <!-- Info kelas/mapel terpilih -->
            <?php if ($f_kelas && $f_mapel): ?>
            <div class="mb-4 p-3 rounded-lg" style="background:#fff7ed;border-left:4px solid #f97316;font-size:.85rem">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-1">
                    <div><span class="text-gray-400">Mata Pelajaran</span><br>
                        <strong><?= e($f_mapel_info['nama_mapel'] ?? '-') ?></strong>
                    </div>
                    <div><span class="text-gray-400">Kelas</span><br>
                        <strong><?= e($f_kelas_info['nama_kelas'] ?? '-') ?></strong>
                    </div>
                    <div><span class="text-gray-400">Guru Pengampu</span><br>
                        <strong class="text-orange-600"><?= e($f_guru_pgm['nama_lengkap'] ?? '—') ?></strong>
                    </div>
                    <div><span class="text-gray-400">Jenis Raport</span><br>
                        <strong><?= e($jenis_opts[$f_jenis] ?? $f_jenis) ?></strong>
                    </div>
                    <div><span class="text-gray-400">Periode</span><br>
                        <strong><?= $f_jenis !== 'semester' && $f_bulan ? e(BULAN_ID[$f_bulan] ?? '-') : 'Semester' ?></strong>
                    </div>
                    <div><span class="text-gray-400">Jumlah Siswa</span><br>
                        <strong><?= $f_siswa_count ?> siswa</strong>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tombol download template bulk -->
            <div class="flex items-center gap-3 flex-wrap">
                <?php if ($bulk_ready): ?>
                <a href="<?= $bulk_url ?>" class="btn btn-primary" style="border-radius:30px">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    Download Template (<?= $f_siswa_count ?> Siswa)
                </a>
                <span class="text-xs text-gray-400">
                    Template berisi daftar siswa kelas <strong><?= e($f_kelas_info['nama_kelas'] ?? '') ?></strong>
                    — tinggal isi kolom nilai
                </span>
                <?php elseif ($f_kelas && $f_mapel && $f_jenis !== 'semester' && !$f_bulan): ?>
                <div class="text-sm text-orange-600 font-semibold">
                    ⚠ Pilih <strong>Bulan</strong> terlebih dahulu untuk mengunduh template
                </div>
                <?php elseif (!$f_kelas || !$f_mapel): ?>
                <div class="text-sm text-gray-400">
                    Pilih <strong>Kelas</strong> dan <strong>Mata Pelajaran</strong> untuk mengunduh template yang sudah
                    berisi daftar siswa
                </div>
                <?php endif; ?>
            </div>

            <!-- Langkah penggunaan -->
            <div class="mt-4 p-3 rounded-lg text-xs"
                style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;line-height:1.8">
                <strong>✓ Cara pakai Template:</strong>
                <ol style="padding-left:16px;margin:4px 0">
                    <li>Pilih Kelas, Mapel, Jenis Raport, dan Bulan di atas</li>
                    <li>Klik <strong>Download Template</strong> — daftar siswa sudah terisi otomatis</li>
                    <li>Isi kolom nilai (NH1, NH2, dst.) di baris masing-masing siswa</li>
                    <li><strong>Jangan ubah</strong> kolom No, NIS, dan Nama Siswa</li>
                    <li>Upload file di bawah — pastikan pilih <strong>Mode Semua</strong></li>
                </ol>
            </div>
        </div>
    </div>

    <!-- ── BAGIAN 2: Upload File ───────────────────────────────── -->
    <div class="grid grid-cols-1 gap-5" style="max-width:640px">
        <!-- Upload Bulk (Template Pintar) — satu-satunya card -->
        <div class="card" style="border-top:3px solid #f97316">
            <div class="card-header">
                <span>Upload Template</span>
                <span class="badge badge-orange">Semua</span>
            </div>
            <div class="card-body">
                <form method="POST" action="index.php?page=nilai.import" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <!-- Hidden: metadata bulk -->
                    <input type="hidden" name="bulk_ta_id" value="<?= $f_ta_id ?>">
                    <input type="hidden" name="bulk_kelas_id" value="<?= $f_kelas ?>">
                    <input type="hidden" name="bulk_mapel_id" value="<?= $f_mapel ?>">
                    <input type="hidden" name="bulk_jenis" value="<?= e($f_jenis) ?>">
                    <input type="hidden" name="bulk_bulan" value="<?= $f_bulan ?>">

                    <?php if ($f_kelas && $f_mapel): ?>
                    <div class="p-2 rounded text-xs mb-3"
                        style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e">
                        <strong>Template aktif:</strong>
                        <?= e($f_kelas_info['nama_kelas'] ?? '') ?> &bull;
                        <?= e($f_mapel_info['nama_mapel'] ?? '') ?> &bull;
                        <?= e($jenis_opts[$f_jenis] ?? $f_jenis) ?>
                        <?= $f_bulan ? ' &bull; ' . e(BULAN_ID[$f_bulan] ?? '') : '' ?>
                    </div>
                    <?php else: ?>
                    <div class="p-2 rounded text-xs mb-3" style="background:#f3f4f6;color:#6b7280">
                        Pilih Kelas &amp; Mapel di bagian atas terlebih dahulu sebelum upload template bulk.
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">File Template (.xlsx) <span class="text-red-500">*</span></label>
                        <input type="file" name="excel_file" accept=".xlsx,.xls" class="form-control"
                            style="padding:6px;font-size:.875rem" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-full justify-center" style="border-radius:30px"
                        <?= (!$f_kelas || !$f_mapel) ? 'disabled title="Pilih kelas & mapel dulu"' : '' ?>>
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

    <!-- ── Hasil Import ───────────────────────────────────────── -->
    <?php if (!empty($results)): ?>
    <div class="card mt-5">
        <div class="card-header">
            <span>Hasil Import</span>
            <span class="badge <?= $successes > 0 ? 'badge-green' : 'badge-red' ?>">
                <?= $successes ?> berhasil / <?= count($results) - $successes ?> dilewati
            </span>
        </div>
        <div class="table-container" style="max-height:360px;overflow-y:auto">
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