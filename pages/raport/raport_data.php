<?php
/**
 * pages/raport/raport_data.php
 * Shared data loader untuk raport — diinclude oleh view.php dan print.php
 * Ekspor variabel: $siswa, $ta, $kelas, $nilai_list, $absensi, $wali_kelas, $kepsek, $peringkat, $total_siswa_kelas
 */
require_once __DIR__ . '/../../db.php';

// Parameter sudah di-set oleh halaman pemanggil
// $siswa_uid, $ta_id, $jenis, $bulan

$siswa = db_row(
    'SELECT u.*, s.nis, s.nisn, s.kelas_id, s.tanggal_lahir, s.tempat_lahir, s.alamat,
            s.nama_ayah, s.nama_ibu, k.nama_kelas, k.wali_kelas_id,
            ta.nama as ta_nama, ta.semester
     FROM users u
     INNER JOIN siswa s ON s.user_id = u.id
     INNER JOIN kelas k ON k.id = s.kelas_id
     INNER JOIN tahun_ajaran ta ON ta.id = :ta_id
     WHERE u.id = :uid',
    [':uid' => $siswa_uid, ':ta_id' => $ta_id]
);

if (!$siswa) return;

$ta     = db_row('SELECT * FROM tahun_ajaran WHERE id = :id', [':id' => $ta_id]);
$kelas  = db_row('SELECT * FROM kelas WHERE id = :id', [':id' => $siswa['kelas_id']]);

// Nilai berdasarkan jenis raport
$nilai_params = [':uid'=>$siswa_uid, ':ta'=>$ta_id, ':jenis'=>$jenis];
$bulan_cond   = '';
// Untuk jenis bulanan DAN sts, filter berdasarkan bulan jika disediakan
if (in_array($jenis, ['bulanan','sts']) && $bulan) {
    $bulan_cond = 'AND n.bulan = :bulan';
    $nilai_params[':bulan'] = $bulan;
}

$nilai_list = db_query(
    "SELECT n.*, m.nama_mapel, m.kelompok, m.kode_mapel, m.parent_id, m.sub_label, m.urutan
     FROM nilai n
     LEFT JOIN mapel m ON m.id = n.mapel_id
     WHERE n.siswa_user_id = :uid
       AND n.tahun_ajaran_id = :ta
       AND n.jenis_raport = :jenis
       $bulan_cond
     ORDER BY m.kelompok, m.urutan, m.nama_mapel",
    $nilai_params
);

// Absensi
$absensi = db_row(
    'SELECT * FROM absensi WHERE siswa_user_id = :uid AND kelas_id = :kid AND tahun_ajaran_id = :ta',
    [':uid'=>$siswa_uid, ':kid'=>$siswa['kelas_id'], ':ta'=>$ta_id]
) ?? ['sakit'=>0,'izin'=>0,'alpa'=>0];

// Wali kelas
$wali_kelas = $kelas['wali_kelas_id']
    ? db_row('SELECT * FROM users WHERE id = :id', [':id'=>$kelas['wali_kelas_id']])
    : null;

// Kepala sekolah
$kepsek = db_row('SELECT * FROM users WHERE role = "kepala_sekolah" LIMIT 1');

// Peringkat kelas
$rank_data = db_query(
    'SELECT sv.user_id, ROUND(AVG(n.nilai_akhir),2) as avg
     FROM siswa sv INNER JOIN nilai n ON n.siswa_user_id = sv.user_id
     WHERE sv.kelas_id = :kid AND n.tahun_ajaran_id = :ta AND n.jenis_raport = :jenis
     GROUP BY sv.user_id ORDER BY avg DESC',
    [':kid'=>$siswa['kelas_id'], ':ta'=>$ta_id, ':jenis'=>$jenis]
);

$peringkat         = '-';
$total_siswa_kelas = count($rank_data);
foreach ($rank_data as $i => $r) {
    if ($r['user_id'] == $siswa_uid) { $peringkat = $i + 1; break; }
}

// Rata-rata nilai
$avg_nilai = count($nilai_list) > 0
    ? round(array_sum(array_column($nilai_list, 'nilai_akhir')) / count($nilai_list), 2)
    : 0;
