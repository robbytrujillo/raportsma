<?php
/**
 * pages/raport/leger.php
 * Leger Rapor — tabel rekap semua siswa × semua mapel dalam satu kelas.
 * Akses: super_admin, admin, kepala_sekolah, wali_kelas (kelasnya sendiri saja),
 *        guru (hanya kelas yang diajarnya).
 * Siswa: ditolak dengan redirect.
 */
require_once __DIR__ . '/../../auth.php';
require_login();

$user_ses = current_user();
$role     = $user_ses['role'];

// Guard server-side: redirect jika role tidak diizinkan
if (!in_array($role, ['super_admin', 'admin', 'kepala_sekolah', 'wali_kelas', 'guru'])) {
    header('Location: ' . page_url($role === 'siswa' ? 'raport' : 'dashboard'));
    exit;
}

$ta_id    = (int)($_GET['ta']    ?? 0);
$kelas_id = (int)($_GET['kelas'] ?? 0);
$jenis    = $_GET['jenis'] ?? 'bulanan';
$bulan    = (int)($_GET['bulan']  ?? 0);

if (!$ta_id || !$kelas_id) {
    http_response_code(400); echo 'Parameter ta dan kelas wajib diisi.'; exit;
}

require_once __DIR__ . '/../../db.php';

// Verifikasi akses lebih lanjut untuk kepala_sekolah (unit)
// wali_kelas dan guru boleh akses leger kelas manapun
if ($role === 'kepala_sekolah') {
    // Kepala sekolah hanya bisa akses leger kelas di unitnya
    $_unit_f_lg = get_user_unit_filter($user_ses);
    if (!empty($_unit_f_lg['units'])) {
        $kelas_unit_lg = db_row('SELECT unit FROM kelas WHERE id = :id', [':id' => $kelas_id])['unit'] ?? 'campuran';
        if (!in_array($kelas_unit_lg, $_unit_f_lg['units'])) {
            http_response_code(403); echo 'Akses ditolak: kelas ini tidak berada di unit Anda.'; exit;
        }
    }
}

// Data kelas & TA (ambil lengkap termasuk tanda_tangan wali kelas untuk TTD)
$kelas = db_row('SELECT k.*, u.nama_lengkap as wali_nama, u.tanda_tangan as wali_ttd FROM kelas k LEFT JOIN users u ON u.id = k.wali_kelas_id WHERE k.id = :id', [':id' => $kelas_id]);
$ta    = db_row('SELECT * FROM tahun_ajaran WHERE id = :id', [':id' => $ta_id]);

if (!$kelas || !$ta) {
    http_response_code(404); echo 'Kelas atau Tahun Ajaran tidak ditemukan.'; exit;
}

$sekolah      = sekolah_info();
$jenis_label  = ['bulanan'=>'Bulanan','sts'=>'ASTS','semester'=>'Semester'][$jenis] ?? ucfirst($jenis);
$bulan_nama   = (in_array($jenis, ['bulanan','sts']) && $bulan) ? (BULAN_ID[$bulan] ?? '') : '';
$semester_lbl = ($ta['semester'] ?? 1) == 1 ? 'Ganjil' : 'Genap';

// ── Daftar mapel dari PENUGASAN GURU (selalu tampil meski belum ada nilai) ──
// Sumber: guru_mapel_kelas, bukan dari tabel nilai.
// Urutan: kelompok → urutan parent → urutan mapel → nama_mapel
$mapel_raw = db_query(
    "SELECT DISTINCT m.id, m.nama_mapel, m.urutan, m.kelompok, m.parent_id,
            p.nama_mapel as parent_nama, p.kelompok as parent_kelompok,
            p.urutan as parent_urutan
     FROM guru_mapel_kelas gmk
     INNER JOIN mapel m ON m.id = gmk.mapel_id
     LEFT  JOIN mapel p ON p.id = m.parent_id
     WHERE gmk.kelas_id = :kid AND gmk.tahun_ajaran_id = :ta
     ORDER BY m.kelompok, m.urutan, m.nama_mapel",
    [':kid' => $kelas_id, ':ta' => $ta_id]
);

// Ambil data parent langsung dari DB agar urutan induk selalu akurat
$_parent_ids_lg = array_filter(array_unique(array_column($mapel_raw, 'parent_id')));
$_parent_db_lg  = [];
if (!empty($_parent_ids_lg)) {
    $in_lg = implode(',', array_map('intval', $_parent_ids_lg));
    foreach (db_query("SELECT id, nama_mapel, kelompok, urutan FROM mapel WHERE id IN ($in_lg)") as $pr) {
        $_parent_db_lg[$pr['id']] = $pr;
    }
}

// Susun ulang sesuai logika raport: kelompok → urutan (parent jika sub-mapel) → urutan sub
$_singles            = [];
$_children_by_parent = [];
$_parent_meta        = [];

foreach ($mapel_raw as $mi) {
    if ($mi['parent_id'] !== null) {
        $pid = (int)$mi['parent_id'];
        $_children_by_parent[$pid][] = $mi;
        if (!isset($_parent_meta[$pid])) {
            // Prioritas: data parent langsung dari DB → fallback ke JOIN
            $pd = $_parent_db_lg[$pid] ?? null;
            $_parent_meta[$pid] = [
                'kelompok' => $pd['kelompok'] ?? $mi['parent_kelompok'] ?? $mi['kelompok'],
                'urutan'   => isset($pd['urutan']) ? (int)$pd['urutan'] : (int)($mi['parent_urutan'] ?? 0),
                'nama'     => $pd['nama_mapel']  ?? $mi['parent_nama']  ?? '',
            ];
        }
    } else {
        $_singles[] = $mi;
    }
}

$_slots = [];
foreach ($_singles as $mi) {
    $_slots[] = [
        'kelompok' => $mi['kelompok'],
        'urutan'   => (int)($mi['urutan'] ?? 0),
        'type'     => 'single',
        'items'    => [$mi],
    ];
}
foreach ($_children_by_parent as $pid => $children) {
    $pm = $_parent_meta[$pid];
    $_slots[] = [
        'kelompok' => $pm['kelompok'],
        'urutan'   => $pm['urutan'],
        'type'     => 'group',
        'items'    => $children,
    ];
}
usort($_slots, function($a, $b) {
    $kg = strcmp($a['kelompok'] ?? '', $b['kelompok'] ?? '');
    if ($kg !== 0) return $kg;
    return $a['urutan'] <=> $b['urutan'];
});

// Flatten ke $mapel_list (array datar, tiap item = 1 kolom di tabel)
$mapel_list = [];
foreach ($_slots as $slot) {
    foreach ($slot['items'] as $mi) {
        $mapel_list[] = $mi;
    }
}

// ── Daftar siswa — default sort nama A-Z, peringkat dihitung dari avg nilai ──
$bulan_cond_s  = '';
$siswa_params  = [':kid' => $kelas_id, ':kid2' => $kelas_id, ':ta' => $ta_id, ':jenis' => $jenis];
if (in_array($jenis, ['bulanan','sts']) && $bulan) {
    $bulan_cond_s = 'AND n.bulan = :bulan';
    $siswa_params[':bulan'] = $bulan;
}
$siswa_list = db_query(
    "SELECT u.id, u.nama_lengkap, s.nis, s.nisn,
            ROUND(AVG(n.nilai_akhir),2) as avg_nilai
     FROM users u
     INNER JOIN siswa s ON s.user_id = u.id
     LEFT JOIN nilai n ON n.siswa_user_id = u.id
                       AND n.kelas_id = :kid
                       AND n.tahun_ajaran_id = :ta
                       AND n.jenis_raport = :jenis
                       $bulan_cond_s
     WHERE s.kelas_id = :kid2
     GROUP BY u.id, u.nama_lengkap, s.nis, s.nisn
     ORDER BY u.nama_lengkap ASC",
    $siswa_params
);

// Hitung peringkat dari avg (urut DESC) — simpan sebagai rank_map[siswa_id] = rank
$_rank_sorted = $siswa_list;
usort($_rank_sorted, fn($a,$b) => (float)$b['avg_nilai'] <=> (float)$a['avg_nilai']);
$rank_map = [];
foreach ($_rank_sorted as $_ri => $_rs) {
    $rank_map[(int)$_rs['id']] = $_ri + 1;
}
$total_siswa = count($siswa_list);

if (empty($mapel_list)) {
    http_response_code(404);
    echo 'Belum ada penugasan guru untuk kelas ini.'; exit;
}

// ── Ambil semua nilai sekaligus (1 query) ───────────────────
$bulan_cond_v  = '';
$nilai_params  = [':kid' => $kelas_id, ':ta' => $ta_id, ':jenis' => $jenis];
if (in_array($jenis, ['bulanan','sts']) && $bulan) {
    $bulan_cond_v = 'AND bulan = :bulan';
    $nilai_params[':bulan'] = $bulan;
}
$all_nilai_rows = db_query(
    "SELECT siswa_user_id, mapel_id, nilai_akhir
     FROM nilai
     WHERE kelas_id = :kid
       AND tahun_ajaran_id = :ta
       AND jenis_raport = :jenis
       $bulan_cond_v",
    $nilai_params
);

// Indeks: $nilai_map[siswa_id][mapel_id] = nilai_akhir
// Key ada (meski nilai 0.0) = sudah diinput; key tidak ada = belum diinput sama sekali
$nilai_map = [];
foreach ($all_nilai_rows as $r) {
    $nilai_map[$r['siswa_user_id']][$r['mapel_id']] = (float)$r['nilai_akhir'];
}

// Hitung peringkat (posisi dalam array sudah urut rata-rata DESC)
$total_siswa = count($siswa_list);
$krit_a = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c = (float)($sekolah['kriteria_c_min'] ?? 76);

// Kepala sekolah beserta tanda tangan — pilih berdasarkan unit kelas
$kelas_unit_lg = $kelas['unit'] ?? 'campuran';
if ($kelas_unit_lg === 'putri') {
    $sekolah_si_lg = db_row('SELECT kepala_sekolah_putri, nip_kepala_putri FROM sekolah_info ORDER BY id LIMIT 1');
    $kepsek_putri_nm = $sekolah_si_lg['kepala_sekolah_putri'] ?? null;
    if ($kepsek_putri_nm) {
        $kepsek = db_row('SELECT nama_lengkap, tanda_tangan FROM users WHERE role = "kepala_sekolah" AND nama_lengkap = :nm LIMIT 1',
            [':nm' => $kepsek_putri_nm]);
        if (!$kepsek) {
            $kepsek = ['nama_lengkap' => $kepsek_putri_nm, 'tanda_tangan' => null];
        }
    } else {
        $kepsek = db_row('SELECT nama_lengkap, tanda_tangan FROM users WHERE role = "kepala_sekolah" LIMIT 1');
    }
} else {
    $kepsek = db_row('SELECT nama_lengkap, tanda_tangan FROM users WHERE role = "kepala_sekolah" LIMIT 1');
    if (!$kepsek) {
        $sekolah_si_lg = db_row('SELECT kepala_sekolah, nip_kepala FROM sekolah_info ORDER BY id LIMIT 1');
        if (!empty($sekolah_si_lg['kepala_sekolah'])) {
            $kepsek = ['nama_lengkap' => $sekolah_si_lg['kepala_sekolah'], 'tanda_tangan' => null];
        }
    }
}

// Tanggal cetak
$bulan_id_map = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
    7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
];
$tgl_ts    = time();
$tgl_cetak = ($sekolah['kota'] ?? 'Depok') . ', '
    . (int)date('j', $tgl_ts) . ' '
    . ($bulan_id_map[(int)date('n', $tgl_ts)] ?? date('F', $tgl_ts))
    . ' ' . date('Y', $tgl_ts);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leger Rapor <?= htmlspecialchars($kelas['nama_kelas']) ?> | <?= htmlspecialchars($ta['nama']) ?>
    </title>
    <!-- SheetJS lokal (hanya dipakai sebagai fallback; export utama via PHP server-side) -->
    <style>
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: Arial, sans-serif;
        background: #f3f4f6;
        font-size: 11px;
        padding-top: 56px;
    }

    /* ── Toolbar ── */
    .leger-toolbar {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 999;
        background: #f97316;
        color: #fff;
        padding: 8px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        flex-wrap: wrap;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
    }

    .leger-toolbar .tb-title {
        font-weight: 700;
        font-size: .9rem;
    }

    .leger-toolbar .tb-meta {
        font-size: .75rem;
        opacity: .85;
    }

    .leger-toolbar .tb-btns {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    /* ── Filter dalam leger-paper ── */
    .leger-filter-panel {
        background: #fff7ed;
        border: 1px solid #fed7aa;
        border-radius: 8px;
        padding: 7px 12px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        font-size: .8rem;
    }

    .leger-filter-panel label {
        font-weight: 700;
        color: #7c2d12;
        white-space: nowrap;
        font-size: .78rem;
    }

    .abjad-btn-wrap {
        display: flex;
        gap: 3px;
        flex-wrap: wrap;
        align-items: center;
    }

    .btn-abjad {
        background: #fff;
        border: 1px solid #fdba74;
        color: #9a3412;
        padding: 2px 7px;
        border-radius: 4px;
        cursor: pointer;
        font-size: .75rem;
        font-weight: 600;
        transition: background .15s, color .15s;
        line-height: 1.4;
    }

    .btn-abjad.active,
    .btn-abjad:hover {
        background: #f97316;
        color: #fff;
        border-color: #f97316;
    }

    .filter-sep {
        width: 1px;
        height: 18px;
        background: #fed7aa;
        display: inline-block;
        margin: 0 2px;
    }

    .sort-select {
        border: 1px solid #fdba74;
        border-radius: 6px;
        padding: 3px 8px;
        font-size: .77rem;
        color: #7c2d12;
        background: #fff;
        cursor: pointer;
    }

    .sort-select:focus {
        outline: none;
        border-color: #f97316;
    }

    /* Hanya sembunyikan elemen non-print saat cetak */
    @media print {
        .leger-filter-panel {
            display: none !important;
        }
    }

    .btn-xlsx {
        background: #16a34a !important;
        border-color: #16a34a !important;
        color: #fff !important;
        border-radius: 30px !important;
    }

    .btn-xlsx:hover {
        background: #15803d !important;
    }

    .leger-toolbar a,
    .leger-toolbar button {
        background: rgba(255, 255, 255, .2);
        border: 1px solid rgba(255, 255, 255, .5);
        color: #fff;
        padding: 5px 12px;
        border-radius: 8px;
        cursor: pointer;
        font-size: .8rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    .leger-toolbar a:hover,
    .leger-toolbar button:hover {
        background: rgba(255, 255, 255, .35);
    }

    .leger-toolbar .btn-print {
        background: rgba(255, 255, 255, .9);
        color: #f97316;
    }

    .leger-toolbar .btn-print:hover {
        background: #fff;
    }

    /* ── Wrapper kertas ── */
    .leger-paper {
        background: #fff;
        margin: 16px auto 32px;
        padding: 20px 18px;
        width: max-content;
        min-width: 800px;
        max-width: 98vw;
        overflow-x: auto;
        box-shadow: 0 4px 24px rgba(0, 0, 0, .12);
    }

    /* ── Kepala surat ── */
    .leger-header {
        text-align: center;
        margin-bottom: 12px;
    }

    .leger-header .judul {
        font-weight: 700;
        font-size: 13px;
        text-transform: uppercase;
        line-height: 1.6;
    }

    .leger-meta {
        display: grid;
        grid-template-columns: auto 1fr auto auto auto 1fr;
        gap: 4px 8px;
        font-size: 11px;
        margin-bottom: 10px;
        align-items: center;
    }

    .leger-meta .label {
        font-weight: 600;
        white-space: nowrap;
    }

    .leger-meta .val {
        border-bottom: 1px solid #333;
        padding: 1px 4px;
        min-width: 120px;
    }

    /* ── Tabel leger ── */
    .leger-table {
        border-collapse: collapse;
        width: 100%;
        font-size: 10px;
    }

    .leger-table th,
    .leger-table td {
        border: 1px solid #333;
        padding: 3px 4px;
        text-align: center;
        vertical-align: middle;
        white-space: nowrap;
    }

    .leger-table th {
        background: #e8f5e9;
        font-weight: 700;
    }

    /* Header grup PENGETAHUAN */
    .th-group-pengetahuan {
        background: #c8e6c9 !important;
        color: #1b5e20;
        font-size: 11px;
    }

    /* Kolom tetap kiri (NO, NISN, NAMA) */
    .leger-table .col-no {
        width: 28px;
        min-width: 28px;
    }

    .leger-table .col-nisn {
        width: 90px;
        min-width: 90px;
    }

    .leger-table .col-nama {
        text-align: left;
        min-width: 160px;
        max-width: 200px;
        white-space: normal;
        font-weight: 600;
    }

    /* Kolom nilai mapel */
    .leger-table .col-mapel {
        min-width: 40px;
    }

    /* Kolom total & rata-rata */
    .leger-table .col-total {
        min-width: 50px;
        font-weight: 700;
    }

    .leger-table .col-rata {
        min-width: 55px;
        font-weight: 700;
        color: #e65100;
    }

    .leger-table .col-rank {
        min-width: 45px;
        font-weight: 700;
    }

    /* Header mapel — teks horizontal, wrap, lebar kolom tetap kecil */
    .leger-table th.th-mapel {
        padding: 5px 3px;
        vertical-align: middle;
        min-width: 42px;
        max-width: 60px;
        white-space: normal;
        word-break: break-word;
        font-size: 9px;
        line-height: 1.3;
    }

    /* Row baris nomor kolom */
    .leger-table .row-col-num td {
        background: #fff9c4;
        font-weight: 700;
        font-size: 10px;
    }

    /* Nilai rendah — highlight merah muda */
    .val-low {
        background: #ffe0e0 !important;
        color: #c62828;
    }

    /* Baris zebra */
    .leger-table tbody tr:nth-child(odd) td {
        background: #fafafa;
    }

    .leger-table tbody tr:nth-child(even) td {
        background: #ffffff;
    }

    /* Override untuk highlight */
    .leger-table tbody tr td.val-low {
        background: #ffe0e0 !important;
    }

    /* Tanda tangan */
    .ttd-block {
        width: 100%;
        border-collapse: collapse;
        margin-top: 32px;
        font-size: 11px;
    }

    .ttd-block td {
        vertical-align: top;
        text-align: center;
        padding: 0 12px;
    }

    .ttd-block .ttd-label {
        margin-bottom: 4px;
    }

    .ttd-block .ttd-img-wrap {
        height: 70px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 6px 0;
    }

    .ttd-block .ttd-img-wrap img {
        max-height: 70px;
        max-width: 160px;
        object-fit: contain;
        display: block;
        margin: 0 auto;
    }

    .ttd-block .ttd-name {
        border-top: 1px solid #333;
        padding-top: 4px;
        font-weight: 700;
        display: inline-block;
        min-width: 180px;
    }

    /* ── @media print ── */
    @media print {
        body {
            background: #fff !important;
            padding-top: 0 !important;
            margin: 0 !important;
        }

        .leger-toolbar {
            display: none !important;
        }

        .leger-paper {
            margin: 0 !important;
            padding: 8px 6px !important;
            box-shadow: none !important;
            width: auto !important;
            max-width: none !important;
            overflow: visible !important;
        }

        .leger-table {
            font-size: 8px;
        }

        .leger-table .col-nama {
            min-width: 110px;
            max-width: 140px;
        }

        .leger-table .col-nisn {
            width: 70px;
            min-width: 70px;
            font-size: 7.5px;
        }

        .ttd-block {
            margin-top: 10px !important;
        }

        .ttd-block .ttd-img-wrap {
            height: 48px;
            margin: 3px 0;
        }

        .ttd-block .ttd-img-wrap img {
            max-height: 48px;
        }

        .leger-header .judul {
            font-size: 11px;
        }

        @page {
            size: landscape;
            margin: 6mm;
        }
    }

    /* Style zoom diinjeksi JS sebelum print */
    @media print {
        #leger-zoom-wrap {
            zoom: var(--leger-zoom, 1);
        }

        /* Sembunyikan chatbot saat cetak */
        #cbotBtn, #cbotModal, #cbotBadge { display: none !important; }

        /* Pastikan baris rata-rata ikut tercetak */
        .leger-table tfoot { display: table-footer-group; }
    }
    </style>
</head>

<body>

    <!-- ── Toolbar ── -->
    <div class="leger-toolbar">
        <div>
            <div class="tb-title">Leger Rapor | <?= htmlspecialchars($kelas['nama_kelas']) ?></div>
            <div class="tb-meta">
                <?= htmlspecialchars($ta['nama']) ?> &middot; Sem. <?= $semester_lbl ?>
                &middot; <?= $jenis_label ?><?= $bulan_nama ? " &mdash; $bulan_nama" : '' ?>
                &middot; <strong><?= $total_siswa ?> murid</strong>
                &middot; <strong><?= count($mapel_list) ?> mapel</strong>
            </div>
        </div>
        <div class="tb-btns">
            <a href="<?= page_url('raport', ['ta'=>$ta_id,'kelas'=>$kelas_id,'jenis'=>$jenis,'bulan'=>$bulan]) ?>"
                onclick="if(window.opener||history.length<=1){window.close();return false;}"
                style="border-radius: 30px">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <polyline points="15 18 9 12 15 6" />
                </svg>
                Kembali
            </a>
            <button class="btn-print" onclick="window.print()" style="border-radius: 30px">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="6 9 6 2 18 2 18 9" />
                    <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" />
                    <rect x="6" y="14" width="12" height="8" />
                </svg>
                Cetak / Simpan PDF
            </button>
            <a id="btnExportXlsx" href="#" onclick="doExportXlsx(event)"
               class="btn-print btn-xlsx" style="border-radius:30px">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="12" y1="11" x2="12" y2="17"/>
                    <polyline points="9 14 12 17 15 14"/>
                </svg>
                Export XLSX
            </a>
        </div>
    </div>

    <!-- ── Kertas Leger ── -->
    <div class="leger-paper">

        <!-- Panel Filter (di dalam paper, ikut print) -->
        <div class="leger-filter-panel" id="filterPanel">
            <label>Filter Nama:</label>
            <div class="abjad-btn-wrap" id="abjadWrap">
                <button class="btn-abjad active" data-abjad="ALL" onclick="filterAbjad(this)">Semua</button>
                <?php
                $huruf_list = range('A', 'Z');
                foreach ($huruf_list as $h): ?>
                <button class="btn-abjad" data-abjad="<?= $h ?>" onclick="filterAbjad(this)"><?= $h ?></button>
                <?php endforeach; ?>
            </div>
            <div class="filter-sep"></div>
            <label>Filter Peringkat:</label>
            <select class="sort-select" id="rankFilter" onchange="filterRank(this.value)" style="min-width:120px">
                <option value="ALL">Semua Peringkat</option>
                <?php
                $rank_steps = [1,6,11,16,21,26,31,36];
                foreach ($rank_steps as $rs):
                    $re = $rs + 4;
                    if ($rs > $total_siswa) break;
                    $re_label = min($re, $total_siswa);
                ?>
                <option value="<?= $rs ?>-<?= $re_label ?>">Peringkat <?= $rs ?>–<?= $re_label ?></option>
                <?php endforeach; ?>
            </select>
            <div class="filter-sep"></div>
            <label>Urut:</label>
            <select class="sort-select" id="sortSelect" onchange="sortTable(this.value)">
                <option value="nama_asc" selected>Nama (A→Z)</option>
                <option value="nama_desc">Nama (Z→A)</option>
                <option value="rank_asc">Peringkat (1→<?= $total_siswa ?>)</option>
                <option value="rank_desc">Peringkat (<?= $total_siswa ?>→1)</option>
                <option value="rata_desc">Rata-rata (↓)</option>
                <option value="rata_asc">Rata-rata (↑)</option>
            </select>
        </div>

        <!-- Kepala surat -->
        <div class="leger-header">
            <div class="judul">
                Leger Rapor <?= strtoupper($jenis_label) ?><?= $bulan_nama ? ' ' . strtoupper($bulan_nama) : '' ?><br>
                <?= strtoupper(htmlspecialchars($sekolah['nama_sekolah'] ?? 'NAMA SEKOLAH')) ?><br>
                Tahun Pelajaran <?= htmlspecialchars($ta['nama']) ?> Semester <?= $semester_lbl ?>
            </div>
        </div>

        <!-- Meta kelas & wali -->
        <table style="width:100%;border-collapse:collapse;margin-bottom:10px;font-size:11px">
            <tr>
                <td style="width:70px;font-weight:600;padding:2px 0">Kelas</td>
                <td style="width:8px">:</td>
                <td style="border-bottom:1px solid #333;padding:1px 6px;min-width:120px">
                    <?= htmlspecialchars($kelas['nama_kelas']) ?>
                </td>
                <td style="width:60px"></td>
                <td style="width:80px;font-weight:600;padding:2px 0">Wali Kelas</td>
                <td style="width:8px">:</td>
                <td style="border-bottom:1px solid #333;padding:1px 6px;min-width:160px">
                    <?= htmlspecialchars($kelas['wali_nama'] ?? '-') ?>
                </td>
            </tr>
            <tr>
                <td style="font-weight:600;padding:2px 0">KKTP</td>
                <td>:</td>
                <td style="border-bottom:1px solid #333;padding:1px 6px">
                    <?= (int)$krit_c ?>
                </td>
            </tr>
        </table>

        <!-- Tabel Leger -->
        <div style="overflow-x:auto">
            <table class="leger-table">
                <thead>
                    <!-- Baris 1: Grup header -->
                    <tr>
                        <th class="col-no" rowspan="2">NO</th>
                        <th class="col-nisn" rowspan="2">NISN</th>
                        <th class="col-nama" rowspan="2" style="text-align:center">NAMA MURID</th>
                        <?php if (count($mapel_list) > 0): ?>
                        <th class="th-group-pengetahuan" colspan="<?= count($mapel_list) ?>">PENGETAHUAN</th>
                        <?php endif; ?>
                        <th rowspan="2" class="col-total" style="background:#e8f5e9">Total</th>
                        <th rowspan="2" class="col-rata" style="background:#fff3e0">Rata-rata</th>
                        <th rowspan="2" class="col-rank" style="background:#e8f5e9">Peringkat</th>
                    </tr>
                    <!-- Baris 2: Nama mapel horizontal -->
                    <tr>
                        <?php foreach ($mapel_list as $idx => $m): ?>
                        <th class="th-mapel col-mapel" title="<?= htmlspecialchars($m['nama_mapel']) ?>">
                            <?= htmlspecialchars($m['nama_mapel']) ?>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                    <!-- Baris 3: Nomor kolom -->
                    <tr class="row-col-num">
                        <td>1</td>
                        <td>2</td>
                        <td>3</td>
                        <?php foreach ($mapel_list as $idx => $m): ?>
                        <td><?= $idx + 4 ?></td>
                        <?php endforeach; ?>
                        <td><?= count($mapel_list) + 4 ?></td>
                        <td><?= count($mapel_list) + 5 ?></td>
                        <td><?= count($mapel_list) + 6 ?></td>
                    </tr>
                </thead>
                <tbody id="legerTbody">
                    <?php foreach ($siswa_list as $i => $s):
            $sid         = (int)$s['id'];
            $peringkat   = $rank_map[$sid] ?? ($i + 1);
            $siswa_nilai = $nilai_map[$sid] ?? [];

            // Rata-rata hanya dari mapel yang sudah diinput (record ada di DB, nilai > 0)
            $total_val   = 0;
            $jml_mapel   = 0;
            foreach ($mapel_list as $m) {
                $has_record = array_key_exists($m['id'], $siswa_nilai);
                $v = $has_record ? $siswa_nilai[$m['id']] : null;
                if ($has_record && $v > 0) { $total_val += $v; $jml_mapel++; }
            }
            $rata  = $jml_mapel > 0 ? round($total_val / $jml_mapel, 1) : 0;
            $total = round($total_val, 1);
        ?>
                    <tr data-nama="<?= htmlspecialchars(strtoupper($s['nama_lengkap'])) ?>"
                        data-rank="<?= $peringkat ?>"
                        data-rata="<?= $rata ?>">
                        <td class="col-no"><?= $i + 1 ?></td>
                        <td class="col-nisn" style="font-size:9px"><?= htmlspecialchars($s['nisn'] ?? $s['nis']) ?></td>
                        <td class="col-nama"><?= htmlspecialchars($s['nama_lengkap']) ?></td>
                        <?php foreach ($mapel_list as $m):
                $has_record = array_key_exists($m['id'], $siswa_nilai);
                $v        = $has_record ? $siswa_nilai[$m['id']] : null;
                $v_disp   = $has_record ? (int)round((float)$v, 0) : null; // nilai setelah dibulatkan (sama dengan tampilan)
                $low      = ($has_record && $v_disp > 0 && $v_disp < $krit_c);
            ?>
                        <td class="col-mapel<?= $low ? ' val-low' : '' ?>"><?= $has_record ? $v_disp : '' ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="col-total"><?= $total > 0 ? number_format($total, 0) : '-' ?></td>
                        <td class="col-rata"><?= $rata  > 0 ? number_format($rata,  1) : '-' ?></td>
                        <td class="col-rank"><?= $peringkat ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php
                // ── Hitung rata-rata per mapel (dari semua siswa yang punya nilai > 0) ───
                $avg_per_mapel = [];          // mapel_id => avg
                $avg_total_sum = 0;           // jumlah rata-rata per siswa
                $avg_total_cnt = 0;           // jumlah siswa dengan nilai
                foreach ($mapel_list as $m) {
                    $sum = 0; $cnt = 0;
                    foreach ($siswa_list as $s) {
                        $sv = $nilai_map[(int)$s['id']][$m['id']] ?? null;
                        if ($sv !== null && $sv > 0) { $sum += $sv; $cnt++; }
                    }
                    $avg_per_mapel[$m['id']] = $cnt > 0 ? round($sum / $cnt, 1) : null;
                }
                // Rata-rata kelas = rata-rata dari semua rata-rata siswa
                foreach ($siswa_list as $s) {
                    $sv_total = 0; $sv_cnt = 0;
                    foreach ($mapel_list as $m) {
                        $sv = $nilai_map[(int)$s['id']][$m['id']] ?? null;
                        if ($sv !== null && $sv > 0) { $sv_total += $sv; $sv_cnt++; }
                    }
                    if ($sv_cnt > 0) { $avg_total_sum += round($sv_total / $sv_cnt, 1); $avg_total_cnt++; }
                }
                $avg_kelas = $avg_total_cnt > 0 ? round($avg_total_sum / $avg_total_cnt, 1) : 0;
                $avg_total_nilai = 0;
                foreach ($avg_per_mapel as $v) { if ($v !== null) $avg_total_nilai += $v; }
                ?>
                <tfoot>
                    <tr style="background:#f0fdf4;font-weight:700;border-top:2px solid #16a34a">
                        <td class="col-no" style="text-align:center;color:#15803d">—</td>
                        <td class="col-nisn"></td>
                        <td class="col-nama" style="color:#15803d;font-size:.78rem;white-space:nowrap">Rata-rata per Mapel</td>
                        <?php foreach ($mapel_list as $m):
                            $av = $avg_per_mapel[$m['id']] ?? null;
                            $low = ($av !== null && $av > 0 && $av < $krit_c);
                        ?>
                        <td class="col-mapel<?= $low ? ' val-low' : '' ?>"
                            style="font-weight:700;<?= $av !== null ? 'color:#15803d' : '' ?>">
                            <?= $av !== null ? number_format($av, 1) : '' ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="col-total" style="color:#15803d"><?= $avg_total_nilai > 0 ? number_format($avg_total_nilai, 1) : '-' ?></td>
                        <td class="col-rata" style="color:#15803d"><?= $avg_kelas > 0 ? number_format($avg_kelas, 1) : '-' ?></td>
                        <td class="col-rank"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Tanda tangan -->
        <table class="ttd-block">
            <tr>
                <!-- Kepala Sekolah — kiri -->
                <td style="width:45%">
                    <div class="ttd-label">Mengetahui,</div>
                    <div class="ttd-label">Kepala Sekolah,</div>
                    <div class="ttd-img-wrap">
                        <?php if (!empty($kepsek['tanda_tangan'])): ?>
                        <img src="<?= UPLOAD_URL . htmlspecialchars($kepsek['tanda_tangan']) ?>"
                            alt="TTD Kepala Sekolah">
                        <?php endif; ?>
                    </div>
                    <span class="ttd-name">
                        <?= htmlspecialchars($kepsek['nama_lengkap'] ?? '________________________') ?>
                    </span>
                </td>
                <!-- Spacer -->
                <td style="width:10%"></td>
                <!-- Wali Kelas — kanan -->
                <td style="width:45%">
                    <div class="ttd-label"><?= htmlspecialchars($tgl_cetak) ?></div>
                    <div class="ttd-label">Wali Kelas,</div>
                    <div class="ttd-img-wrap">
                        <?php if (!empty($kelas['wali_ttd'])): ?>
                        <img src="<?= UPLOAD_URL . htmlspecialchars($kelas['wali_ttd']) ?>" alt="TTD Wali Kelas">
                        <?php endif; ?>
                    </div>
                    <span class="ttd-name">
                        <?= htmlspecialchars($kelas['wali_nama'] ?? '________________________') ?>
                    </span>
                </td>
            </tr>
        </table>

    </div><!-- /.leger-paper -->

    <script>
    // ── PHP data ke JS ────────────────────────────────────────────
    var _LEGER = {
        kelas   : <?= json_encode($kelas['nama_kelas']) ?>,
        wali    : <?= json_encode($kelas['wali_nama'] ?? '-') ?>,
        kktp    : <?= json_encode((int)$krit_c) ?>,
        ta      : <?= json_encode($ta['nama']) ?>,
        jenis   : <?= json_encode($jenis_label) ?>,
        bulan   : <?= json_encode($bulan_nama) ?>,
        krit_c  : <?= json_encode((float)$krit_c) ?>,
        krit_b  : <?= json_encode((float)$krit_b) ?>,
        krit_a  : <?= json_encode((float)$krit_a) ?>,
        mapel   : <?= json_encode(array_values(array_map(function($m){ return $m['nama_mapel']; }, $mapel_list))) ?>,
        semester: <?= json_encode($semester_lbl) ?>,
        sekolah : <?= json_encode($sekolah['nama_sekolah'] ?? '') ?>
    };

    // ── Fungsi inti: tampilkan baris sesuai filter aktif ─────────
    function applyFilters() {
        var abjadBtn = document.querySelector('.btn-abjad.active');
        var abjad    = abjadBtn ? abjadBtn.dataset.abjad : 'ALL';
        var rankSel  = document.getElementById('rankFilter');
        var rankVal  = rankSel ? rankSel.value : 'ALL';

        var rankMin = 0, rankMax = 99999;
        if (rankVal !== 'ALL') {
            var parts = rankVal.split('-');
            rankMin = parseInt(parts[0]); rankMax = parseInt(parts[1]);
        }

        var tbody = document.getElementById('legerTbody');
        tbody.querySelectorAll('tr').forEach(function(row) {
            var nama     = row.dataset.nama || '';
            var rank     = parseInt(row.dataset.rank) || 0;
            var showAbjad = (abjad === 'ALL') || nama.startsWith(abjad);
            var showRank  = (rankVal === 'ALL') || (rank >= rankMin && rank <= rankMax);
            row.style.display = (showAbjad && showRank) ? '' : 'none';
        });
        renumberVisible();
    }

    // ── Filter Abjad ──────────────────────────────────────────────
    function filterAbjad(btn) {
        document.querySelectorAll('.btn-abjad').forEach(function(b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');
        applyFilters();
    }

    // ── Filter Peringkat ──────────────────────────────────────────
    function filterRank(val) {
        applyFilters();
    }

    // ── Sort Tabel ────────────────────────────────────────────────
    function sortTable(mode) {
        var tbody = document.getElementById('legerTbody');
        var rows  = Array.from(tbody.querySelectorAll('tr'));

        rows.sort(function(a, b) {
            if (mode === 'rank_asc')   return parseInt(a.dataset.rank)  - parseInt(b.dataset.rank);
            if (mode === 'rank_desc')  return parseInt(b.dataset.rank)  - parseInt(a.dataset.rank);
            if (mode === 'nama_asc')   return (a.dataset.nama||'').localeCompare(b.dataset.nama||'');
            if (mode === 'nama_desc')  return (b.dataset.nama||'').localeCompare(a.dataset.nama||'');
            if (mode === 'rata_desc')  return parseFloat(b.dataset.rata) - parseFloat(a.dataset.rata);
            if (mode === 'rata_asc')   return parseFloat(a.dataset.rata) - parseFloat(b.dataset.rata);
            return 0;
        });

        rows.forEach(function(r) { tbody.appendChild(r); });
        renumberVisible();
    }

    // ── Renumber kolom NO (hanya baris visible) ───────────────────
    function renumberVisible() {
        var tbody = document.getElementById('legerTbody');
        var no = 1;
        tbody.querySelectorAll('tr').forEach(function(row) {
            if (row.style.display !== 'none') {
                row.querySelector('.col-no').textContent = no++;
            }
        });
    }

    // ── Export XLSX via PHP endpoint (PhpSpreadsheet server-side) ─
    function doExportXlsx(e) {
        e.preventDefault();
        // Ambil sort & abjad aktif saat ini
        var sortVal  = document.getElementById('sortSelect').value;
        var abjadBtn = document.querySelector('.btn-abjad.active');
        var abjadVal = abjadBtn ? abjadBtn.dataset.abjad : 'ALL';

        var base = <?= json_encode(page_url('raport.leger_export', ['ta'=>$ta_id,'kelas'=>$kelas_id,'jenis'=>$jenis,'bulan'=>$bulan])) ?>;
        var url  = base + '&sort=' + encodeURIComponent(sortVal) + '&abjad=' + encodeURIComponent(abjadVal);
        window.location.href = url;
    }

    // ── Zoom Print ────────────────────────────────────────────────
    (function() {
        var paper = document.querySelector('.leger-paper');
        if (!paper) return;

        var wrap = document.createElement('div');
        wrap.id = 'leger-zoom-wrap';
        paper.parentNode.insertBefore(wrap, paper);
        wrap.appendChild(paper);

        var PX_PER_MM = 96 / 25.4;
        var printW = 285 * PX_PER_MM;
        var printH = 198 * PX_PER_MM;

        var styleEl = document.getElementById('leger-zoom-style');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'leger-zoom-style';
            document.head.appendChild(styleEl);
        }

        function applyZoom() {
            wrap.style.zoom = '';
            paper.style.width = '';
            var cw = paper.scrollWidth;
            var ch = paper.scrollHeight;
            if (!cw || !ch) return;
            var zoom = Math.min(printW / cw, printH / ch, 1);
            styleEl.textContent = '@media print { #leger-zoom-wrap { zoom: ' + zoom + '; } }';
        }

        function resetZoom() { styleEl.textContent = ''; }

        window.addEventListener('beforeprint', applyZoom);
        window.addEventListener('afterprint', resetZoom);
        if (window.matchMedia) {
            window.matchMedia('print').addEventListener('change', function(e) {
                if (e.matches) applyZoom(); else resetZoom();
            });
        }
    })();
    </script>

<?php
// ── Chatbot widget (inject langsung karena halaman ini standalone/tanpa layout) ──
include __DIR__ . '/../../pages/chatbot/widget.php';
?>

</body>

</html>