<?php
/**
 * pages/raport/raport_template.php
 * Template HTML Raport — sesuai format SMA IBNU HAJAR BOARDING SCHOOL
 *
 * Variabel tersedia dari raport_data.php:
 *   $siswa, $ta, $kelas, $nilai_list, $absensi,
 *   $wali_kelas, $kepsek, $peringkat, $total_siswa_kelas, $avg_nilai
 *   $jenis, $bulan
 */

$sekolah      = sekolah_info();
$bulan_names  = BULAN_ID;

// ── Tentukan logo yang akan dipakai ──────────────────────────
// Prioritas: 1) logoihbs.png di uploads/logos/  2) logo dari setting sekolah  3) placeholder
$logo_candidates = ['logoihbs.png', 'logo.png', 'logo.jpg', 'logo.webp'];
$logo_url  = '';
$logo_path = '';
// Cek file fisik di uploads/logos/
foreach ($logo_candidates as $lc) {
    if (file_exists(UPLOAD_DIR . 'logos/' . $lc)) {
        $logo_url  = UPLOAD_URL . 'logos/' . $lc;
        $logo_path = UPLOAD_DIR . 'logos/' . $lc;
        break;
    }
}
// Fallback ke logo dari setting sekolah di DB
if (!$logo_url && !empty($sekolah['logo'])) {
    $logo_url = UPLOAD_URL . htmlspecialchars($sekolah['logo']);
}
$semester_lbl = ($ta['semester'] ?? 1) == 1 ? 'Ganjil' : 'Genap';
$is_bulanan   = ($jenis === 'bulanan');
$is_sts       = ($jenis === 'sts');
$bulan_label  = (($is_bulanan || $is_sts) && $bulan) ? strtoupper($bulan_names[$bulan] ?? '') : '';

// ── Deteksi kelas Global (raport bahasa Inggris) ─────────────
// Kelas Global ditandai dengan nama kelas mengandung kata "Global" (case-insensitive)
$nama_kelas_raw = $siswa['nama_kelas'] ?? '';
$is_global      = stripos($nama_kelas_raw, 'global') !== false;

// ── Label bilingual ───────────────────────────────────────────
// Digunakan di seluruh template: jika $is_global pakai EN, jika tidak pakai ID
$lbl = $is_global ? [
    'judul_bulanan'   => 'MONTHLY REPORT (PERIOD: %s)',
    'judul_sts'       => 'MID-SEMESTER REPORT%s',
    'judul_semester'  => 'SEMESTER %s ACADEMIC REPORT',
    'tahun_pelajaran' => 'ACADEMIC YEAR %s',
    'nama_siswa'      => 'Name of Student',
    'nis_nisn'        => 'NIS / NISN',
    'kelas'           => 'Grade',
    'kelas_peminatan' => 'Grade/Program',
    'semester'        => 'Semester',
    'semester_ganjil' => 'Odd',
    'semester_genap'  => 'Even',
    'peringkat'       => 'Class Rank',
    'dari'            => 'of',
    'siswa'           => 'students',
    'mata_pelajaran'  => 'Lesson Subject',
    'angka'           => 'Number',
    'deskripsi'       => 'Description',
    'kelompok'        => 'Group',          // "Group A", "Group B", dll
    'kelompok_c_pil'  => 'Group C (Elective)',
    'kelompok_d'      => 'Group D',
    'rata_rata'       => 'Average',
    'kriteria'        => 'Criteria',
    'ketidakhadiran'  => 'ABSENCES',
    'sakit'           => 'Sick',
    'izin'            => 'Permission',
    'tanpa_ket'       => 'Unexcused',
    'hari'            => 'Days',
    'catatan_walas'   => 'HOMEROOM TEACHER\'S NOTES',
    'mengetahui'      => 'Acknowledged by,',
    'kepala_sekolah'  => 'Principal of SMA IHBS',
    'wali_kelas'      => 'Homeroom Teacher',
    'tgl_format'      => 'en',             // format tanggal bahasa Inggris
] : [
    'judul_bulanan'   => 'LAPORAN BULANAN (PERIODE : %s)',
    'judul_sts'       => 'LAPORAN SUMATIF TENGAH SEMESTER%s',
    'judul_semester'  => 'LAPORAN HASIL BELAJAR SEMESTER %s',
    'tahun_pelajaran' => 'TAHUN PELAJARAN %s',
    'nama_siswa'      => 'Nama Siswa',
    'nis_nisn'        => 'NIS / NISN',
    'kelas'           => 'Kelas',
    'kelas_peminatan' => 'Kelas/Perminatan',
    'semester'        => 'Semester',
    'semester_ganjil' => 'Ganjil',
    'semester_genap'  => 'Genap',
    'peringkat'       => 'Peringkat Kelas',
    'dari'            => 'dari',
    'siswa'           => 'siswa',
    'mata_pelajaran'  => 'MATA PELAJARAN',
    'angka'           => 'Angka',
    'deskripsi'       => 'Deskripsi',
    'kelompok'        => 'Kelompok',
    'kelompok_c_pil'  => 'Kelompok C (Mapel Pilihan)',
    'kelompok_d'      => 'Kelompok D',
    'rata_rata'       => 'Rata-rata',
    'kriteria'        => 'Kriteria',
    'ketidakhadiran'  => 'KETIDAKHADIRAN',
    'sakit'           => 'Sakit',
    'izin'            => 'Izin',
    'tanpa_ket'       => 'Tanpa Keterangan',
    'hari'            => 'Hari',
    'catatan_walas'   => 'CATATAN WALI KELAS',
    'mengetahui'      => 'Mengetahui,',
    'kepala_sekolah'  => 'Kepala SMA IHBS',
    'wali_kelas'      => 'Wali Kelas',
    'tgl_format'      => 'id',
];
$semester_lbl_loc = ($ta['semester'] ?? 1) == 1 ? $lbl['semester_ganjil'] : $lbl['semester_genap'];

// Kriteria nilai dari setting sekolah
$krit_a = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c = (float)($sekolah['kriteria_c_min'] ?? 76);

// Hitung predikat berdasarkan kriteria sekolah
// Guard: template bisa di-include berkali-kali (cetak kelas = 1 include per siswa)
if (!function_exists('predikat_lokal')) {
    function predikat_lokal(float $n, float $a, float $b, float $c): string {
        if ($n >= $a) return 'A';
        if ($n >= $b) return 'B';
        if ($n >= $c) return 'C';
        return 'D';
    }
}

/*
 * Susun nilai_list menjadi hierarki:
 * mapel induk → [sub-mapel list atau langsung tampil]
 *
 * Jika mapel punya parent_id → sub-mapel (tampil indented di bawah induk)
 * Jika parent_id = null        → mapel mandiri (tampil langsung)
 *
 * Strategi: buat dua pass:
 *  1. kumpulkan semua nilai per mapel_id
 *  2. susun urutan: induk → sub-mapel-nya → mapel lain
 */
$nilai_by_mapel = [];
foreach ($nilai_list as $n) {
    $nilai_by_mapel[$n['mapel_id']] = $n;
}

$mapel_ids = array_column($nilai_list, 'mapel_id');
$ordered_rows = [];

if (!empty($mapel_ids)) {
    // ── Pass 1: ambil info mapel beserta parent ──────────────────────────────
    $in = implode(',', array_map('intval', $mapel_ids));
    $mapel_info_raw = db_query(
        "SELECT m.*, p.nama_mapel as parent_nama, p.kelompok as parent_kelompok,
                p.urutan as parent_urutan
         FROM mapel m
         LEFT JOIN mapel p ON p.id = m.parent_id
         WHERE m.id IN ($in)
         ORDER BY m.kelompok, m.urutan, m.nama_mapel"
    );

    // ── Pass 2: kumpulkan anak-anak per parent ───────────────────────────────
    // Kita perlu parent_id → daftar anak secara berurutan.
    // Juga kita perlu info mapel induk (nama, kelompok, urutan) agar bisa
    // menyisipkan parent_header di posisi yang BENAR dalam urutan global.

    $children_by_parent = [];   // parent_id  => [child_mi, ...]
    $singles            = [];   // [mi, ...]  — mapel tanpa parent
    $parent_meta        = [];   // parent_id  => ['nama'=>.., 'kelompok'=>.., 'urutan'=>..]

    foreach ($mapel_info_raw as $mi) {
        if ($mi['parent_id'] !== null) {
            $pid = (int)$mi['parent_id'];
            $children_by_parent[$pid][] = $mi;
            if (!isset($parent_meta[$pid])) {
                $parent_meta[$pid] = [
                    'kelompok' => $mi['parent_kelompok'] ?? $mi['kelompok'],
                    'urutan'   => (int)($mi['parent_urutan'] ?? 0),
                    'nama'     => $mi['parent_nama'] ?? '',
                ];
            }
        } else {
            $singles[] = $mi;
        }
    }

    // ── Pass 3: bangun daftar "slot" yang akan dirender, terurut ────────────
    // Setiap slot adalah: ['kelompok'=>, 'urutan'=>, 'type'=>'single'|'parent_group', 'data'=>...]
    $slots = [];

    foreach ($singles as $mi) {
        if (!isset($nilai_by_mapel[$mi['id']])) continue;
        $slots[] = [
            'kelompok' => $mi['kelompok'],
            'urutan'   => (int)($mi['urutan'] ?? 0),
            'type'     => 'single',
            'mi'       => $mi,
        ];
    }

    foreach ($children_by_parent as $pid => $children) {
        $pm = $parent_meta[$pid];
        $slots[] = [
            'kelompok' => $pm['kelompok'],
            'urutan'   => $pm['urutan'],
            'type'     => 'parent_group',
            'pid'      => $pid,
            'parent_nama' => $pm['nama'],
            'children' => $children,  // sudah terurut dari query
        ];
    }

    // Urutkan slot berdasarkan kelompok → urutan
    usort($slots, function($a, $b) {
        $kg = strcmp($a['kelompok'] ?? '', $b['kelompok'] ?? '');
        if ($kg !== 0) return $kg;
        return $a['urutan'] <=> $b['urutan'];
    });

    // ── Pass 4: flatten slot ke ordered_rows (dengan group header) ──────────
    $kelompok_prev = null;

    foreach ($slots as $slot) {
        // Header kelompok
        if (($slot['kelompok'] ?? '') !== $kelompok_prev) {
            $kelompok_prev = $slot['kelompok'] ?? '';
            $ordered_rows[] = ['type' => 'group', 'kelompok' => $kelompok_prev];
        }

        if ($slot['type'] === 'single') {
            $ordered_rows[] = [
                'type'       => 'single',
                'nilai'      => $nilai_by_mapel[$slot['mi']['id']],
                'mapel_info' => $slot['mi'],
            ];
        } else {
            // parent_group: header dulu, lalu semua anak berurutan
            $ordered_rows[] = [
                'type'       => 'parent_header',
                'mapel_id'   => $slot['pid'],
                'nama_mapel' => $slot['parent_nama'],
            ];
            foreach ($slot['children'] as $cmi) {
                if (!isset($nilai_by_mapel[$cmi['id']])) continue;
                $ordered_rows[] = [
                    'type'       => 'child',
                    'sub_label'  => $cmi['sub_label'] ?? '',
                    'nilai'      => $nilai_by_mapel[$cmi['id']],
                    'mapel_info' => $cmi,
                ];
            }
        }
    }
} else {
    // Tidak ada nilai — tetap tampilkan tabel kosong
}

// Nomor urut global untuk baris induk & mandiri
$row_no = 0;
?>

<!-- ══════════════════════════════════════════════════════════
     RAPORT PAGE — cetak A4 portrait
     ══════════════════════════════════════════════════════════ -->
<div class="raport-page" id="raportContent">

  <!-- ── HEADER SEKOLAH ────────────────────────────────────── -->
  <table style="width:100%;border-bottom:3px solid #000;padding-bottom:8px;margin-bottom:6px">
    <tr>
      <!-- Logo -->
      <td style="width:80px;vertical-align:middle;text-align:center">
        <?php if ($logo_url): ?>
          <img src="<?= $logo_url ?>"
               alt="Logo" style="width:70px;height:70px;object-fit:contain">
        <?php else: ?>
          <!-- Placeholder logo jika file tidak ada -->
          <div style="width:70px;height:70px;border:2px solid #333;border-radius:50%;
                      display:flex;align-items:center;justify-content:center;
                      margin:0 auto;font-size:9pt;font-weight:700;color:#333;text-align:center;
                      line-height:1.2">
            LOGO
          </div>
        <?php endif; ?>
      </td>
      <!-- Info sekolah -->
      <td style="vertical-align:middle;text-align:center;padding:0 10px">
        <?php if ($sekolah['nama_yayasan']): ?>
          <div style="font-size:11pt;font-weight:600;font-family:'Times New Roman',serif">
            <?= htmlspecialchars($sekolah['nama_yayasan']) ?>
          </div>
        <?php endif; ?>
        <div style="font-size:18pt;font-weight:900;font-family:'Times New Roman',serif;
                    text-transform:uppercase;line-height:1.1;margin:2px 0">
          <?= htmlspecialchars($sekolah['nama_sekolah']) ?>
        </div>
        <div style="font-size:9pt;font-family:'Times New Roman',serif">
          <?= htmlspecialchars($sekolah['alamat']) ?>
        </div>
        <div style="font-size:9pt;font-family:'Times New Roman',serif">
          Telp./Fax: <?= htmlspecialchars($sekolah['telp']) ?>
          <?php if ($sekolah['website']): ?>
            | Website: <span style="color:#1a56db"><?= htmlspecialchars($sekolah['website']) ?></span>
          <?php endif; ?>
          <?php if ($sekolah['email']): ?>
            | E-mail: <span style="color:#1a56db"><?= htmlspecialchars($sekolah['email']) ?></span>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  </table>

  <!-- ── JUDUL RAPORT ──────────────────────────────────────── -->
  <div style="text-align:center;margin:10px 0 4px;font-family:'Times New Roman',serif">
    <div style="font-size:13pt;font-weight:700;text-transform:uppercase;letter-spacing:.5px">
      <?php if ($is_bulanan): ?>
        <?= sprintf($lbl['judul_bulanan'], $bulan_label) ?>
      <?php elseif ($is_sts): ?>
        <?= sprintf($lbl['judul_sts'], $bulan_label ? ' — ' . $bulan_label : '') ?>
      <?php else: ?>
        <?= sprintf($lbl['judul_semester'], strtoupper($semester_lbl_loc)) ?>
      <?php endif; ?>
    </div>
    <div style="font-size:12pt;font-weight:700;text-transform:uppercase">
      <?= sprintf($lbl['tahun_pelajaran'], htmlspecialchars($ta['nama'] ?? '')) ?>
    </div>
  </div>

  <!-- ── IDENTITAS SISWA ───────────────────────────────────── -->
  <table style="width:60%;font-size:10.5pt;margin:10px 0 12px;
                font-family:'Times New Roman',serif;border-collapse:collapse">
    <tr>
      <td style="width:160px;padding:1px 0;font-weight:600"><?= $lbl['nama_siswa'] ?></td>
      <td style="width:12px">:</td>
      <td style="font-weight:600"><?= htmlspecialchars($siswa['nama_lengkap'] ?? '') ?></td>
    </tr>
    <?php if (!$is_bulanan && ($siswa['nis'] || $siswa['nisn'])): ?>
    <tr>
      <td style="padding:1px 0;font-weight:600"><?= $lbl['nis_nisn'] ?></td>
      <td>:</td>
      <td><?= htmlspecialchars(($siswa['nis'] ?? '') . ($siswa['nisn'] ? ' / ' . $siswa['nisn'] : '')) ?></td>
    </tr>
    <?php elseif ($is_global && $siswa['nisn']): ?>
    <tr>
      <td style="padding:1px 0;font-weight:600">NISN</td>
      <td>:</td>
      <td><?= htmlspecialchars($siswa['nisn'] ?? '') ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td style="padding:1px 0;font-weight:600"><?= $is_bulanan ? $lbl['kelas_peminatan'] : $lbl['kelas'] ?></td>
      <td>:</td>
      <td><?= htmlspecialchars($siswa['nama_kelas'] ?? '') ?></td>
    </tr>
    <tr>
      <td style="padding:1px 0;font-weight:600"><?= $lbl['semester'] ?></td>
      <td>:</td>
      <td><?= $semester_lbl_loc ?></td>
    </tr>
    <?php if ($is_global && $is_bulanan): ?>
    <tr>
      <td style="padding:1px 0;font-weight:600">Performance Indicators</td>
      <td>:</td>
      <td><?= (int)$krit_c ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($peringkat !== '-' && !$is_bulanan): ?>
    <tr>
      <td style="padding:1px 0;font-weight:600"><?= $lbl['peringkat'] ?></td>
      <td>:</td>
      <td><?= htmlspecialchars($peringkat) ?> <?= $lbl['dari'] ?> <?= $total_siswa_kelas ?> <?= $lbl['siswa'] ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <!-- ── TABEL NILAI ───────────────────────────────────────── -->
  <table class="raport-nilai-table" style="font-family:'Times New Roman',serif;font-size:10pt">
    <thead>
      <tr style="background:#fff">
        <!-- Kolom Mata Pelajaran: No(tanpa label) | sub | Nama — 5 kolom total (Pred dihapus) -->
        <th colspan="3" style="border:1px solid #000;padding:5px 8px;text-align:center;width:48%">
            <?= $lbl['mata_pelajaran'] ?>
          </th>
          <th colspan="2" style="border:1px solid #000;padding:5px 8px;text-align:center">
            <?= $is_global ? 'Cognitive Score' : 'NILAI PENGETAHUAN' ?>
          </th>
        </tr>
        <tr style="background:#fff">
          <th style="border:1px solid #000;padding:4px 6px;width:26px;text-align:center"></th>
          <th style="border:1px solid #000;padding:4px 6px;width:18px;text-align:center"></th>
          <th style="border:1px solid #000;padding:4px 6px;text-align:left"><?= $is_global ? 'Subject' : 'Nama Mata Pelajaran' ?></th>
          <th style="border:1px solid #000;padding:4px 8px;text-align:center;width:48px"><?= $lbl['angka'] ?></th>
          <th style="border:1px solid #000;padding:4px 8px;text-align:center"><?= $lbl['deskripsi'] ?></th>
      </tr>
    </thead>
    <tbody>

    <?php foreach ($ordered_rows as $row): ?>

      <?php if ($row['type'] === 'group'): ?>
        <!-- ── Baris Header Kelompok — bilingual, label khusus untuk C Pilihan & D ── -->
        <?php
          $kg = $row['kelompok'] ?? '';
          if ($kg === 'C Pilihan') {
              $kg_label = $lbl['kelompok_c_pil'];
          } elseif ($kg === 'D') {
              $kg_label = $lbl['kelompok_d'];
          } elseif ($kg === 'Muatan Lokal') {
              $kg_label = $is_global ? 'Local Content' : 'Muatan Lokal';
          } else {
              $kg_label = $lbl['kelompok'] . ' ' . $kg;
          }
        ?>
        <tr style="background:#f0f0f0">
          <td colspan="5" style="border:1px solid #000;padding:4px 8px;font-weight:700">
            <?= htmlspecialchars($kg_label) ?>
          </td>
        </tr>

      <?php elseif ($row['type'] === 'parent_header'): ?>
        <!-- ── Baris Induk Mapel — nomor tampil, Pred dihapus ── -->
        <?php $row_no++; ?>
        <tr>
          <td style="border:1px solid #000;padding:4px 6px;text-align:center;
                     vertical-align:top;font-weight:700"><?= $row_no ?></td>
          <td style="border:1px solid #000;padding:4px 6px"></td>
          <td style="border:1px solid #000;padding:4px 8px;font-weight:700">
            <?= htmlspecialchars($row['nama_mapel']) ?>:
          </td>
          <td style="border:1px solid #000;padding:4px 6px"></td>
          <td style="border:1px solid #000;padding:4px 8px"></td>
        </tr>

      <?php elseif ($row['type'] === 'child'): ?>
        <!-- ── Baris Sub-Mapel — kolom Pred dihapus ── -->
        <?php
          $n    = $row['nilai'];
          $na   = (float)($n['nilai_akhir'] ?? 0);
          // ?? hanya cek null — pakai ?: agar fallback jika string kosong
          $desk = trim($n['deskripsi'] ?? '') ?: trim($n['catatan'] ?? '');
        ?>
        <tr>
          <td style="border:1px solid #000;padding:4px 6px;text-align:center"></td>
          <td style="border:1px solid #000;padding:4px 6px;text-align:center;font-weight:600">
            <?= htmlspecialchars($row['sub_label'] ?? '') ?>
          </td>
          <td style="border:1px solid #000;padding:4px 8px">
            <?= htmlspecialchars($n['nama_mapel'] ?? '') ?>
          </td>
          <td style="border:1px solid #000;padding:4px 6px;text-align:center;font-weight:700">
            <?= $na > 0 ? number_format($na, 0) : '' ?>
          </td>
          <td style="border:1px solid #000;padding:4px 8px;font-size:9.5pt">
            <?= htmlspecialchars($desk) ?>
          </td>
        </tr>

      <?php elseif ($row['type'] === 'single'): ?>
        <!-- ── Baris Mapel Mandiri — kolom Pred dihapus ── -->
        <?php
          $n    = $row['nilai'];
          $na   = (float)($n['nilai_akhir'] ?? 0);
          // ?? hanya cek null — pakai ?: agar fallback jika string kosong
          $desk = trim($n['deskripsi'] ?? '') ?: trim($n['catatan'] ?? '');
          $row_no++;
        ?>
        <tr>
          <td style="border:1px solid #000;padding:4px 6px;text-align:center;font-weight:700">
            <?= $row_no ?>
          </td>
          <td style="border:1px solid #000;padding:4px 6px"></td>
          <td style="border:1px solid #000;padding:4px 8px;font-weight:700">
            <?= htmlspecialchars($n['nama_mapel'] ?? '') ?>
          </td>
          <td style="border:1px solid #000;padding:4px 6px;text-align:center;font-weight:700">
            <?= $na > 0 ? number_format($na, 0) : '' ?>
          </td>
          <td style="border:1px solid #000;padding:4px 8px;font-size:9.5pt">
            <?= htmlspecialchars($desk) ?>
          </td>
        </tr>

      <?php endif; ?>
    <?php endforeach; ?>

    <?php if (empty($ordered_rows)): ?>
      <tr>
        <td colspan="5" style="border:1px solid #000;padding:14px;text-align:center;
                                color:#666;font-style:italic">
          Belum ada data nilai untuk periode ini.
        </td>
      </tr>
    <?php endif; ?>

    <!-- ── Baris Rata-rata — colspan disesuaikan 5 kolom ── -->
    <tr style="font-weight:700;background:#f9f9f9">
      <td colspan="3" style="border:1px solid #000;padding:5px 8px;text-align:center">
        <?= $lbl['rata_rata'] ?>
      </td>
      <td style="border:1px solid #000;padding:5px 8px;text-align:center">
        <?= $avg_nilai > 0 ? number_format($avg_nilai, 2) : '' ?>
      </td>
      <td style="border:1px solid #000;padding:5px 8px"></td>
    </tr>

    </tbody>
  </table>

  <!-- ══════════════════════════════════════════════════════════
       BLOK BAWAH: Kriteria + Absensi + Catatan + TTD
       Dibungkus agar tidak terpotong page break
       ══════════════════════════════════════════════════════════ -->
  <div class="raport-footer-block">

  <!-- ── KRITERIA PENILAIAN ────────────────────────────────── -->
  <div style="margin-top:8px;font-family:'Times New Roman',serif;font-size:9.5pt">
    <table style="border-collapse:collapse;border:1px solid #000">
      <tr>
        <td style="padding:4px 10px;font-style:italic;vertical-align:top;
                   font-weight:600;white-space:nowrap">
          <?= $lbl['kriteria'] ?> :
        </td>
        <td style="padding:4px 14px;font-style:italic;line-height:1.7">
          A : <?= (int)$krit_a ?> - 100<br>
          B : <?= (int)$krit_b ?> - <?= (int)$krit_a - 1 ?><br>
          C : <?= (int)$krit_c ?> - <?= (int)$krit_b - 1 ?><br>
          D : &lt; <?= (int)$krit_c ?>
        </td>
      </tr>
    </table>
  </div>

  <?php if (!$is_bulanan): ?>
  <!-- ── KETIDAKHADIRAN — hanya untuk STS & Semester ── -->
  <div style="margin-top:10px;font-family:'Times New Roman',serif;font-size:10pt">
    <table style="border:1px solid #000;border-collapse:collapse;width:auto">
      <thead>
        <tr style="background:#f0f0f0">
          <th colspan="6" style="border:1px solid #000;padding:4px 10px;text-align:center;font-weight:700">
            <?= $lbl['ketidakhadiran'] ?>
          </th>
        </tr>
        <tr>
          <th style="border:1px solid #000;padding:3px 10px;text-align:center;font-size:9pt"><?= $lbl['sakit'] ?></th>
          <th style="border:1px solid #000;padding:3px 6px;font-size:9pt"><?= $lbl['hari'] ?></th>
          <th style="border:1px solid #000;padding:3px 10px;text-align:center;font-size:9pt"><?= $lbl['izin'] ?></th>
          <th style="border:1px solid #000;padding:3px 6px;font-size:9pt"><?= $lbl['hari'] ?></th>
          <th style="border:1px solid #000;padding:3px 10px;text-align:center;font-size:9pt"><?= $lbl['tanpa_ket'] ?></th>
          <th style="border:1px solid #000;padding:3px 6px;font-size:9pt"><?= $lbl['hari'] ?></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="border:1px solid #000;padding:4px 10px;text-align:center"></td>
          <td style="border:1px solid #000;padding:4px 10px;text-align:center;font-weight:700">
            <?= (int)($absensi['sakit'] ?? 0) ?>
          </td>
          <td style="border:1px solid #000;padding:4px 10px;text-align:center"></td>
          <td style="border:1px solid #000;padding:4px 10px;text-align:center;font-weight:700">
            <?= (int)($absensi['izin'] ?? 0) ?>
          </td>
          <td style="border:1px solid #000;padding:4px 10px;text-align:center"></td>
          <td style="border:1px solid #000;padding:4px 10px;text-align:center;font-weight:700">
            <?= (int)($absensi['alpa'] ?? 0) ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- ── CATATAN WALI KELAS — hanya untuk STS & Semester ─── -->
  <div style="margin-top:10px;font-family:'Times New Roman',serif;font-size:10pt">
    <table style="width:100%;border:1px solid #000;border-collapse:collapse">
      <tr style="background:#f0f0f0">
        <th style="border:1px solid #000;padding:4px 10px;text-align:left;font-weight:700;font-size:9.5pt">
          <?= $lbl['catatan_walas'] ?>
        </th>
      </tr>
      <tr>
        <td style="border:1px solid #000;padding:8px 10px;min-height:40px;height:50px;
                   font-size:9.5pt;vertical-align:top">
          <?php
            $catatan_walas = '';
            foreach ($nilai_list as $nv) {
                if (!empty($nv['catatan'])) { $catatan_walas = $nv['catatan']; break; }
            }
            echo $catatan_walas ? htmlspecialchars($catatan_walas) : '&nbsp;';
          ?>
        </td>
      </tr>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── TANDA TANGAN ──────────────────────────────────────── -->
  <table style="width:100%;margin-top:20px;font-family:'Times New Roman',serif;font-size:10.5pt">
    <tr>
      <!-- Kepala Sekolah — KIRI -->
      <td style="width:45%;vertical-align:top;text-align:center">
        <div><?= $lbl['mengetahui'] ?></div>
        <div><?= $lbl['kepala_sekolah'] ?></div>
        <!-- Tanda tangan kepala sekolah -->
        <div style="height:<?= ($kepsek && $kepsek['tanda_tangan']) ? 'auto' : '70px' ?>;
                    margin:6px 0;display:flex;align-items:center;justify-content:center">
          <?php if ($kepsek && $kepsek['tanda_tangan']): ?>
            <img src="<?= UPLOAD_URL . htmlspecialchars($kepsek['tanda_tangan']) ?>"
                 alt="TTD Kepala Sekolah"
                 style="max-height:70px;max-width:160px;object-fit:contain;display:block;margin:0 auto">
          <?php endif; ?>
        </div>
        <div style="font-weight:700">
          <?= htmlspecialchars($kepsek['nama_lengkap'] ?? '__________________________') ?>
        </div>
      </td>

      <!-- Spacer tengah -->
      <td style="width:10%"></td>

      <!-- Wali Kelas — KANAN -->
      <td style="width:45%;vertical-align:top;text-align:center">
        <!-- Tanggal + Kota -->
        <?php
          // Tentukan tanggal cetak:
          // Prioritas: $tgl_override (dari ?tgl= di URL) → fallback ke hari ini
          // $tgl_override diset oleh print.php / print_kelas.php (format Y-m-d)
          $tgl_ts = isset($tgl_override) && $tgl_override
              ? strtotime($tgl_override)
              : time();

          $bulan_id_map = [
              1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
              7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
          ];
          $bulan_en_map = [
              1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
              7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'
          ];
          $day_n = (int)date('j', $tgl_ts);
          $mon_n = (int)date('n', $tgl_ts);
          $yr_n  = date('Y', $tgl_ts);
          if ($is_global) {
              // English date: "Depok, 19th February on 2026"
              $ord = ($day_n % 10 === 1 && $day_n !== 11) ? 'st'
                   : (($day_n % 10 === 2 && $day_n !== 12) ? 'nd'
                   : (($day_n % 10 === 3 && $day_n !== 13) ? 'rd' : 'th'));
              $tgl_cetak = ($sekolah['kota'] ?? 'Depok') . ', ' . $day_n . $ord . ' ' . ($bulan_en_map[$mon_n] ?? '') . ' on ' . $yr_n;
          } else {
              $tgl_cetak = ($sekolah['kota'] ?? 'Depok') . ', '
                  . $day_n . ' '
                  . ($bulan_id_map[$mon_n] ?? date('F', $tgl_ts))
                  . ' ' . $yr_n;
          }
        ?>
        <div><?= htmlspecialchars($tgl_cetak) ?></div>
        <div><?= $lbl['wali_kelas'] ?></div>
        <!-- Tanda tangan wali kelas -->
        <div style="height:<?= ($wali_kelas && $wali_kelas['tanda_tangan']) ? 'auto' : '70px' ?>;
                    margin:6px 0;display:flex;align-items:center;justify-content:center">
          <?php if ($wali_kelas && $wali_kelas['tanda_tangan']): ?>
            <img src="<?= UPLOAD_URL . htmlspecialchars($wali_kelas['tanda_tangan']) ?>"
                 alt="TTD Wali Kelas"
                 style="max-height:70px;max-width:160px;object-fit:contain;display:block;margin:0 auto">
          <?php endif; ?>
        </div>
        <div style="font-weight:700">
          <?= htmlspecialchars($wali_kelas['nama_lengkap'] ?? '__________________________') ?>
        </div>
      </td>
    </tr>
  </table>

  </div><!-- /.raport-footer-block -->

</div><!-- /.raport-page -->
