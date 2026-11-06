<?php
/**
 * pages/raport/leger.php
 * Leger Raport — tabel rekap semua siswa × semua mapel dalam satu kelas.
 * Akses: super_admin, admin, kepala_sekolah, wali_kelas (kelasnya sendiri saja).
 * Guru biasa dan siswa: ditolak dengan redirect (peringatan ditampilkan sebagai
 * modal overlay di halaman raport/index.php saat tombol diklik).
 */
require_once __DIR__ . '/../../auth.php';
require_login();

$user_ses = current_user();
$role     = $user_ses['role'];

// Guard server-side: redirect jika role tidak diizinkan
if (!in_array($role, ['super_admin', 'admin', 'kepala_sekolah', 'wali_kelas'])) {
    header('Location: ' . BASE_URL . '/index.php?page=' . ($role === 'siswa' ? 'raport' : 'dashboard'));
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

// Verifikasi akses lebih lanjut: wali_kelas hanya bisa akses kelasnya sendiri
if ($role === 'wali_kelas') {
    $kelas_check = db_row(
        'SELECT id FROM kelas WHERE id = :kid AND wali_kelas_id = :uid',
        [':kid' => $kelas_id, ':uid' => $user_ses['id']]
    );
    if (!$kelas_check) {
        header('Location: ' . BASE_URL . '/index.php?page=raport');
        exit;
    }
}

// Data kelas & TA (ambil lengkap termasuk tanda_tangan wali kelas untuk TTD)
$kelas = db_row('SELECT k.*, u.nama_lengkap as wali_nama, u.tanda_tangan as wali_ttd FROM kelas k LEFT JOIN users u ON u.id = k.wali_kelas_id WHERE k.id = :id', [':id' => $kelas_id]);
$ta    = db_row('SELECT * FROM tahun_ajaran WHERE id = :id', [':id' => $ta_id]);

if (!$kelas || !$ta) {
    http_response_code(404); echo 'Kelas atau Tahun Ajaran tidak ditemukan.'; exit;
}

$sekolah      = sekolah_info();
$jenis_label  = ['bulanan'=>'Bulanan','sts'=>'STS','semester'=>'Semester'][$jenis] ?? ucfirst($jenis);
$bulan_nama   = (in_array($jenis, ['bulanan','sts']) && $bulan) ? (BULAN_ID[$bulan] ?? '') : '';
$semester_lbl = ($ta['semester'] ?? 1) == 1 ? 'Ganjil' : 'Genap';

// ── Daftar mapel yang ada nilainya di kelas ini ───────────────
// Urutan: kelompok → urutan parent (jika sub-mapel) → urutan mapel → nama_mapel
// Identik dengan logika raport_template.php
$bulan_cond_m  = '';
$mapel_params  = [':kid' => $kelas_id, ':ta' => $ta_id, ':jenis' => $jenis];
if (in_array($jenis, ['bulanan','sts']) && $bulan) {
    $bulan_cond_m = 'AND n.bulan = :bulan';
    $mapel_params[':bulan'] = $bulan;
}
$mapel_raw = db_query(
    "SELECT DISTINCT m.id, m.nama_mapel, m.urutan, m.kelompok, m.parent_id,
            p.nama_mapel as parent_nama, p.kelompok as parent_kelompok,
            p.urutan as parent_urutan
     FROM nilai n
     INNER JOIN mapel m ON m.id = n.mapel_id
     LEFT JOIN mapel p ON p.id = m.parent_id
     WHERE n.kelas_id = :kid
       AND n.tahun_ajaran_id = :ta
       AND n.jenis_raport = :jenis
       $bulan_cond_m
     ORDER BY m.kelompok, m.urutan, m.nama_mapel",
    $mapel_params
);

// Susun ulang sesuai logika raport: kelompok → urutan (parent jika sub-mapel) → urutan sub
$_singles            = [];
$_children_by_parent = [];
$_parent_meta        = [];

foreach ($mapel_raw as $mi) {
    if ($mi['parent_id'] !== null) {
        $pid = (int)$mi['parent_id'];
        $_children_by_parent[$pid][] = $mi;
        if (!isset($_parent_meta[$pid])) {
            $_parent_meta[$pid] = [
                'kelompok' => $mi['parent_kelompok'] ?? $mi['kelompok'],
                'urutan'   => (int)($mi['parent_urutan'] ?? 0),
                'nama'     => $mi['parent_nama'] ?? '',
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

// ── Daftar siswa diurutkan berdasarkan rata-rata (untuk peringkat) ──
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
     ORDER BY avg_nilai DESC",
    $siswa_params
);

if (empty($mapel_list)) {
    http_response_code(404);
    echo 'Tidak ada nilai untuk filter yang dipilih.'; exit;
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
$nilai_map = [];
foreach ($all_nilai_rows as $r) {
    $nilai_map[$r['siswa_user_id']][$r['mapel_id']] = (float)$r['nilai_akhir'];
}

// Hitung peringkat (posisi dalam array sudah urut rata-rata DESC)
$total_siswa = count($siswa_list);
$krit_a = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c = (float)($sekolah['kriteria_c_min'] ?? 76);

// Kepala sekolah beserta tanda tangan
$kepsek = db_row('SELECT nama_lengkap, tanda_tangan FROM users WHERE role = "kepala_sekolah" LIMIT 1');

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
    <title>Leger Raport <?= htmlspecialchars($kelas['nama_kelas']) ?> | <?= htmlspecialchars($ta['nama']) ?>
    </title>
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
    }
    </style>
</head>

<body>

    <!-- ── Toolbar ── -->
    <div class="leger-toolbar">
        <div>
            <div class="tb-title">Leger Raport | <?= htmlspecialchars($kelas['nama_kelas']) ?></div>
            <div class="tb-meta">
                <?= htmlspecialchars($ta['nama']) ?> &middot; Sem. <?= $semester_lbl ?>
                &middot; <?= $jenis_label ?><?= $bulan_nama ? " &mdash; $bulan_nama" : '' ?>
                &middot; <strong><?= $total_siswa ?> siswa</strong>
                &middot; <strong><?= count($mapel_list) ?> mapel</strong>
            </div>
        </div>
        <div class="tb-btns">
            <a href="<?= BASE_URL ?>/index.php?page=raport&ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>&jenis=<?= urlencode($jenis) ?>&bulan=<?= $bulan ?>"
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
        </div>
    </div>

    <!-- ── Kertas Leger ── -->
    <div class="leger-paper">

        <!-- Kepala surat -->
        <div class="leger-header">
            <div class="judul">
                Leger Raport <?= strtoupper($jenis_label) ?><?= $bulan_nama ? ' ' . strtoupper($bulan_nama) : '' ?><br>
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
                        <th class="col-nama" rowspan="2" style="text-align:center">NAMA SISWA</th>
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
                <tbody>
                    <?php foreach ($siswa_list as $i => $s):
            $peringkat   = $i + 1;
            $sid         = (int)$s['id'];
            $siswa_nilai = $nilai_map[$sid] ?? [];

            // Hitung total & rata-rata dari nilai yang ada di mapel_list
            $total_val   = 0;
            $jml_mapel   = 0;
            foreach ($mapel_list as $m) {
                $v = $siswa_nilai[$m['id']] ?? 0;
                if ($v > 0) { $total_val += $v; $jml_mapel++; }
            }
            $rata  = $jml_mapel > 0 ? round($total_val / $jml_mapel, 1) : 0;
            $total = round($total_val, 1);
        ?>
                    <tr>
                        <td class="col-no"><?= $peringkat ?></td>
                        <td class="col-nisn" style="font-size:9px"><?= htmlspecialchars($s['nisn'] ?? $s['nis']) ?></td>
                        <td class="col-nama"><?= htmlspecialchars($s['nama_lengkap']) ?></td>
                        <?php foreach ($mapel_list as $m):
                $v   = $siswa_nilai[$m['id']] ?? 0;
                $low = ($v > 0 && $v < $krit_c);
            ?>
                        <td class="col-mapel<?= $low ? ' val-low' : '' ?>"><?= $v > 0 ? number_format($v, 0) : '' ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="col-total"><?= $total > 0 ? number_format($total, 0) : '-' ?></td>
                        <td class="col-rata"><?= $rata  > 0 ? number_format($rata,  1) : '-' ?></td>
                        <td class="col-rank"><?= $peringkat ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
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
    (function() {
        var paper = document.querySelector('.leger-paper');
        if (!paper) return;

        // Bungkus paper dalam div#leger-zoom-wrap
        var wrap = document.createElement('div');
        wrap.id = 'leger-zoom-wrap';
        paper.parentNode.insertBefore(wrap, paper);
        wrap.appendChild(paper);

        // Ukuran kertas A4 landscape - margin @page 6mm tiap sisi
        // area cetak: (297-12)mm × (210-12)mm = 285 × 198 mm
        var DPR = window.devicePixelRatio || 1;
        var PX_PER_MM = 96 / 25.4; // 1mm = ~3.78px pada 96dpi
        var printW = 285 * PX_PER_MM; // px area cetak lebar
        var printH = 198 * PX_PER_MM; // px area cetak tinggi

        var styleEl = document.getElementById('leger-zoom-style');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'leger-zoom-style';
            document.head.appendChild(styleEl);
        }

        function applyZoom() {
            // Reset zoom dulu agar scrollWidth/Height terukur asli
            wrap.style.zoom = '';
            paper.style.width = '';

            var cw = paper.scrollWidth;
            var ch = paper.scrollHeight;
            if (!cw || !ch) return;

            var zoomW = printW / cw;
            var zoomH = printH / ch;
            var zoom = Math.min(zoomW, zoomH, 1); // tidak pernah > 1

            // Inject ke <style> khusus print — zoom bekerja di print engine
            styleEl.textContent =
                '@media print { #leger-zoom-wrap { zoom: ' + zoom + '; } }';
        }

        function resetZoom() {
            styleEl.textContent = '';
        }

        window.addEventListener('beforeprint', applyZoom);
        window.addEventListener('afterprint', resetZoom);

        // matchMedia fallback (Chrome)
        if (window.matchMedia) {
            window.matchMedia('print').addEventListener('change', function(e) {
                if (e.matches) applyZoom();
                else resetZoom();
            });
        }
    })();
    </script>

</body>

</html>