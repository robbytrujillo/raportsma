<?php
/**
 * pages/nilai/bulk.php
 * Input nilai bulk â€" semua siswa dalam 1 kelas Ã&mdash; 1 mapel sekaligus
 * Tampilan seperti format Excel: header info + tabel siswa + tabel deskripsi kriteria
 */
require_role(['admin', 'super_admin', 'guru', 'wali_kelas']);

$user         = current_user();
$role         = $user['role'];
$ta_aktif     = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$guru_detail  = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user['id']]);
$guru_status  = $guru_detail['status'] ?? 'guru';

// Kelas wali
$wali_kelas_ids = [];
if ($guru_status === 'wali_kelas') {
    $wk = db_query('SELECT id FROM kelas WHERE wali_kelas_id = :uid', [':uid' => $user['id']]);
    $wali_kelas_ids = array_column($wk, 'id');
}

$ta_id     = (int)($_GET['ta']    ?? $ta_aktif['id'] ?? 0);
$kelas_id  = (int)($_GET['kelas'] ?? 0);
$mapel_id  = (int)($_GET['mapel'] ?? 0);
$jenis     = $_GET['jenis'] ?? 'bulanan';
$bulan     = (int)($_GET['bulan'] ?? 0);

/* â"€â"€ Deskripsi default per kriteria (CRUD) â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€
   Disimpan di tabel nilai_deskripsi_kriteria (dibuat runtime jika belum ada)
   Struktur: mapel_id, kelas_id, tahun_ajaran_id, jenis_raport, bulan, kriteria, deskripsi
*/
// Buat tabel jika belum ada (one-time)
db()->exec("CREATE TABLE IF NOT EXISTS nilai_deskripsi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mapel_id INT NOT NULL,
    kelas_id INT NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    jenis_raport ENUM('bulanan','sts','semester') NOT NULL DEFAULT 'bulanan',
    bulan TINYINT DEFAULT NULL,
    kriteria CHAR(1) NOT NULL,
    deskripsi TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dk (mapel_id, kelas_id, tahun_ajaran_id, jenis_raport, bulan, kriteria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* â"€â"€ Handle POST: simpan nilai bulk + deskripsi â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $p_ta    = (int)$_POST['tahun_ajaran_id'];
    $p_kelas = (int)$_POST['kelas_id'];
    $p_mapel = (int)$_POST['mapel_id'];
    $p_jenis = $_POST['jenis_raport'] ?? 'bulanan';
    $p_bulan = ($p_jenis !== 'semester') ? ((int)$_POST['bulan'] ?: null) : null;

    // ── VALIDASI OTORISASI: pastikan guru ini ditugaskan di mapel+kelas tersebut ──
    if (!in_array($role, ['admin', 'super_admin'])) {
        $penugasan_valid = false;
        if ($guru_status === 'wali_kelas' && in_array($p_kelas, $wali_kelas_ids)) {
            // Wali kelas: boleh input nilai mapel apa saja selama mapel itu terdaftar di kelasnya
            $penugasan_valid = (bool)db_row(
                'SELECT id FROM guru_mapel_kelas
                 WHERE mapel_id=:m AND kelas_id=:k AND tahun_ajaran_id=:ta LIMIT 1',
                [':m'=>$p_mapel,':k'=>$p_kelas,':ta'=>$p_ta]
            );
        } else {
            // Guru biasa: hanya boleh input mapel yang ditugaskan ke dirinya di kelas tersebut
            $penugasan_valid = (bool)db_row(
                'SELECT id FROM guru_mapel_kelas
                 WHERE guru_user_id=:uid AND mapel_id=:m AND kelas_id=:k AND tahun_ajaran_id=:ta LIMIT 1',
                [':uid'=>$user['id'],':m'=>$p_mapel,':k'=>$p_kelas,':ta'=>$p_ta]
            );
        }
        if (!$penugasan_valid) {
            flash('error', 'Tidak dapat menyimpan: Anda tidak memiliki penugasan untuk mata pelajaran ini di kelas tersebut. Pilih mata pelajaran yang sesuai dengan kelas yang dipilih.');
            header("Location: /nilai.bulk?ta=$p_ta&kelas=$p_kelas&mapel=0&jenis=$p_jenis");
            exit;
        }
    }

    $sekolah = sekolah_info();
    $krit_a  = (float)($sekolah['kriteria_a_min'] ?? 92);
    $krit_b  = (float)($sekolah['kriteria_b_min'] ?? 83);
    $krit_c  = (float)($sekolah['kriteria_c_min'] ?? 76);

    // 1. Simpan deskripsi per kriteria
    foreach (['A','B','C','D'] as $kr) {
        $desk = trim($_POST['desk_' . $kr] ?? '');
        if ($desk !== '') {
            db_execute(
                'INSERT INTO nilai_deskripsi
                    (mapel_id, kelas_id, tahun_ajaran_id, jenis_raport, bulan, kriteria, deskripsi)
                 VALUES (:m,:k,:ta,:j,:b,:kr,:d)
                 ON DUPLICATE KEY UPDATE deskripsi = VALUES(deskripsi)',
                [':m'=>$p_mapel,':k'=>$p_kelas,':ta'=>$p_ta,
                 ':j'=>$p_jenis,':b'=>$p_bulan,':kr'=>$kr,':d'=>$desk]
            );
        }
    }

    // 2. Simpan nilai per siswa
    $siswa_ids = $_POST['siswa_ids'] ?? [];
    foreach ($siswa_ids as $sid) {
        $sid = (int)$sid;

        $h1   = (float)($_POST["h1_$sid"]    ?? 0);
        $h2   = (float)($_POST["h2_$sid"]    ?? 0);
        $h3   = (float)($_POST["h3_$sid"]    ?? 0);
        $tug  = (float)($_POST["tugas_$sid"] ?? 0);
        $sts       = (float)($_POST["sts_$sid"]        ?? 0);
        $kumu_raw  = $_POST["kumulatif_$sid"] ?? '';  // raw string untuk cek apakah benar-benar dikosongkan
        $kumu      = $kumu_raw !== '' ? (float)$kumu_raw : null; // null = belum diisi
        $sas       = (float)($_POST["sas_$sid"]        ?? 0);
        $desk_siswa = trim($_POST["deskripsi_$sid"] ?? '');
        $catatan    = trim($_POST["catatan_$sid"]   ?? '');

        // Hitung nilai akhir
        if ($p_jenis === 'semester') {
            $na = round($sas, 2);
        } elseif ($p_jenis === 'sts') {
            // nilai_akhir = kumulatif jika guru mengisinya (termasuk jika diisi 0)
            // fallback ke sts hanya jika field kumulatif benar-benar kosong/tidak dikirim
            $na = round($kumu !== null ? $kumu : $sts, 2);
        } else {
            // Gunakan hanya kolom yang diaktifkan guru (checkbox)
            $aktif_nh1   = (int)($_POST['aktif_nh1']   ?? 1);
            $aktif_nh2   = (int)($_POST['aktif_nh2']   ?? 1);
            $aktif_nh3   = (int)($_POST['aktif_nh3']   ?? 1);
            $aktif_tugas = (int)($_POST['aktif_tugas'] ?? 1);

            // Kumpulkan nilai dari kolom aktif (nilai 0 tetap dihitung; dibagi jumlah kolom aktif)
            $komponen_aktif = [];
            if ($aktif_nh1)   $komponen_aktif[] = $h1;
            if ($aktif_nh2)   $komponen_aktif[] = $h2;
            if ($aktif_nh3)   $komponen_aktif[] = $h3;
            if ($aktif_tugas) $komponen_aktif[] = $tug;

            $na = count($komponen_aktif) > 0
                ? round(array_sum($komponen_aktif) / count($komponen_aktif), 2)
                : 0;
        }

        // Predikat berdasarkan kriteria sekolah
        if ($na >= $krit_a)      $pred = 'A';
        elseif ($na >= $krit_b)  $pred = 'B';
        elseif ($na >= $krit_c)  $pred = 'C';
        else                     $pred = 'D';

        // Ambil semua deskripsi kriteria untuk mapel ini
        $all_desk = [];
        $dkRows = db_query(
            'SELECT kriteria, deskripsi FROM nilai_deskripsi
             WHERE mapel_id=:m AND kelas_id=:k AND tahun_ajaran_id=:ta
               AND jenis_raport=:j AND (bulan=:b OR bulan IS NULL)',
            [':m'=>$p_mapel,':k'=>$p_kelas,':ta'=>$p_ta,
             ':j'=>$p_jenis,':b'=>$p_bulan]
        );
        foreach ($dkRows as $dkr) {
            $all_desk[$dkr['kriteria']] = $dkr['deskripsi'];
        }

        // Auto-isi deskripsi: jika kosong ATAU jika isinya adalah deskripsi
        // kriteria lain (stale â€" predikat siswa berubah), ganti dengan yang benar
        $deskripsi_kriteria_lain = array_filter($all_desk, fn($k) => $k !== $pred, ARRAY_FILTER_USE_KEY);
        $is_stale = $desk_siswa !== '' && in_array($desk_siswa, array_values($deskripsi_kriteria_lain), true);
        if ($desk_siswa === '' || $is_stale) {
            $desk_siswa = $all_desk[$pred] ?? '';
        }

        db_execute(
            'INSERT INTO nilai
                (siswa_user_id, mapel_id, kelas_id, tahun_ajaran_id, guru_user_id,
                 jenis_raport, bulan,
                 nilai_harian_1, nilai_harian_2, nilai_harian_3,
                 nilai_tugas, nilai_sts, nilai_kumulatif, nilai_sas,
                 nilai_akhir, predikat, deskripsi, catatan)
             VALUES
                (:sid,:m,:k,:ta,:guru,:j,:b,:h1,:h2,:h3,:tug,:sts,:kumu,:sas,:na,:pred,:desk,:cat)
             ON DUPLICATE KEY UPDATE
                guru_user_id    = VALUES(guru_user_id),
                nilai_harian_1  = VALUES(nilai_harian_1),
                nilai_harian_2  = VALUES(nilai_harian_2),
                nilai_harian_3  = VALUES(nilai_harian_3),
                nilai_tugas     = VALUES(nilai_tugas),
                nilai_sts       = VALUES(nilai_sts),
                nilai_kumulatif = VALUES(nilai_kumulatif),
                nilai_sas       = VALUES(nilai_sas),
                nilai_akhir     = VALUES(nilai_akhir),
                predikat        = VALUES(predikat),
                deskripsi       = VALUES(deskripsi),
                catatan         = VALUES(catatan)',
            [':sid'=>$sid, ':m'=>$p_mapel, ':k'=>$p_kelas, ':ta'=>$p_ta,
             ':guru'=>$user['id'], ':j'=>$p_jenis, ':b'=>$p_bulan,
             ':h1'=>$h1,':h2'=>$h2,':h3'=>$h3,':tug'=>$tug,
             ':sts'=>$sts,':kumu'=>$kumu,':sas'=>$sas,
             ':na'=>$na, ':pred'=>$pred, ':desk'=>$desk_siswa, ':cat'=>$catatan]
        );
    }

    // 3. Simpan state kolom aktif ke DB (hanya untuk jenis bulanan)
    if ($p_jenis === 'bulanan') {
        $aktif_nh1   = (int)($_POST['aktif_nh1']   ?? 1);
        $aktif_nh2   = (int)($_POST['aktif_nh2']   ?? 1);
        $aktif_nh3   = (int)($_POST['aktif_nh3']   ?? 1);
        $aktif_tugas = (int)($_POST['aktif_tugas'] ?? 1);
        db_execute(
            'INSERT INTO nilai_kolom_aktif
                (kelas_id, mapel_id, tahun_ajaran_id, jenis_raport, bulan,
                 aktif_nh1, aktif_nh2, aktif_nh3, aktif_tugas, updated_by)
             VALUES (:k,:m,:ta,:j,:b,:n1,:n2,:n3,:tg,:uid)
             ON DUPLICATE KEY UPDATE
                aktif_nh1=VALUES(aktif_nh1), aktif_nh2=VALUES(aktif_nh2),
                aktif_nh3=VALUES(aktif_nh3), aktif_tugas=VALUES(aktif_tugas),
                updated_by=VALUES(updated_by)',
            [':k'=>$p_kelas,':m'=>$p_mapel,':ta'=>$p_ta,':j'=>$p_jenis,
             ':b'=>$p_bulan,':n1'=>$aktif_nh1,':n2'=>$aktif_nh2,
             ':n3'=>$aktif_nh3,':tg'=>$aktif_tugas,':uid'=>$user['id']]
        );
    }

    flash('success', 'Nilai berhasil disimpan.');
    header("Location: /nilai.bulk?ta=$p_ta&kelas=$p_kelas&mapel=$p_mapel&jenis=$p_jenis&bulan=$p_bulan");
    exit;
}

/* â"€â"€ Data pendukung â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€*/
$ta_list   = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$sekolah   = sekolah_info();
$krit_a    = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b    = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c    = (float)($sekolah['kriteria_c_min'] ?? 76);

// Kelas sesuai hak akses
$_unit_f   = get_user_unit_filter($user);
$_unit_sql = $_unit_f['sql'];
if (in_array($role, ['admin','super_admin','kepala_sekolah'])) {
    $kelas_list = db_query("SELECT * FROM kelas k WHERE k.tahun_ajaran_id=:ta {$_unit_sql} ORDER BY k.nama_kelas", [':ta'=>$ta_id]);
} elseif ($guru_status === 'wali_kelas') {
    // :uid2 = nilai sama dengan :uid â€" PDO tidak boleh pakai named param duplikat
    $kelas_list = db_query(
        "SELECT DISTINCT k.* FROM kelas k
         LEFT JOIN guru_mapel_kelas gmk ON gmk.kelas_id=k.id AND gmk.guru_user_id=:uid
         WHERE k.tahun_ajaran_id=:ta AND (k.wali_kelas_id=:uid2 OR gmk.id IS NOT NULL) {$_unit_sql}
         ORDER BY k.nama_kelas",
        [':uid'=>$user['id'],':uid2'=>$user['id'],':ta'=>$ta_id]
    );
} else {
    $kelas_list = db_query(
        "SELECT DISTINCT k.* FROM kelas k
         INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id=k.id
         WHERE gmk.guru_user_id=:uid AND gmk.tahun_ajaran_id=:ta {$_unit_sql} ORDER BY k.nama_kelas",
        [':uid'=>$user['id'],':ta'=>$ta_id]
    );
}

// Mapel sesuai kelas & hak akses
$mapel_list = [];
if ($kelas_id) {
    if (in_array($role, ['admin','super_admin'])) {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':kid'=>$kelas_id]
        );
    } elseif ($guru_status === 'wali_kelas' && in_array($kelas_id, $wali_kelas_ids)) {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':kid'=>$kelas_id]
        );
    } else {
        $mapel_list = db_query(
            'SELECT DISTINCT m.* FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.guru_user_id=:uid AND gmk.kelas_id=:kid ORDER BY m.urutan, m.nama_mapel',
            [':uid'=>$user['id'],':kid'=>$kelas_id]
        );
    }
}

// ── Guard: reset mapel_id jika tidak ada dalam mapel_list kelas ini ──
// Ini mencegah tampil & simpan nilai dengan mapel sisa kelas lain di URL
if ($mapel_id && !empty($mapel_list)) {
    $valid_mapel_ids = array_column($mapel_list, 'id');
    if (!in_array($mapel_id, $valid_mapel_ids)) {
        $mapel_id = 0; // reset — paksa guru pilih mapel yang sesuai kelas ini
    }
}

// Info kelas & mapel terpilih
$kelas_info = $kelas_id ? db_row('SELECT * FROM kelas WHERE id=:id', [':id'=>$kelas_id]) : null;
$mapel_info = $mapel_id ? db_row('SELECT * FROM mapel WHERE id=:id', [':id'=>$mapel_id]) : null;

// Nama guru pengampu mapel ini di kelas ini
$guru_pengampu = null;
if ($kelas_id && $mapel_id) {
    $guru_pengampu = db_row(
        'SELECT u.nama_lengkap FROM users u
         INNER JOIN guru_mapel_kelas gmk ON gmk.guru_user_id=u.id
         WHERE gmk.kelas_id=:kid AND gmk.mapel_id=:mid AND gmk.tahun_ajaran_id=:ta LIMIT 1',
        [':kid'=>$kelas_id,':mid'=>$mapel_id,':ta'=>$ta_id]
    );
}

// Daftar siswa di kelas
$siswa_list = [];
if ($kelas_id) {
    $siswa_list = db_query(
        'SELECT u.id, u.nama_lengkap, s.nis
         FROM users u INNER JOIN siswa s ON s.user_id=u.id
         WHERE s.kelas_id=:kid ORDER BY u.nama_lengkap',
        [':kid'=>$kelas_id]
    );
}

// Nilai yang sudah ada
$existing_nilai = [];
if ($kelas_id && $mapel_id) {
    $rows = db_query(
        'SELECT * FROM nilai
         WHERE kelas_id=:k AND mapel_id=:m AND tahun_ajaran_id=:ta
           AND jenis_raport=:j AND (bulan=:b OR (:b2 IS NULL AND bulan IS NULL))',
        [':k'=>$kelas_id,':m'=>$mapel_id,':ta'=>$ta_id,
         ':j'=>$jenis,':b'=>$bulan?:null,':b2'=>$bulan?:null]
    );
    foreach ($rows as $r) $existing_nilai[$r['siswa_user_id']] = $r;
}

// Deskripsi kriteria yang sudah tersimpan
$deskripsi_kriteria = [];
if ($kelas_id && $mapel_id) {
    $drows = db_query(
        'SELECT * FROM nilai_deskripsi
         WHERE mapel_id=:m AND kelas_id=:k AND tahun_ajaran_id=:ta
           AND jenis_raport=:j AND (bulan=:b OR (:b2 IS NULL AND bulan IS NULL))',
        [':m'=>$mapel_id,':k'=>$kelas_id,':ta'=>$ta_id,
         ':j'=>$jenis,':b'=>$bulan?:null,':b2'=>$bulan?:null]
    );
    foreach ($drows as $d) $deskripsi_kriteria[$d['kriteria']] = $d['deskripsi'];
}

// Baca state kolom aktif dari DB (jika pernah disimpan guru)
$kolom_aktif_db = null;
if ($kelas_id && $mapel_id && $jenis === 'bulanan') {
    $kolom_aktif_db = db_row(
        'SELECT * FROM nilai_kolom_aktif
         WHERE kelas_id=:k AND mapel_id=:m AND tahun_ajaran_id=:ta
           AND jenis_raport=:j AND (bulan=:b OR (:b2 IS NULL AND bulan IS NULL))',
        [':k'=>$kelas_id,':m'=>$mapel_id,':ta'=>$ta_id,
         ':j'=>$jenis,':b'=>$bulan?:null,':b2'=>$bulan?:null]
    );
}

if ($kolom_aktif_db) {
    // State tersimpan eksplisit di DB → pakai langsung
    $db_aktif_nh1   = (int)$kolom_aktif_db['aktif_nh1'];
    $db_aktif_nh2   = (int)$kolom_aktif_db['aktif_nh2'];
    $db_aktif_nh3   = (int)$kolom_aktif_db['aktif_nh3'];
    $db_aktif_tugas = (int)$kolom_aktif_db['aktif_tugas'];
} elseif (!empty($existing_nilai)) {
    // Belum ada di nilai_kolom_aktif (input dari versi lama) →
    // Infer dari data nilai yang sudah tersimpan:
    // kolom aktif = kolom yang nilai rata-ratanya > 0 di antara siswa yang sudah diinput.
    // Jika SEMUA siswa bernilai 0 di suatu kolom, kolom itu dianggap tidak aktif.
    $sum_nh1 = $sum_nh2 = $sum_nh3 = $sum_tug = 0;
    foreach ($existing_nilai as $nv) {
        $sum_nh1 += (float)($nv['nilai_harian_1'] ?? 0);
        $sum_nh2 += (float)($nv['nilai_harian_2'] ?? 0);
        $sum_nh3 += (float)($nv['nilai_harian_3'] ?? 0);
        $sum_tug += (float)($nv['nilai_tugas']     ?? 0);
    }
    $db_aktif_nh1   = $sum_nh1 > 0 ? 1 : 0;
    $db_aktif_nh2   = $sum_nh2 > 0 ? 1 : 0;
    $db_aktif_nh3   = $sum_nh3 > 0 ? 1 : 0;
    $db_aktif_tugas = $sum_tug > 0 ? 1 : 0;
    // Jika semua nol (belum ada nilai sama sekali), aktifkan semua sebagai default
    if (!$db_aktif_nh1 && !$db_aktif_nh2 && !$db_aktif_nh3 && !$db_aktif_tugas) {
        $db_aktif_nh1 = $db_aktif_nh2 = $db_aktif_nh3 = $db_aktif_tugas = 1;
    }
} else {
    // Belum ada nilai sama sekali → default semua aktif
    $db_aktif_nh1 = $db_aktif_nh2 = $db_aktif_nh3 = $db_aktif_tugas = 1;
}

$bulan_opts = BULAN_ID;
$jenis_opts = [
    'bulanan'  => 'Bulanan (NH + Tugas)',
    'sts'      => 'ASTS / Asesmen Sumatif Tengah Semester',
    'semester' => 'Semester / SAS',
];
$ready = $kelas_id && $mapel_id && $ta_id;
?>

<div class="page-content">

    <!-- â"€â"€ PAGE HEADER â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Input Nilai Kelas</h1>
            <p class="page-subtitle">Input nilai seluruh murid dalam satu kelas sekaligus</p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <?php if ($ready): ?>
            <a id="linkExportExcel"
                href="/nilai.import?export_nilai=1&f_ta=<?= $ta_id ?>&f_kelas=<?= $kelas_id ?>&f_mapel=<?= $mapel_id ?>&f_jenis=<?= e($jenis) ?>&f_bulan=<?= $bulan ?>"
                class="btn btn-secondary" style="border-radius:30px"
                title="Export rekap nilai + deskripsi yang sudah diinput ke Excel">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                Export Excel
            </a>
            <?php endif; ?>
            <a id="linkImportExcel"
                href="/nilai.import?ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>&mapel=<?= $mapel_id ?>&jenis=<?= e($jenis) ?>&bulan=<?= $bulan ?>"
                class="btn btn-secondary" style="border-radius: 30px"
                title="Download template dengan daftar siswa sudah terisi, lalu upload kembali">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="17 8 12 3 7 8" />
                    <line x1="12" y1="3" x2="12" y2="15" />
                </svg>
                Import Excel
            </a>
            <a href="/nilai" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="15 18 9 12 15 6" />
                </svg>
                Kembali ke Daftar
            </a>
        </div>
    </div>

    <!-- â"€â"€ STEP 1: PILIH FILTER â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ -->
    <div class="card mb-5">
        <div class="card-header">
            <span class="flex items-center gap-2">
                <span
                    style="background:#f97316;color:#fff;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700">1</span>
                Pilih Mata Pelajaran, Kelas &amp; Periode
            </span>
        </div>
        <div class="card-body">
            <form method="GET" action="index.php" id="filterForm">
                <input type="hidden" name="page" value="nilai.bulk">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                    <!-- Tahun Ajaran -->
                    <div class="form-group mb-0">
                        <label class="form-label">Tahun Ajaran</label>
                        <select name="ta" class="form-control" id="selectTA" onchange="onTAChange(this)">
                            <?php foreach ($ta_list as $ta): ?>
                            <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_id?'selected':'' ?>>
                                <?= e($ta['nama']) ?>/<?= $ta['semester'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Kelas -->
                    <div class="form-group mb-0">
                        <label class="form-label">Kelas</label>
                        <select name="kelas" class="form-control" id="selectKelas" onchange="onKelasChange(this)">
                            <option value="">-- Pilih --</option>
                            <?php foreach ($kelas_list as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $k['id']==$kelas_id?'selected':'' ?>>
                                <?= e($k['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Mapel -->
                    <div class="form-group mb-0">
                        <label class="form-label">Mata Pelajaran</label>
                        <select name="mapel" id="selectMapel" class="form-control" onchange="this.form.submit()">
                            <option value="">-- Pilih --</option>
                            <?php foreach ($mapel_list as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $m['id']==$mapel_id?'selected':'' ?>>
                                <?= $m['kode_mapel'] ? '['.e($m['kode_mapel']).'] ' : '' ?><?= e($m['nama_mapel']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Jenis -->
                    <div class="form-group mb-0">
                        <label class="form-label">Jenis</label>
                        <select name="jenis" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($jenis_opts as $jk => $jv): ?>
                            <option value="<?= $jk ?>" <?= $jenis===$jk?'selected':'' ?>><?= $jv ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Bulan (kecuali semester) -->
                    <?php if ($jenis !== 'semester'): ?>
                    <div class="form-group mb-0">
                        <label class="form-label">
                            Bulan <span class="text-red-500">*</span>
                        </label>
                        <select name="bulan" id="selectBulan" class="form-control" onchange="this.form.submit()"
                            style="<?= (!$bulan && $kelas_id && $mapel_id) ? 'border-color:#ef4444;background:#fef2f2' : '' ?>">
                            <option value="">-- Pilih Bulan --</option>
                            <?php foreach ($bulan_opts as $bk => $bv): ?>
                            <option value="<?= $bk ?>" <?= $bk==$bulan?'selected':'' ?>><?= $bv ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="bulan" value="0">
                    <?php endif; ?>
                    <!-- Tombol -->
                    <div class="form-group mb-0 flex items-end">
                        <button type="submit" class="btn btn-primary w-100"
                            style="border-radius: 30px">Tampilkan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$kelas_id || !$mapel_id): ?>
    <!-- Belum pilih filter -->
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"
                class="mx-auto mb-3">
                <path d="M9 11l3 3L22 4" />
                <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" />
            </svg>
            <p>Pilih <strong>Kelas</strong> dan <strong>Mata Pelajaran</strong> untuk mulai input nilai.</p>
        </div>
    </div>

    <?php elseif (in_array($jenis, ['bulanan','sts']) && !$bulan): ?>
    <!-- Belum pilih bulan -->
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"
                class="mx-auto mb-3" style="color:#f97316">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                <line x1="16" y1="2" x2="16" y2="6" />
                <line x1="8" y1="2" x2="8" y2="6" />
                <line x1="3" y1="10" x2="21" y2="10" />
            </svg>
            <p style="font-weight:600;font-size:1rem;margin-bottom:6px">Pilih Bulan Terlebih Dahulu</p>
            <p class="text-gray-400 text-sm">Jenis rapor <strong><?= e($jenis_opts[$jenis]) ?></strong> memerlukan
                bulan.<br>
                Pilih bulan di filter atas lalu klik <strong>Tampilkan</strong>.</p>
        </div>
    </div>

    <?php else: ?>

    <!-- â"€â"€ INFO HEADER (seperti format Excel) â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ -->
    <div class="card mb-4" style="border-left:4px solid #f97316">
        <div class="card-body py-3">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
                <div class="flex gap-2">
                    <span class="text-gray-400 w-36 flex-shrink-0">Mata Pelajaran</span>
                    <span class="font-bold text-gray-800">: <?= e($mapel_info['nama_mapel'] ?? '-') ?></span>
                </div>
                <div class="flex gap-2">
                    <span class="text-gray-400 w-36 flex-shrink-0">Kelas</span>
                    <span class="font-bold text-gray-800">: <?= e($kelas_info['nama_kelas'] ?? '-') ?></span>
                </div>
                <div class="flex gap-2">
                    <span class="text-gray-400 w-36 flex-shrink-0">Guru Mata Pelajaran</span>
                    <span class="font-bold text-orange-600">:
                        <?= e($guru_pengampu['nama_lengkap'] ?? $user['nama_lengkap']) ?></span>
                </div>
                <div class="flex gap-2">
                    <span class="text-gray-400 w-36 flex-shrink-0">Jenis Rapor</span>
                    <span class="font-semibold">: <?= e($jenis_opts[$jenis] ?? $jenis) ?></span>
                </div>
                <div class="flex gap-2">
                    <span class="text-gray-400 w-36 flex-shrink-0">Periode</span>
                    <span class="font-semibold">:
                        <?= $jenis==='semester' ? 'Akhir Semester' : e($bulan_opts[$bulan] ?? '-') ?></span>
                </div>
                <div class="flex gap-2">
                    <span class="text-gray-400 w-36 flex-shrink-0">Jumlah Murid</span>
                    <span class="font-semibold">: <?= count($siswa_list) ?> murid</span>
                </div>
            </div>
        </div>
    </div>

    <!-- â"€â"€ STEP 2: FORM INPUT â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ -->
    <form method="POST" action="/nilai.bulk" id="formBulk">
        <?= csrf_field() ?>
        <input type="hidden" name="tahun_ajaran_id" value="<?= $ta_id ?>">
        <input type="hidden" name="kelas_id" value="<?= $kelas_id ?>">
        <input type="hidden" name="mapel_id" value="<?= $mapel_id ?>">
        <input type="hidden" name="jenis_raport" value="<?= e($jenis) ?>">
        <input type="hidden" name="bulan" value="<?= $bulan ?>">
        <?php foreach ($siswa_list as $s): ?>
        <input type="hidden" name="siswa_ids[]" value="<?= $s['id'] ?>">
        <?php endforeach; ?>
        <?php if ($jenis === 'bulanan'): ?>
        <!-- Kolom aktif: nilai dari DB (atau default 1 jika belum pernah disimpan) -->
        <input type="hidden" id="hid_nh1" name="aktif_nh1" value="<?= $db_aktif_nh1 ?>">
        <input type="hidden" id="hid_nh2" name="aktif_nh2" value="<?= $db_aktif_nh2 ?>">
        <input type="hidden" id="hid_nh3" name="aktif_nh3" value="<?= $db_aktif_nh3 ?>">
        <input type="hidden" id="hid_tugas" name="aktif_tugas" value="<?= $db_aktif_tugas ?>">
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

            <!-- â"€â"€ KIRI: Tabel Nilai Siswa â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ -->
            <div class="xl:col-span-2">
                <div class="card">
                    <div class="card-header">
                        <span class="flex items-center gap-2">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <path d="M23 21v-2a4 4 0 00-3-3.87" />
                                <path d="M16 3.13a4 4 0 010 7.75" />
                            </svg>
                            Nilai Murid
                        </span>
                        <div class="flex gap-2">
                            <button type="button" onclick="isiSemuaRata()" class="btn btn-sm btn-secondary"
                                style="border-radius: 30px">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24">
                                    <path d="M12 20h9" />
                                    <path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z" />
                                </svg>
                                Isi Rata-rata
                            </button>
                            <?php if (!empty($siswa_list) && $kelas_id && $mapel_id): ?>
                            <button type="button" onclick="bukaReviewNilai()" class="btn btn-sm btn-secondary"
                                style="border-radius: 30px; border-color:#6366f1; color:#6366f1">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                Review Nilai
                            </button>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm btn-primary" style="border-radius: 30px">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"
                                    viewBox="0 0 24 24">
                                    <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                                    <polyline points="17 21 17 13 7 13 7 21" />
                                </svg>
                                Simpan Semua
                            </button>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="data-table" id="tblNilai">
                            <thead>
                                <?php if ($jenis === 'bulanan'): ?>
                                <!-- Baris checkbox pemilih kolom — hanya bulanan -->
                                <tr style="background:#fff7ed">
                                    <th colspan="2"
                                        style="text-align:right;font-size:.7rem;color:#9ca3af;font-weight:500;padding:4px 8px">
                                        Kolom aktif:
                                    </th>
                                    <?php foreach (['nh1'=>'NH1','nh2'=>'NH2','nh3'=>'NH3','tugas'=>'Tugas'] as $col=>$lbl): ?>
                                    <th style="text-align:center;padding:4px 2px">
                                        <label
                                            style="display:flex;flex-direction:column;align-items:center;gap:2px;cursor:pointer">
                                            <?php
                                            $chk_checked = match($col) {
                                                'nh1'   => $db_aktif_nh1,
                                                'nh2'   => $db_aktif_nh2,
                                                'nh3'   => $db_aktif_nh3,
                                                'tugas' => $db_aktif_tugas,
                                                default => 1,
                                            };
                                            ?>
                                            <input type="checkbox" id="chk_<?= $col ?>" class="kolom-aktif-chk"
                                                data-col="<?= $col ?>" <?= $chk_checked ? 'checked' : '' ?>
                                                style="width:14px;height:14px;cursor:pointer;accent-color:#f97316">
                                            <span
                                                style="font-size:.65rem;color:#6b7280;font-weight:500"><?= $lbl ?></span>
                                        </label>
                                    </th>
                                    <?php endforeach; ?>
                                    <th colspan="3"></th>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th style="width:32px">No</th>
                                    <th>Nama Murid</th>
                                    <?php if ($jenis === 'bulanan'): ?>
                                    <th style="width:68px">NH1</th>
                                    <th style="width:68px">NH2</th>
                                    <th style="width:68px">NH3</th>
                                    <th style="width:68px">Tugas</th>
                                    <?php elseif ($jenis === 'sts'): ?>
                                    <th style="width:82px">ASTS</th>
                                    <th style="width:82px">Kumulatif</th>
                                    <?php else: ?>
                                    <th style="width:90px">Nilai SAS</th>
                                    <?php endif; ?>
                                    <th style="width:72px">
                                        <span class="text-orange-600">Nilai</span>
                                    </th>
                                    <th style="width:42px">Pred.</th>
                                    <th style="width:44px" title="Aksi">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"
                                            viewBox="0 0 24 24">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="19" cy="12" r="1" />
                                            <circle cx="5" cy="12" r="1" />
                                        </svg>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($siswa_list)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-8 text-gray-400">Tidak ada murid di kelas
                                        ini.</td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($siswa_list as $i => $s): ?>
                                <?php
                  $nv  = $existing_nilai[$s['id']] ?? null;
                  $na  = (float)($nv['nilai_akhir'] ?? 0);
                  $pred = $nv['predikat'] ?? '';
                  $predClass = $pred==='A'?'text-green-600':($pred==='B'?'text-blue-600':($pred==='C'?'text-yellow-600':'text-red-500'));
                ?>
                                <tr id="row_<?= $s['id'] ?>" class="hover:bg-orange-50">
                                    <td class="text-gray-400 text-xs text-center"><?= $i+1 ?></td>
                                    <td>
                                        <div class="font-semibold text-sm"><?= e($s['nama_lengkap']) ?></div>
                                        <div class="text-xs text-gray-400"><?= e($s['nis']) ?></div>
                                    </td>

                                    <?php if ($jenis === 'bulanan'): ?>
                                    <td>
                                        <input type="number" name="h1_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                                            value="<?= $nv['nilai_harian_1'] ?? 0 ?>"
                                            class="nilai-input form-control text-center px-1"
                                            style="padding:4px 2px;font-size:.8rem" data-sid="<?= $s['id'] ?>"
                                            data-field="h1" onchange="hitungBaris(<?= $s['id'] ?>)">
                                    </td>
                                    <td>
                                        <input type="number" name="h2_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                                            value="<?= $nv['nilai_harian_2'] ?? 0 ?>"
                                            class="nilai-input form-control text-center px-1"
                                            style="padding:4px 2px;font-size:.8rem" data-sid="<?= $s['id'] ?>"
                                            data-field="h2" onchange="hitungBaris(<?= $s['id'] ?>)">
                                    </td>
                                    <td>
                                        <input type="number" name="h3_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                                            value="<?= $nv['nilai_harian_3'] ?? 0 ?>"
                                            class="nilai-input form-control text-center px-1"
                                            style="padding:4px 2px;font-size:.8rem" data-sid="<?= $s['id'] ?>"
                                            data-field="h3" onchange="hitungBaris(<?= $s['id'] ?>)">
                                    </td>
                                    <td>
                                        <input type="number" name="tugas_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                                            value="<?= $nv['nilai_tugas'] ?? 0 ?>"
                                            class="nilai-input form-control text-center px-1"
                                            style="padding:4px 2px;font-size:.8rem" data-sid="<?= $s['id'] ?>"
                                            data-field="tugas" onchange="hitungBaris(<?= $s['id'] ?>)">
                                    </td>

                                    <?php elseif ($jenis === 'sts'): ?>
                                    <td>
                                        <input type="number" name="sts_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                                            value="<?= $nv['nilai_sts'] ?? 0 ?>"
                                            class="nilai-input form-control text-center"
                                            style="padding:4px 2px;font-size:.8rem" data-sid="<?= $s['id'] ?>"
                                            data-field="sts" onchange="hitungBaris(<?= $s['id'] ?>)">
                                    </td>
                                    <td>
                                        <input type="number" name="kumulatif_<?= $s['id'] ?>" min="0" max="100"
                                            step="0.01" value="<?= $nv['nilai_kumulatif'] ?? 0 ?>"
                                            class="nilai-input form-control text-center"
                                            style="padding:4px 2px;font-size:.8rem;background:#f0f9ff;border-color:#bae6fd"
                                            data-sid="<?= $s['id'] ?>" data-field="kumulatif"
                                            onchange="hitungBaris(<?= $s['id'] ?>)">
                                    </td>

                                    <?php else: ?>
                                    <td>
                                        <input type="number" name="sas_<?= $s['id'] ?>" min="0" max="100" step="0.01"
                                            value="<?= $nv['nilai_sas'] ?? 0 ?>"
                                            class="nilai-input form-control text-center"
                                            style="padding:4px 2px;font-size:.8rem" data-sid="<?= $s['id'] ?>"
                                            data-field="sas" onchange="hitungBaris(<?= $s['id'] ?>)">
                                    </td>
                                    <?php endif; ?>

                                    <!-- Nilai Akhir (readonly, dihitung JS) -->
                                    <td>
                                        <input type="number" id="na_<?= $s['id'] ?>"
                                            value="<?= $nv !== null ? $na : '' ?>"
                                            class="form-control text-center font-bold text-orange-600"
                                            style="padding:4px 2px;font-size:.85rem;background:#fff7ed;border-color:#fed7aa"
                                            readonly tabindex="-1">
                                    </td>

                                    <!-- Predikat -->
                                    <td class="text-center">
                                        <span id="pred_<?= $s['id'] ?>" class="font-bold <?= $predClass ?>"
                                            style="font-size:.9rem">
                                            <?= $pred ?: '-' ?>
                                        </span>
                                    </td>

                                    <!-- Tombol deskripsi -->
                                    <td class="text-center">
                                        <button type="button" onclick="toggleDeskSiswa(<?= $s['id'] ?>)"
                                            class="btn btn-sm btn-secondary" style="padding:3px 7px" title="Deskripsi">
                                            <svg width="12" height="12" fill="none" stroke="currentColor"
                                                stroke-width="2" viewBox="0 0 24 24">
                                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                                                <polyline points="14 2 14 8 20 8" />
                                                <line x1="16" y1="13" x2="8" y2="13" />
                                                <line x1="16" y1="17" x2="8" y2="17" />
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                                <!-- Baris deskripsi siswa (tersembunyi, expand via tombol) -->
                                <tr id="deskRow_<?= $s['id'] ?>" style="display:none;background:#fffbf5">
                                    <td colspan="<?= $jenis==='bulanan'?10:8 ?>" style="padding:8px 12px">
                                        <div class="flex flex-col gap-2">
                                            <div class="flex items-center gap-2">
                                                <label class="text-xs font-semibold text-gray-500 whitespace-nowrap">
                                                    Deskripsi : <?= e($s['nama_lengkap']) ?>:
                                                </label>
                                                <!-- Status simpan -->
                                                <span id="deskStatus_<?= $s['id'] ?>"
                                                    class="text-xs text-gray-400"></span>
                                            </div>
                                            <div class="flex items-start gap-2">
                                                <!-- textarea: cegah Enter/S memicu submit form -->
                                                <textarea id="deskArea_<?= $s['id'] ?>" name="deskripsi_<?= $s['id'] ?>"
                                                    rows="3" class="form-control text-sm flex-1"
                                                    placeholder="Kosongkan untuk menggunakan deskripsi predikat otomatis..."
                                                    onkeydown="return event.key !== 'Enter' || (event.shiftKey ? true : false)"><?= e($nv['deskripsi'] ?? '') ?></textarea>
                                                <!-- Tombol simpan deskripsi (AJAX-free: langsung set hidden field + feedback) -->
                                                <div class="flex flex-col gap-1">
                                                    <button type="button" class="btn btn-sm btn-primary"
                                                        style="min-width:70px; border-radius: 30px;"
                                                        onclick="simpanDeskripsi(<?= $s['id'] ?>)">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor"
                                                            stroke-width="2.5" viewBox="0 0 24 24">
                                                            <path
                                                                d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                                                            <polyline points="17 21 17 13 7 13 7 21" />
                                                        </svg>
                                                        Simpan
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-secondary"
                                                        style="min-width:70px; border-radius: 30px;"
                                                        onclick="toggleDeskSiswa(<?= $s['id'] ?>)">
                                                        Tutup
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <input type="hidden" name="catatan_<?= $s['id'] ?>"
                                            value="<?= e($nv['catatan'] ?? '') ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if (!empty($siswa_list)): ?>
                            <tfoot>
                                <tr style="background:#fff7ed;font-weight:700">
                                    <td colspan="<?= $jenis==='bulanan'?6:3 ?>" class="text-right px-3 py-2 text-sm">
                                        Rata-rata Kelas:</td>
                                    <td class="text-center text-orange-600" id="rataKelas">-</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!-- Tombol simpan bawah -->
                    <div class="p-4 border-t border-gray-100 flex justify-end">
                        <button type="submit" class="btn btn-primary" style="border-radius: 30px">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
                                viewBox="0 0 24 24">
                                <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                                <polyline points="17 21 17 13 7 13 7 21" />
                            </svg>
                            Simpan Semua Nilai
                        </button>
                    </div>
                </div>
            </div>

            <!-- â"€â"€ KANAN: Deskripsi Kriteria â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ -->
            <div class="xl:col-span-1">
                <div class="card" style="position:sticky;top:80px">
                    <div class="card-header">
                        <span class="flex items-center gap-2">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            Deskripsi per Kriteria
                        </span>
                        <span class="text-xs text-gray-400">Otomatis ke deskripsi murid</span>
                    </div>
                    <div class="card-body" style="padding:12px">
                        <p class="text-xs text-gray-400 mb-3">
                            Isi deskripsi untuk setiap predikat. Saat simpan, murid akan otomatis mendapat
                            deskripsi sesuai predikatnya (jika deskripsi murid dikosongkan).
                        </p>

                        <?php
            $krit_data = [
              'A' => ['label'=>'Predikat A', 'range'=>">={$krit_a}", 'color'=>'green', 'border'=>'#22c55e', 'bg'=>'#f0fdf4'],
              'B' => ['label'=>'Predikat B', 'range'=>">={$krit_b}", 'color'=>'blue',  'border'=>'#3b82f6', 'bg'=>'#eff6ff'],
              'C' => ['label'=>'Predikat C', 'range'=>">={$krit_c}", 'color'=>'yellow','border'=>'#eab308', 'bg'=>'#fefce8'],
              'D' => ['label'=>'Predikat D', 'range'=>"<{$krit_c}", 'color'=>'red',   'border'=>'#ef4444', 'bg'=>'#fef2f2'],
            ];
            foreach ($krit_data as $kr => $kd):
            ?>
                        <div style="border-left:3px solid <?= $kd['border'] ?>;background:<?= $kd['bg'] ?>;
                        border-radius:0 8px 8px 0;padding:10px 12px;margin-bottom:10px">
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-bold text-sm"
                                    style="color:<?= $kd['border'] ?>"><?= $kd['label'] ?></span>
                                <span class="text-xs text-gray-400"><?= $kd['range'] ?></span>
                            </div>
                            <textarea name="desk_<?= $kr ?>" id="deskKrit_<?= $kr ?>" rows="3"
                                class="form-control text-xs" style="font-size:.8rem;line-height:1.5"
                                placeholder="Masukkan deskripsi untuk siswa dengan predikat <?= $kr ?>..."
                                oninput="updateDeskPreview('<?= $kr ?>', this.value)"><?= e($deskripsi_kriteria[$kr] ?? '') ?></textarea>
                            <button type="button" class="btn btn-sm btn-secondary mt-1 w-full text-xs"
                                onclick="applyDeskToAll('<?= $kr ?>', document.getElementById('deskKrit_<?= $kr ?>').value)">
                                Terapkan ke semua siswa predikat <?= $kr ?>
                            </button>
                        </div>
                        <?php endforeach; ?>

                        <!-- Tombol sinkronkan semua deskripsi sekaligus -->
                        <button type="button" class="btn btn-primary w-full mt-1 text-xs"
                            style="font-size:.8rem; border-radius: 30px" onclick="sinkronSemuaDeskripsi()">
                            Sinkronkan Semua Deskripsi
                        </button>
                        <p class="text-xs text-gray-400 mt-1" style="font-size:.73rem">
                            Overwrite deskripsi semua siswa dari kolom kanan sesuai predikat masing-masing.
                        </p>

                        <div class="mt-2 p-2 rounded-lg bg-gray-50 border border-gray-200 text-xs text-gray-500">
                            <strong>Kriteria nilai:</strong><br>
                            A: >=<?= (int)$krit_a ?> &nbsp; B: >=<?= (int)$krit_b ?> &nbsp;
                            C: >=<?= (int)$krit_c ?> &nbsp; D: &lt;<?= (int)$krit_c ?>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.grid -->
    </form>
    <?php endif; ?>
</div>

<!-- ══ Modal Review Nilai ═══════════════════════════════════════════════ -->
<?php if (!empty($siswa_list) && $kelas_id && $mapel_id): ?>
<div id="modalReviewNilai" style="display:none;position:fixed;inset:0;z-index:1050;
    background:rgba(0,0,0,.45);align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:14px;width:100%;max-width:800px;
        max-height:90vh;display:flex;flex-direction:column;
        box-shadow:0 20px 60px rgba(0,0,0,.2)">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;
            padding:16px 20px;border-bottom:1px solid #e5e7eb;flex-shrink:0">
            <div>
                <div style="font-weight:700;font-size:1rem;color:#1f2328;display:flex;align-items:center;gap:6px">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    Review Nilai &amp; Deskripsi
                </div>
                <div style="font-size:.78rem;color:#57606a;margin-top:3px">
                    <?= e($kelas_info['nama_kelas'] ?? '') ?>
                    &mdash; <?= e($mapel_info['nama_mapel'] ?? '') ?>
                    <?php if ($jenis === 'bulanan' && $bulan): ?>
                    &mdash; <?= BULAN_ID[$bulan] ?? "Bulan $bulan" ?>
                    <?php elseif ($jenis === 'sts'): ?>
                    &mdash; ASTS
                    <?php elseif ($jenis === 'semester'): ?>
                    &mdash; Semester
                    <?php endif; ?>
                </div>
            </div>
            <button onclick="tutupReviewNilai()" style="background:none;border:none;cursor:pointer;color:#9ca3af;
                font-size:1.6rem;line-height:1;padding:4px 8px">&times;</button>
        </div>

        <!-- Statistik ringkas -->
        <div id="reviewStats" style="display:flex;gap:10px;padding:12px 20px;
            background:#f7f8fa;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;flex-shrink:0"></div>

        <!-- Filter -->
        <div style="padding:10px 20px;border-bottom:1px solid #f3f4f6;
            display:flex;align-items:center;gap:8px;flex-shrink:0">
            <span style="font-size:.78rem;color:#57606a;white-space:nowrap">Tampilkan:</span>
            <select id="reviewFilter" onchange="renderReviewTabel()" style="font-size:.78rem;padding:5px 10px;border:1px solid #e5e7eb;
                border-radius:8px;background:#fff;color:#1f2328;cursor:pointer">
                <option value="semua">Semua siswa</option>
                <option value="nilai_kosong">Nilai belum diinput</option>
                <option value="desk_kosong">Deskripsi belum diisi</option>
                <option value="lengkap">Nilai &amp; Deskripsi sudah lengkap</option>
            </select>
            <span id="reviewJumlah" style="font-size:.75rem;color:#9ca3af;margin-left:4px"></span>
        </div>

        <!-- Tabel -->
        <div style="overflow-y:auto;flex:1;padding:0 20px 16px">
            <table style="width:100%;border-collapse:collapse;font-size:.82rem;margin-top:12px">
                <thead>
                    <tr style="background:#f7f8fa;position:sticky;top:0;z-index:1">
                        <th style="text-align:left;padding:7px 10px;font-weight:600;color:#57606a;
                            border-bottom:2px solid #e5e7eb;white-space:nowrap;width:36px">#</th>
                        <th style="text-align:left;padding:7px 10px;font-weight:600;color:#57606a;
                            border-bottom:2px solid #e5e7eb">Nama Murid</th>
                        <th style="text-align:center;padding:7px 10px;font-weight:600;color:#57606a;
                            border-bottom:2px solid #e5e7eb;white-space:nowrap;width:90px">Nilai Akhir</th>
                        <th style="text-align:center;padding:7px 10px;font-weight:600;color:#57606a;
                            border-bottom:2px solid #e5e7eb;width:70px">Predikat</th>
                        <th style="text-align:left;padding:7px 10px;font-weight:600;color:#57606a;
                            border-bottom:2px solid #e5e7eb">Deskripsi</th>
                    </tr>
                </thead>
                <tbody id="reviewTbody"></tbody>
            </table>
        </div>

        <!-- Footer -->
        <div style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;
            justify-content:flex-end;gap:8px;flex-shrink:0">
            <button onclick="tutupReviewNilai()" class="btn btn-secondary" style="border-radius:30px">Tutup</button>
        </div>
    </div>
</div>

<script>
/* Data siswa dari PHP — dipakai fungsi bukaReviewNilai() */
const REVIEW_SISWA = <?= json_encode(array_values(array_map(fn($s) => [
    'id'   => (int)$s['id'],
    'nama' => $s['nama_lengkap'],
    'nis'  => $s['nis'],
], $siswa_list))) ?>;
</script>
<?php endif; ?>


<script>
/* â"€â"€ Konfigurasi kriteria dari server â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€â"€ */
const KRIT = {
    A: <?= $krit_a ?>,
    B: <?= $krit_b ?>,
    C: <?= $krit_c ?>
};
const JENIS = '<?= e($jenis) ?>';
/* Deskripsi per kriteria yang tersimpan di server (untuk deteksi stale) */
const DESK_KRIT = {
    A: <?= json_encode($deskripsi_kriteria['A'] ?? '') ?>,
    B: <?= json_encode($deskripsi_kriteria['B'] ?? '') ?>,
    C: <?= json_encode($deskripsi_kriteria['C'] ?? '') ?>,
    D: <?= json_encode($deskripsi_kriteria['D'] ?? '') ?>
};

/**
 * Kembalikan daftar field bulanan yang sedang AKTIF (checkbox dicentang)
 */
function getKolomAktif() {
    const map = {
        nh1: 'h1',
        nh2: 'h2',
        nh3: 'h3',
        tugas: 'tugas'
    };
    const aktif = [];
    document.querySelectorAll('.kolom-aktif-chk').forEach(chk => {
        if (chk.checked) aktif.push(map[chk.dataset.col]);
    });
    return aktif; // e.g. ['h1', 'tugas']
}

/**
 * Hitung nilai akhir satu baris siswa
 */
function hitungBaris(sid, isInit = false) {
    let na = 0;
    if (JENIS === 'bulanan') {
        const kolomAktif = getKolomAktif();
        // Hanya kolom aktif yang masuk perhitungan; nilai 0/kosong tetap dihitung (dibagi jumlah kolom aktif)
        const nilaiKolom = kolomAktif.map(f => {
            const el = document.querySelector(`[name="${f}_${sid}"]`);
            return el ? (parseFloat(el.value) || 0) : 0;
        });
        na = kolomAktif.length > 0 ?
            nilaiKolom.reduce((a, b) => a + b, 0) / kolomAktif.length :
            0;
    } else if (JENIS === 'sts') {
        const stsVal = parseFloat(document.querySelector(`[name="sts_${sid}"]`)?.value) || 0;
        const kumuRaw = document.querySelector(`[name="kumulatif_${sid}"]`)?.value ?? '';
        // nilai_akhir = kumulatif jika guru mengisi field (termasuk jika diisi 0),
        // fallback ke sts hanya jika field kumulatif benar-benar kosong/tidak diisi
        na = kumuRaw.trim() !== '' ? (parseFloat(kumuRaw) || 0) : stsVal;
    } else {
        na = parseFloat(document.querySelector(`[name="sas_${sid}"]`)?.value) || 0;
    }
    na = Math.round(na * 100) / 100;

    // Tampilkan nilai akhir
    const naEl = document.getElementById(`na_${sid}`);
    if (naEl) {
        // Saat init: jika field sudah diisi dari DB (ada value), recalc tapi tetap tampilkan.
        // Jika field kosong (belum diinput guru), biarkan kosong — jangan tampilkan "0".
        // Saat user edit (isInit=false): selalu tampilkan hasil kalkulasi.
        if (isInit && naEl.value === '') {
            // kosong dari DB → biarkan kosong, skip update
        } else {
            naEl.value = na.toFixed(2);
        }
    }

    // Predikat
    let pred = 'D';
    if (na >= KRIT.A) pred = 'A';
    else if (na >= KRIT.B) pred = 'B';
    else if (na >= KRIT.C) pred = 'C';

    const predEl = document.getElementById(`pred_${sid}`);
    if (predEl) {
        // Saat init & kosong: biarkan '-'
        const showPred = isInit ? (naEl && naEl.value !== '') : true;
        predEl.textContent = (na > 0 && showPred) ? pred : '-';
        // predEl.textContent = na > 0 ? pred : 'â€"';
        predEl.className = 'font-bold ' + {
            A: 'text-green-600',
            B: 'text-blue-600',
            C: 'text-yellow-600',
            D: 'text-red-500'
        } [pred];
    }

    // Highlight baris jika 100
    const row = document.getElementById(`row_${sid}`);
    if (row) {
        row.style.color = na === 100 ? '#ea580c' : '';
        row.style.fontWeight = na === 100 ? '700' : '';
    }

    hitungRataKelas();
}

/**
 * Hitung rata-rata kelas dari semua nilai akhir
 */
function hitungRataKelas() {
    const inputs = document.querySelectorAll('[id^="na_"]');
    let sum = 0,
        count = 0;
    inputs.forEach(el => {
        const v = parseFloat(el.value);
        if (!isNaN(v) && v > 0) {
            sum += v;
            count++;
        }
    });
    const rataEl = document.getElementById('rataKelas');
    if (rataEl) rataEl.textContent = count > 0 ? (sum / count).toFixed(2) : '-';
}

/**
 * Expand/collapse baris deskripsi siswa.
 * Saat dibuka: auto-koreksi jika isi textarea adalah deskripsi kriteria LAIN
 * (stale â€" predikat siswa berubah sejak terakhir disimpan).
 */
function toggleDeskSiswa(sid) {
    const row = document.getElementById(`deskRow_${sid}`);
    if (!row) return;

    const isHidden = row.style.display === 'none' || row.style.display === '';
    row.style.display = isHidden ? 'table-row' : 'none';

    // Koreksi deskripsi stale hanya saat membuka
    if (isHidden) {
        const ta = document.getElementById(`deskArea_${sid}`);
        const pred = document.getElementById(`pred_${sid}`)?.textContent.trim();
        if (!ta || !pred || pred === '-') return;

        const current = ta.value.trim();
        if (current === '') {
            // Kosong: isi dari deskripsi kriteria yang sesuai predikat
            if (DESK_KRIT[pred]) ta.value = DESK_KRIT[pred];
            return;
        }

        // Cek apakah isi textarea adalah deskripsi kriteria LAIN (stale)
        const allKriteria = ['A', 'B', 'C', 'D'];
        const isStale = allKriteria.some(k => k !== pred && DESK_KRIT[k] && DESK_KRIT[k] === current);
        if (isStale && DESK_KRIT[pred]) {
            ta.value = DESK_KRIT[pred];
            const status = document.getElementById(`deskStatus_${sid}`);
            if (status) {
                status.textContent = 'Deskripsi dikoreksi sesuai predikat ' + pred;
                status.style.color = '#d97706';
                setTimeout(() => {
                    status.textContent = '';
                }, 4000);
            }
        }
    }
}

/**
 * Simpan deskripsi siswa â€" konfirmasi visual tanpa reload halaman.
 * Nilai textarea sudah otomatis ikut form submit (name="deskripsi_SID"),
 * tombol ini hanya memberi feedback visual bahwa teks sudah terkunci.
 */
function simpanDeskripsi(sid) {
    const ta = document.getElementById(`deskArea_${sid}`);
    const status = document.getElementById(`deskStatus_${sid}`);
    if (!ta) return;

    const val = ta.value.trim();
    // Tandai textarea sebagai "tersimpan" secara visual
    ta.style.borderColor = '#16a34a';
    ta.style.background = '#f0fdf4';
    if (status) {
        status.textContent = 'Deskripsi siap disimpan';
        status.style.color = '#16a34a';
    }

    // Reset visual setelah 3 detik
    setTimeout(() => {
        ta.style.borderColor = '';
        ta.style.background = '';
        if (status) {
            status.textContent = '';
        }
    }, 3000);
}

/**
 * Real-time preview: saat user mengetik di textarea kanan,
 * update SEMUA siswa yang predikatnya sama (termasuk yang sudah ada isinya).
 * Highlight kuning sementara agar user sadar ada perubahan.
 */
function updateDeskPreview(kr, val) {
    document.querySelectorAll('[id^="pred_"]').forEach(predEl => {
        if (predEl.textContent.trim() !== kr) return;
        const sid = predEl.id.replace('pred_', '');
        const ta = document.getElementById(`deskArea_${sid}`);
        if (!ta) return;
        ta.value = val;
        // Highlight kuning sementara sebagai indikator updated
        ta.style.borderColor = '#f59e0b';
        ta.style.background = '#fffbeb';
        clearTimeout(ta._hlTimer);
        ta._hlTimer = setTimeout(() => {
            ta.style.borderColor = '';
            ta.style.background = '';
        }, 2000);
    });
}

/**
 * Terapkan deskripsi ke SEMUA siswa dengan predikat tertentu (paksa overwrite).
 * Membuka baris deskripsi yang masih tersembunyi.
 */
function applyDeskToAll(kr, val) {
    if (!val.trim()) {
        alert('Isi deskripsi terlebih dahulu.');
        return;
    }
    let count = 0;
    document.querySelectorAll('[id^="pred_"]').forEach(predEl => {
        if (predEl.textContent.trim() !== kr) return;
        const sid = predEl.id.replace('pred_', '');
        const ta = document.getElementById(`deskArea_${sid}`);
        if (ta) {
            ta.value = val;
            count++;
        }
        // Otomatis tampilkan baris deskripsi
        const deskRow = document.getElementById(`deskRow_${sid}`);
        if (deskRow) deskRow.style.display = 'table-row';
    });
    if (count > 0) showToast(`Deskripsi diterapkan ke ${count} siswa predikat ${kr}`, 'success');
}

/**
 * Sinkronkan SEMUA siswa sekaligus dari textarea kanan sesuai predikat masing-masing.
 * Berguna jika deskripsi kriteria diubah dan siswa sudah punya deskripsi lama di DB.
 */
function sinkronSemuaDeskripsi() {
    // Baca nilai terkini dari textarea kanan (bukan DESK_KRIT yang dari server)
    const deskNow = {
        A: (document.getElementById('deskKrit_A')?.value ?? '').trim(),
        B: (document.getElementById('deskKrit_B')?.value ?? '').trim(),
        C: (document.getElementById('deskKrit_C')?.value ?? '').trim(),
        D: (document.getElementById('deskKrit_D')?.value ?? '').trim(),
    };

    let count = 0;
    document.querySelectorAll('[id^="pred_"]').forEach(predEl => {
        const pred = predEl.textContent.trim();
        if (!['A', 'B', 'C', 'D'].includes(pred)) return;
        const sid = predEl.id.replace('pred_', '');
        const ta = document.getElementById(`deskArea_${sid}`);
        if (!ta) return;
        if (deskNow[pred] === '') return; // skip jika deskripsi kanan masih kosong
        ta.value = deskNow[pred];
        count++;
        // Highlight hijau sebentar
        ta.style.borderColor = '#16a34a';
        ta.style.background = '#f0fdf4';
        clearTimeout(ta._hlTimer);
        ta._hlTimer = setTimeout(() => {
            ta.style.borderColor = '';
            ta.style.background = '';
        }, 2500);
    });

    if (count > 0) {
        showToast(` ${count} deskripsi siswa berhasil disinkronkan`, 'success');
    } else {
        alert('Tidak ada deskripsi yang bisa disinkronkan.\nPastikan kolom kanan sudah diisi.');
    }
}

/**
 * Isi field dengan rata-rata (misalnya semua NH = 0 lalu set ke 80 default)
 * Digunakan tombol "Isi Rata-rata" untuk testing
 */
function isiSemuaRata() {
    const val = prompt('Isi nilai default untuk semua siswa (0-100):', '85');
    if (val === null) return;
    const num = parseFloat(val);
    if (isNaN(num) || num < 0 || num > 100) {
        alert('Nilai tidak valid!');
        return;
    }

    document.querySelectorAll('[id^="row_"]').forEach(row => {
        const sid = row.id.replace('row_', '');
        if (JENIS === 'bulanan') {
            // Hanya isi kolom yang aktif (checkbox dicentang)
            getKolomAktif().forEach(f => {
                const el = document.querySelector(`[name="${f}_${sid}"]`);
                if (el) el.value = num;
            });
        } else if (JENIS === 'sts') {
            const elSts = document.querySelector(`[name="sts_${sid}"]`);
            const elKumu = document.querySelector(`[name="kumulatif_${sid}"]`);
            if (elSts) elSts.value = num;
            if (elKumu) elKumu.value = num;
        } else {
            const el = document.querySelector(`[name="sas_${sid}"]`);
            if (el) el.value = num;
        }
        hitungBaris(parseInt(sid));
    });
}

// Init
document.addEventListener('DOMContentLoaded', () => {

    if (JENIS === 'bulanan') {
        const fieldMap = {
            nh1: 'h1',
            nh2: 'h2',
            nh3: 'h3',
            tugas: 'tugas'
        };
        const hidMap = {
            nh1: 'hid_nh1',
            nh2: 'hid_nh2',
            nh3: 'hid_nh3',
            tugas: 'hid_tugas'
        };

        /** Terapkan disable/style ke kolom berdasarkan checked */
        function terapkanKolom(col, checked) {
            const field = fieldMap[col];
            document.querySelectorAll(`[name^="${field}_"]`).forEach(inp => {
                inp.disabled = !checked;
                inp.style.background = checked ? '' : '#f3f4f6';
                inp.style.color = checked ? '' : '#9ca3af';
                inp.style.cursor = checked ? '' : 'not-allowed';
            });
        }

        // ── Terapkan state awal dari PHP (sudah di-inject via checked attribute) ──
        document.querySelectorAll('.kolom-aktif-chk').forEach(chk => {
            terapkanKolom(chk.dataset.col, chk.checked);
        });

        /** Update href tombol Import & Export Excel dengan state kolom aktif saat ini */
        function updateImportLink() {
            const state = {};
            document.querySelectorAll('.kolom-aktif-chk').forEach(chk => {
                state[chk.dataset.col] = chk.checked ? 1 : 0;
            });
            const aktifParams =
                '&aktif_nh1=' + (state.nh1 ?? 1) +
                '&aktif_nh2=' + (state.nh2 ?? 1) +
                '&aktif_nh3=' + (state.nh3 ?? 1) +
                '&aktif_tugas=' + (state.tugas ?? 1);

            // Import link
            const importLink = document.getElementById('linkImportExcel');
            if (importLink) {
                const base = importLink.href.split('&aktif_')[0];
                importLink.href = base + aktifParams;
            }
            // Export link — sinkronkan kolom aktif agar template yg diunduh konsisten
            const exportLink = document.getElementById('linkExportExcel');
            if (exportLink) {
                const base = exportLink.href.split('&aktif_')[0];
                exportLink.href = base + aktifParams;
            }
        }
        // Update link sesuai state awal
        updateImportLink();

        // ── Checkbox change: disable kolom + recalc ──────────────────────
        document.querySelectorAll('.kolom-aktif-chk').forEach(chk => {
            chk.addEventListener('change', function() {
                terapkanKolom(this.dataset.col, this.checked);
                // Hitung ulang semua baris
                document.querySelectorAll('[id^="row_"]').forEach(row => {
                    hitungBaris(parseInt(row.id.replace('row_', '')));
                });
                updateImportLink();
            });
        });

        // ── Sebelum submit: sync hidden inputs ───────────────────────────
        document.getElementById('formBulk')?.addEventListener('submit', function() {
            document.querySelectorAll('.kolom-aktif-chk').forEach(chk => {
                const hid = document.getElementById(hidMap[chk.dataset.col]);
                if (hid) hid.value = chk.checked ? '1' : '0';
            });
        });
    }

    // ── Hitung semua baris (setelah state kolom di-restore) ──────────────
    document.querySelectorAll('[id^="row_"]').forEach(row => {
        const sid = row.id.replace('row_', '');
        hitungBaris(parseInt(sid), true); // isInit=true: jangan tampilkan "0" untuk baris kosong
    });
});

/* ══ Review Nilai: buka / tutup / render ════════════════════════════════
   Membaca state langsung dari DOM (nilai & deskripsi yang sedang ada di
   halaman, termasuk yang baru diketik tapi belum disimpan).
   REVIEW_SISWA di-inject PHP di atas (array [{id, nama, nis}]).
════════════════════════════════════════════════════════════════════════ */
function bukaReviewNilai() {
    const modal = document.getElementById('modalReviewNilai');
    if (!modal) return;
    modal.style.display = 'flex';
    document.getElementById('reviewFilter').value = 'semua';
    renderReviewTabel();
}

function tutupReviewNilai() {
    const modal = document.getElementById('modalReviewNilai');
    if (modal) modal.style.display = 'none';
}

function renderReviewTabel() {
    if (typeof REVIEW_SISWA === 'undefined') return;
    const filter = document.getElementById('reviewFilter')?.value ?? 'semua';
    const tbody = document.getElementById('reviewTbody');
    const statsEl = document.getElementById('reviewStats');
    const jmlEl = document.getElementById('reviewJumlah');
    if (!tbody) return;

    const PRED_COLOR = {
        A: '#16a34a',
        B: '#2563eb',
        C: '#ca8a04',
        D: '#dc2626',
        '-': '#9ca3af'
    };

    // ── Kumpulkan data terkini dari DOM per siswa ──────────────────────
    const rows = REVIEW_SISWA.map(s => {
        const sid = s.id;

        // Nilai akhir dari input readonly #na_SID
        const naEl = document.getElementById('na_' + sid);
        const naVal = naEl ? naEl.value.trim() : '';
        const na = naVal !== '' ? parseFloat(naVal) : null;

        // Predikat dari span #pred_SID
        const predEl = document.getElementById('pred_' + sid);
        const pred = predEl ? predEl.textContent.trim() : '-';

        // Deskripsi dari textarea #deskArea_SID
        const taEl = document.getElementById('deskArea_' + sid);
        const desk = taEl ? taEl.value.trim() : '';

        return {
            id: sid,
            nama: s.nama,
            nis: s.nis,
            na,
            pred,
            desk
        };
    });

    // ── Statistik ringkas ──────────────────────────────────────────────
    const total = rows.length;
    const nilaiIsi = rows.filter(r => r.na !== null).length;
    const deskIsi = rows.filter(r => r.desk !== '').length;
    const lengkap = rows.filter(r => r.na !== null && r.desk !== '').length;
    const nilaiKosong = total - nilaiIsi;
    const deskKosong = total - deskIsi;

    const stat = (label, val, total, color) =>
        `<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;
            padding:8px 14px;text-align:center;min-width:110px">
            <div style="font-size:1.2rem;font-weight:700;color:${color}">${val}</div>
            <div style="font-size:.7rem;color:#57606a;margin-top:1px">${label}</div>
            ${total !== null ? `<div style="font-size:.68rem;color:#9ca3af">dari ${total} siswa</div>` : ''}
        </div>`;

    statsEl.innerHTML =
        stat('Total Siswa', total, null, '#1f2328') +
        stat('Nilai Terisi', nilaiIsi, total, '#16a34a') +
        stat('Nilai Kosong', nilaiKosong, total, nilaiKosong > 0 ? '#dc2626' : '#9ca3af') +
        stat('Deskripsi Terisi', deskIsi, total, '#2563eb') +
        stat('Deskripsi Kosong', deskKosong, total, deskKosong > 0 ? '#d97706' : '#9ca3af') +
        stat('Lengkap', lengkap, total, lengkap === total ? '#16a34a' : '#6366f1');

    // ── Filter ─────────────────────────────────────────────────────────
    let filtered = rows;
    if (filter === 'nilai_kosong') filtered = rows.filter(r => r.na === null);
    else if (filter === 'desk_kosong') filtered = rows.filter(r => r.desk === '');
    else if (filter === 'lengkap') filtered = rows.filter(r => r.na !== null && r.desk !== '');

    if (jmlEl) jmlEl.textContent = filtered.length + ' siswa';

    // ── Render baris ───────────────────────────────────────────────────
    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:24px;
            color:#9ca3af;font-size:.85rem">Tidak ada siswa untuk filter ini.</td></tr>`;
        return;
    }

    const rowsHTML = filtered.map((r, i) => {
        // Nilai akhir
        const nilaiHtml = r.na !== null ?
            `<span style="font-weight:700;color:#ea580c">${r.na.toFixed(2)}</span>` :
            `<span style="color:#dc2626;font-size:.75rem;font-weight:600">&#9888; Belum diinput</span>`;

        // Predikat
        const predColor = PRED_COLOR[r.pred] ?? '#9ca3af';
        const predHtml = r.pred && r.pred !== '-' ?
            `<span style="font-weight:700;color:${predColor};font-size:.9rem">${r.pred}</span>` :
            `<span style="color:#9ca3af">-</span>`;

        // Deskripsi
        let deskHtml;
        if (r.desk !== '') {
            // Tampilkan teks deskripsi (maks 80 karakter)
            const preview = r.desk.length > 80 ? r.desk.slice(0, 80) + '…' : r.desk;
            deskHtml = `<span style="color:#1f2328">${preview.replace(/</g,'&lt;')}</span>`;
        } else {
            deskHtml = `<span style="color:#d97706;font-size:.75rem;font-weight:600">
                &#9888; Belum diisi &mdash;
                <span style="color:#9ca3af;font-weight:400">klik ikon dokumen di baris siswa untuk mengisi</span>
            </span>`;
        }

        const oddBg = i % 2 === 0 ? '#fff' : '#f9fafb';
        return `<tr style="background:${oddBg}">
            <td style="padding:8px 10px;color:#9ca3af;font-size:.75rem;border-bottom:1px solid #f3f4f6">${i+1}</td>
            <td style="padding:8px 10px;border-bottom:1px solid #f3f4f6">
                <div style="font-weight:600;color:#1f2328">${r.nama.replace(/</g,'&lt;')}</div>
                <div style="font-size:.72rem;color:#9ca3af">${r.nis ? r.nis : ''}</div>
            </td>
            <td style="padding:8px 10px;text-align:center;border-bottom:1px solid #f3f4f6">${nilaiHtml}</td>
            <td style="padding:8px 10px;text-align:center;border-bottom:1px solid #f3f4f6">${predHtml}</td>
            <td style="padding:8px 10px;border-bottom:1px solid #f3f4f6;font-size:.8rem">${deskHtml}</td>
        </tr>`;
    }).join('');

    tbody.innerHTML = rowsHTML;
}

/* Tutup modal jika klik overlay (luar kotak) */
document.addEventListener('click', function(e) {
    const modal = document.getElementById('modalReviewNilai');
    if (modal && e.target === modal) tutupReviewNilai();
});

/**
 * Saat Kelas berubah: reset pilihan Mata Pelajaran ke kosong sebelum submit.
 * Ini memastikan guru tidak bisa terbawa mapel dari kelas lain di URL.
 */
function onKelasChange(sel) {
    const mapelSel = document.getElementById('selectMapel');
    if (mapelSel) mapelSel.value = '';
    sel.form.submit();
}

/**
 * Saat Tahun Ajaran berubah: reset Kelas DAN Mata Pelajaran ke kosong sebelum submit.
 */
function onTAChange(sel) {
    const kelasSel = document.getElementById('selectKelas');
    const mapelSel = document.getElementById('selectMapel');
    if (kelasSel) kelasSel.value = '';
    if (mapelSel) mapelSel.value = '';
    sel.form.submit();
}
</script>