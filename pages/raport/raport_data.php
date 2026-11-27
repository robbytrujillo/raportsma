<?php
/**
 * pages/raport/raport_data.php
 * Shared data loader untuk raport &mdash; diinclude oleh view.php dan print.php
 * Ekspor variabel: $siswa, $ta, $kelas, $nilai_list, $absensi, $wali_kelas, $kepsek, $peringkat, $total_siswa_kelas
 */
require_once __DIR__ . '/../../db.php';

// Parameter sudah di-set oleh halaman pemanggil
// $siswa_uid, $ta_id, $jenis, $bulan

// Ambil data siswa & cari kelas yang sesuai tahun ajaran yang diminta.
// Prioritas: (1) kelas dari nilai yang ada di ta_id ini, (2) kelas siswa sekarang.
// Ini memastikan raport lama tetap tampil dengan nama kelas yang benar
// meskipun siswa sudah naik kelas / pindah kelas.
$siswa = db_row(
    'SELECT u.*, s.nis, s.nisn, s.kelas_id as kelas_id_sekarang,
            s.tanggal_lahir, s.tempat_lahir, s.alamat, s.nama_ayah, s.nama_ibu,
            ta.nama as ta_nama, ta.semester
     FROM users u
     INNER JOIN siswa s ON s.user_id = u.id
     INNER JOIN tahun_ajaran ta ON ta.id = :ta_id
     WHERE u.id = :uid',
    [':uid' => $siswa_uid, ':ta_id' => $ta_id]
);

if (!$siswa) return;

$ta = db_row('SELECT * FROM tahun_ajaran WHERE id = :id', [':id' => $ta_id]);

// Cari kelas historis: ambil kelas_id dari nilai siswa di tahun ajaran ini.
// Jika belum ada nilai (raport kosong), fallback ke kelas siswa sekarang.
$kelas_id_historis = db_row(
    'SELECT kelas_id FROM nilai
     WHERE siswa_user_id = :uid AND tahun_ajaran_id = :ta
     LIMIT 1',
    [':uid' => $siswa_uid, ':ta' => $ta_id]
)['kelas_id'] ?? $siswa['kelas_id_sekarang'];

$kelas = db_row('SELECT * FROM kelas WHERE id = :id', [':id' => $kelas_id_historis]);

// Tambahkan kelas_id dan nama_kelas ke array $siswa agar kompatibel dengan template raport
$siswa['kelas_id']   = $kelas_id_historis;
$siswa['nama_kelas'] = $kelas['nama_kelas'] ?? '-';
$siswa['wali_kelas_id'] = $kelas['wali_kelas_id'] ?? null;

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

// ── Tambahkan mapel dari penugasan guru yang belum ada nilainya ─────────────
// Patokan: guru_mapel_kelas (penugasan guru) untuk kelas & TA ini.
// Mapel yang sudah ditugaskan ke kelas SELALU muncul di raport,
// dengan nilai_akhir = 0 dan deskripsi kosong jika belum diisi guru.
$penugasan_mapel = db_query(
    "SELECT DISTINCT m.id as mapel_id, m.nama_mapel, m.kelompok, m.kode_mapel,
                     m.parent_id, m.sub_label, m.urutan
     FROM guru_mapel_kelas gmk
     INNER JOIN mapel m ON m.id = gmk.mapel_id
     WHERE gmk.kelas_id = :kid AND gmk.tahun_ajaran_id = :ta
     ORDER BY m.kelompok, m.urutan, m.nama_mapel",
    [':kid' => $kelas_id_historis, ':ta' => $ta_id]
);

// Index nilai yang sudah ada berdasarkan mapel_id agar pencarian O(1)
$mapel_id_terisi = array_flip(array_column($nilai_list, 'mapel_id'));

// Tambahkan baris kosong (nilai 0) untuk mapel yang belum diisi
$bulan_default = (in_array($jenis, ['bulanan','sts']) && $bulan) ? $bulan : null;
foreach ($penugasan_mapel as $pm) {
    if (isset($mapel_id_terisi[$pm['mapel_id']])) continue; // sudah ada nilainya
    $nilai_list[] = [
        'id'             => null,
        'siswa_user_id'  => $siswa_uid,
        'mapel_id'       => $pm['mapel_id'],
        'kelas_id'       => $kelas_id_historis,
        'tahun_ajaran_id'=> $ta_id,
        'guru_user_id'   => null,
        'jenis_raport'   => $jenis,
        'bulan'          => $bulan_default,
        'nilai_harian_1'  => 0,
        'nilai_harian_2'  => 0,
        'nilai_harian_3'  => 0,
        'nilai_tugas'     => 0,
        'nilai_sts'       => 0,
        'nilai_kumulatif' => 0,
        'nilai_sas'       => 0,
        'nilai_akhir'    => 0,
        'predikat'       => null,
        'catatan'        => '',
        'deskripsi'      => '',
        // kolom dari mapel
        'nama_mapel'     => $pm['nama_mapel'],
        'kelompok'       => $pm['kelompok'],
        'kode_mapel'     => $pm['kode_mapel'],
        'parent_id'      => $pm['parent_id'],
        'sub_label'      => $pm['sub_label'],
        'urutan'         => $pm['urutan'],
    ];
}

// Urutkan ulang: kelompok → urutan → nama_mapel
usort($nilai_list, function($a, $b) {
    $kg = strcmp($a['kelompok'] ?? '', $b['kelompok'] ?? '');
    if ($kg !== 0) return $kg;
    $ur = ($a['urutan'] ?? 0) <=> ($b['urutan'] ?? 0);
    if ($ur !== 0) return $ur;
    return strcmp($a['nama_mapel'] ?? '', $b['nama_mapel'] ?? '');
});

// ── Hapus duplikat Kelompok C (non-pilihan) yang juga muncul di C Pilihan ──────
// Jika ada mapel dengan kelompok = 'C' DAN nama_mapel yang sama juga ada di
// 'C Pilihan', hapus yang kelompok = 'C' agar tidak muncul dua kali di raport.
$nama_c_pilihan = [];
foreach ($nilai_list as $row) {
    if (($row['kelompok'] ?? '') === 'C Pilihan') {
        $nama_c_pilihan[strtolower(trim($row['nama_mapel'] ?? ''))] = true;
    }
}
if (!empty($nama_c_pilihan)) {
    $nilai_list = array_values(array_filter($nilai_list, function($row) use ($nama_c_pilihan) {
        return !(
            ($row['kelompok'] ?? '') === 'C' &&
            isset($nama_c_pilihan[strtolower(trim($row['nama_mapel'] ?? ''))])
        );
    }));
}

// Absensi
$absensi = db_row(
    'SELECT * FROM absensi WHERE siswa_user_id = :uid AND kelas_id = :kid AND tahun_ajaran_id = :ta',
    [':uid'=>$siswa_uid, ':kid'=>$siswa['kelas_id'], ':ta'=>$ta_id]
) ?? ['sakit'=>0,'izin'=>0,'alpa'=>0];

// Wali kelas
$wali_kelas = $kelas['wali_kelas_id']
    ? db_row('SELECT * FROM users WHERE id = :id', [':id'=>$kelas['wali_kelas_id']])
    : null;

// Kepala sekolah — pilih berdasarkan unit kelas
// Untuk kelas putri: cari user kepsek putri, atau gunakan info dari sekolah_info
$kelas_unit = $kelas['unit'] ?? 'campuran';
if ($kelas_unit === 'putri') {
    // Coba cari user dengan nama yang sesuai kepala_sekolah_putri di sekolah_info
    $sekolah_si = db_row('SELECT * FROM sekolah_info ORDER BY id LIMIT 1');
    $kepsek_putri_nama = $sekolah_si['kepala_sekolah_putri'] ?? null;
    if ($kepsek_putri_nama) {
        // Cari user dengan nama itu (role kepsek) — fallback ke kepsek utama jika tidak ketemu
        $kepsek = db_row('SELECT * FROM users WHERE role = "kepala_sekolah" AND nama_lengkap = :nm LIMIT 1',
            [':nm' => $kepsek_putri_nama]);
        if (!$kepsek) {
            // Buat pseudo-array dengan nama saja (tanpa TTD user)
            $kepsek = [
                'nama_lengkap'  => $kepsek_putri_nama,
                'nip'           => $sekolah_si['nip_kepala_putri'] ?? '',
                'tanda_tangan'  => null,
            ];
        }
    } else {
        $kepsek = db_row('SELECT * FROM users WHERE role = "kepala_sekolah" LIMIT 1');
    }
} else {
    $kepsek = db_row('SELECT * FROM users WHERE role = "kepala_sekolah" LIMIT 1');
    // Jika sekolah_info punya kepala_sekolah (nama teks), override nama jika user belum diisi
    $sekolah_si = db_row('SELECT kepala_sekolah, nip_kepala FROM sekolah_info ORDER BY id LIMIT 1');
    if (!$kepsek && !empty($sekolah_si['kepala_sekolah'])) {
        $kepsek = [
            'nama_lengkap' => $sekolah_si['kepala_sekolah'],
            'nip'          => $sekolah_si['nip_kepala'] ?? '',
            'tanda_tangan' => null,
        ];
    }
}

// Peringkat kelas — gunakan n.kelas_id (historis, bukan siswa.kelas_id sekarang)
// agar peringkat tetap akurat meski siswa sudah naik kelas.
$rank_data = db_query(
    'SELECT n.siswa_user_id as user_id, ROUND(AVG(n.nilai_akhir),2) as avg
     FROM nilai n
     WHERE n.kelas_id = :kid AND n.tahun_ajaran_id = :ta AND n.jenis_raport = :jenis
     GROUP BY n.siswa_user_id ORDER BY avg DESC',
    [':kid'=>$kelas_id_historis, ':ta'=>$ta_id, ':jenis'=>$jenis]
);

$peringkat         = '-';
$total_siswa_kelas = count($rank_data);
foreach ($rank_data as $i => $r) {
    if ($r['user_id'] == $siswa_uid) { $peringkat = $i + 1; break; }
}

// Rata-rata nilai — hanya dari baris yang sudah benar-benar diisi guru (id !== null).
// Baris placeholder dari penugasan (id = null) tidak ikut dihitung agar rata-rata
// tidak terdistorsi oleh mapel yang belum diinput nilainya.
$nilai_terisi = array_filter($nilai_list, fn($r) => $r['id'] !== null);
$avg_nilai = count($nilai_terisi) > 0
    ? round(array_sum(array_column($nilai_terisi, 'nilai_akhir')) / count($nilai_terisi), 2)
    : 0;
