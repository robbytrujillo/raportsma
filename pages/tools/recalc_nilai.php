<?php
/**
 * pages/tools/recalc_nilai.php
 * Hitung ulang nilai_akhir semua record bulanan/sts menggunakan formula
 * "hanya kolom yang terisi (> 0)" — mengembalikan konsistensi data lama
 * yang tersimpan sebelum perubahan formula.
 *
 * Admin-only. Akses: ?page=tools.recalc_nilai
 */
require_role(['admin', 'super_admin']);

$sekolah = sekolah_info();
$krit_a  = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b  = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c  = (float)($sekolah['kriteria_c_min'] ?? 76);

// ── Jalankan recalculate ──────────────────────────────────────
$updated   = 0;
$unchanged = 0;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recalc') {
    if (!verify_csrf()) { flash('error', 'Token tidak valid.'); header('Location: /tools.recalc_nilai'); exit; }

    // Ambil semua record bulanan dan sts
    $rows = db_query(
        "SELECT id, nilai_harian_1, nilai_harian_2, nilai_harian_3, nilai_tugas,
                nilai_sts, nilai_kumulatif, nilai_sas, jenis_raport, nilai_akhir
         FROM nilai
         WHERE jenis_raport IN ('bulanan', 'sts')"
    );

    foreach ($rows as $r) {
        $jenis = $r['jenis_raport'];
        $h1    = (float)$r['nilai_harian_1'];
        $h2    = (float)$r['nilai_harian_2'];
        $h3    = (float)$r['nilai_harian_3'];
        $tug   = (float)$r['nilai_tugas'];
        $sts   = (float)$r['nilai_sts'];
        $kumu  = (float)($r['nilai_kumulatif'] ?? 0);

        if ($jenis === 'sts') {
            // nilai_akhir = kumulatif jika diisi, fallback ke sts (kompatibel data lama)
            $na_baru = round($kumu > 0 ? $kumu : $sts, 2);
        } else {
            // bulanan: rata-rata hanya dari komponen yang > 0 (intent guru)
            $komponen = array_filter([$h1, $h2, $h3, $tug], fn($v) => $v > 0);
            $na_baru  = count($komponen) > 0
                ? round(array_sum($komponen) / count($komponen), 2)
                : 0;
        }

        $na_lama = round((float)$r['nilai_akhir'], 2);
        if ($na_baru == $na_lama) { $unchanged++; continue; }

        // Hitung predikat baru
        if ($na_baru >= $krit_a)      $pred = 'A';
        elseif ($na_baru >= $krit_b)  $pred = 'B';
        elseif ($na_baru >= $krit_c)  $pred = 'C';
        else                          $pred = 'D';

        try {
            db_execute(
                'UPDATE nilai SET nilai_akhir = :na, predikat = :pred WHERE id = :id',
                [':na' => $na_baru, ':pred' => $pred, ':id' => $r['id']]
            );
            $updated++;
        } catch (\Exception $e) {
            $errors[] = "ID {$r['id']}: " . $e->getMessage();
        }
    }

    flash('success', "Recalculate selesai: {$updated} diperbarui, {$unchanged} tidak berubah."
        . (count($errors) ? ' ' . count($errors) . ' error.' : ''));
    header('Location: /tools.recalc_nilai'); exit;
}

// ── Preview: hitung berapa yang akan berubah ──────────────────
$preview_rows = db_query(
    "SELECT n.id, u.nama_lengkap, m.nama_mapel, k.nama_kelas,
            ta.nama as ta_nama, ta.semester,
            n.jenis_raport, n.bulan,
            n.nilai_harian_1, n.nilai_harian_2, n.nilai_harian_3,
            n.nilai_tugas, n.nilai_sts, n.nilai_kumulatif, n.nilai_akhir as na_lama
     FROM nilai n
     LEFT JOIN users u ON u.id = n.siswa_user_id
     LEFT JOIN mapel m ON m.id = n.mapel_id
     LEFT JOIN kelas k ON k.id = n.kelas_id
     LEFT JOIN tahun_ajaran ta ON ta.id = n.tahun_ajaran_id
     WHERE n.jenis_raport IN ('bulanan','sts')
     ORDER BY ta.id DESC, k.nama_kelas, u.nama_lengkap, m.nama_mapel"
);

$akan_berubah = 0;
foreach ($preview_rows as &$pr) {
    $h1   = (float)$pr['nilai_harian_1'];
    $h2   = (float)$pr['nilai_harian_2'];
    $h3   = (float)$pr['nilai_harian_3'];
    $tug  = (float)$pr['nilai_tugas'];
    $sts  = (float)$pr['nilai_sts'];
    $kumu = (float)($pr['nilai_kumulatif'] ?? 0);

    if ($pr['jenis_raport'] === 'sts') {
        $na_baru = round($kumu > 0 ? $kumu : $sts, 2);
    } else {
        $komponen = array_filter([$h1, $h2, $h3, $tug], fn($v) => $v > 0);
        $na_baru  = count($komponen) > 0
            ? round(array_sum($komponen) / count($komponen), 2)
            : 0;
    }
    $pr['na_baru']  = $na_baru;
    $pr['berubah']  = (round((float)$pr['na_lama'], 2) != $na_baru);
    if ($pr['berubah']) $akan_berubah++;
}
unset($pr);
?>
<div class="page-content" style="max-width:960px">
    <div class="page-header">
        <div>
            <h1 class="page-title">Recalculate Nilai Akhir</h1>
            <p class="page-subtitle">Hitung ulang nilai_akhir semua data bulanan sesuai komponen yang terisi (&gt; 0)</p>
        </div>
    </div>

    <?php if ($akan_berubah === 0): ?>
    <div class="alert alert-success">Semua nilai_akhir sudah konsisten — tidak ada yang perlu diperbarui.</div>
    <?php else: ?>
    <div class="alert alert-warning">
        Ditemukan <strong><?= $akan_berubah ?></strong> record yang nilai_akhir-nya tidak konsisten dengan komponen nilainya.
        Klik <strong>Jalankan Recalculate</strong> untuk memperbaiki.
    </div>

    <form method="POST" action="/tools.recalc_nilai"
          onsubmit="return confirm('Yakin menjalankan recalculate untuk <?= $akan_berubah ?> record? Pastikan sudah backup DB.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="recalc">
        <button type="submit" class="btn btn-primary mb-4" style="border-radius:30px">
            Jalankan Recalculate (<?= $akan_berubah ?> record)
        </button>
    </form>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <span>Preview — Hanya yang Akan Berubah</span>
            <span class="text-sm text-gray-400"><?= $akan_berubah ?> dari <?= count($preview_rows) ?> record</span>
        </div>
        <div class="table-container">
            <table class="data-table" style="font-size:.8rem">
                <thead><tr>
                    <th>Murid</th><th>Mapel</th><th>Kelas</th><th>TA/Sem</th>
                    <th>Jenis</th><th>NH1</th><th>NH2</th><th>NH3</th><th>Tugas</th>
                    <th>NA Lama</th><th>NA Baru</th>
                </tr></thead>
                <tbody>
                <?php foreach ($preview_rows as $pr): ?>
                <?php if (!$pr['berubah']) continue; ?>
                <tr style="background:#fef9c3">
                    <td><?= e($pr['nama_lengkap']) ?></td>
                    <td><?= e($pr['nama_mapel']) ?></td>
                    <td><?= e($pr['nama_kelas']) ?></td>
                    <td><?= e($pr['ta_nama']) ?>/<?= $pr['semester'] ?></td>
                    <td><?= e($pr['jenis_raport']) ?></td>
                    <td><?= number_format((float)$pr['nilai_harian_1'],1) ?></td>
                    <td><?= number_format((float)$pr['nilai_harian_2'],1) ?></td>
                    <td><?= number_format((float)$pr['nilai_harian_3'],1) ?></td>
                    <td><?= number_format((float)$pr['nilai_tugas'],1) ?></td>
                    <td class="text-red-600 font-bold"><?= number_format((float)$pr['na_lama'],2) ?></td>
                    <td class="text-green-700 font-bold"><?= number_format($pr['na_baru'],2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($akan_berubah === 0): ?>
                <tr><td colspan="11" class="text-center py-6 text-gray-400">Tidak ada perbedaan.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
