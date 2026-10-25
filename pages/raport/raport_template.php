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

// Kriteria nilai dari setting sekolah
$krit_a = (float)($sekolah['kriteria_a_min'] ?? 92);
$krit_b = (float)($sekolah['kriteria_b_min'] ?? 83);
$krit_c = (float)($sekolah['kriteria_c_min'] ?? 76);

// Hitung predikat berdasarkan kriteria sekolah
function predikat_lokal(float $n, float $a, float $b, float $c): string {
    if ($n >= $a) return 'A';
    if ($n >= $b) return 'B';
    if ($n >= $c) return 'C';
    return 'D';
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

// Query mapel lengkap (termasuk yang tidak ada nilainya, untuk struktur tampilan)
// Kita pakai mapel yang ada nilai saja, tapi susun hierarchinya
$mapel_ids = array_column($nilai_list, 'mapel_id');

$ordered_rows = []; // array of ['type'=>'group'|'parent'|'child'|'single', 'data'=>...]

if (!empty($mapel_ids)) {
    // Ambil info mapel lengkap dengan info parent
    $in = implode(',', array_map('intval', $mapel_ids));
    $mapel_info = db_query(
        "SELECT m.*, p.nama_mapel as parent_nama
         FROM mapel m
         LEFT JOIN mapel p ON p.id = m.parent_id
         WHERE m.id IN ($in)
         ORDER BY m.kelompok, m.urutan, m.nama_mapel"
    );

    // Indeks mapel_info berdasarkan id
    $mapel_idx = [];
    foreach ($mapel_info as $mi) {
        $mapel_idx[$mi['id']] = $mi;
    }

    $kelompok_prev = null;
    $parent_printed = [];  // parent_id yang sudah ditampilkan header-nya

    foreach ($mapel_info as $mi) {
        // Group header (Kelompok A/B/C)
        if ($mi['kelompok'] !== $kelompok_prev) {
            $kelompok_prev = $mi['kelompok'];
            $ordered_rows[] = ['type' => 'group', 'kelompok' => $mi['kelompok']];
        }

        if ($mi['parent_id'] !== null) {
            // Sub-mapel: tampilkan parent-header jika belum
            $pid = $mi['parent_id'];
            if (!isset($parent_printed[$pid])) {
                $parent_printed[$pid] = true;
                $ordered_rows[] = [
                    'type'       => 'parent_header',
                    'mapel_id'   => $pid,
                    // Ambil nomor urut dari mapel induk (jika ada di nilai)
                    'nama_mapel' => isset($nilai_by_mapel[$pid])
                        ? $nilai_by_mapel[$pid]['nama_mapel']
                        : ($mi['parent_nama'] ?? ''),
                ];
            }
            // Baris sub-mapel
            if (isset($nilai_by_mapel[$mi['id']])) {
                $ordered_rows[] = [
                    'type'       => 'child',
                    'sub_label'  => $mi['sub_label'] ?? '',
                    'nilai'      => $nilai_by_mapel[$mi['id']],
                    'mapel_info' => $mi,
                ];
            }
        } else {
            // Mapel mandiri
            if (isset($nilai_by_mapel[$mi['id']])) {
                $ordered_rows[] = [
                    'type'  => 'single',
                    'nilai' => $nilai_by_mapel[$mi['id']],
                    'mapel_info' => $mi,
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
        LAPORAN BULANAN (PERIODE : <?= $bulan_label ?>)
      <?php elseif ($is_sts): ?>
        LAPORAN SUMATIF TENGAH SEMESTER<?= $bulan_label ? ' — ' . $bulan_label : '' ?>
      <?php else: ?>
        LAPORAN HASIL BELAJAR SEMESTER <?= strtoupper($semester_lbl) ?>
      <?php endif; ?>
    </div>
    <div style="font-size:12pt;font-weight:700;text-transform:uppercase">
      TAHUN PELAJARAN <?= htmlspecialchars($ta['nama'] ?? '') ?>
    </div>
  </div>

  <!-- ── IDENTITAS SISWA ───────────────────────────────────── -->
  <table style="width:60%;font-size:10.5pt;margin:10px 0 12px;
                font-family:'Times New Roman',serif;border-collapse:collapse">
    <tr>
      <td style="width:130px;padding:1px 0;font-weight:600">Nama Siswa</td>
      <td style="width:12px">:</td>
      <td style="font-weight:600"><?= htmlspecialchars($siswa['nama_lengkap'] ?? '') ?></td>
    </tr>
    <?php if (!$is_bulanan): ?>
    <tr>
      <td style="padding:1px 0;font-weight:600">NIS / NISN</td>
      <td>:</td>
      <td><?= htmlspecialchars(($siswa['nis'] ?? '') . ($siswa['nisn'] ? ' / ' . $siswa['nisn'] : '')) ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td style="padding:1px 0;font-weight:600">Kelas<?= $is_bulanan ? '/Perminatan' : '' ?></td>
      <td>:</td>
      <td><?= htmlspecialchars($siswa['nama_kelas'] ?? '') ?></td>
    </tr>
    <tr>
      <td style="padding:1px 0;font-weight:600">Semester</td>
      <td>:</td>
      <td><?= $semester_lbl ?></td>
    </tr>
    <?php if ($peringkat !== '-'): ?>
    <tr>
      <td style="padding:1px 0;font-weight:600">Peringkat Kelas</td>
      <td>:</td>
      <td><?= htmlspecialchars($peringkat) ?> dari <?= $total_siswa_kelas ?> siswa</td>
    </tr>
    <?php endif; ?>
  </table>

  <!-- ── TABEL NILAI ───────────────────────────────────────── -->
  <table class="raport-nilai-table" style="font-family:'Times New Roman',serif;font-size:10pt">
    <thead>
      <tr style="background:#fff">
        <!-- Kolom Mata Pelajaran: No(tanpa label) | sub | Nama — 5 kolom total (Pred dihapus) -->
        <th colspan="3" style="border:1px solid #000;padding:5px 8px;text-align:center;width:48%">
          MATA PELAJARAN
        </th>
        <th colspan="2" style="border:1px solid #000;padding:5px 8px;text-align:center">
          NILAI PENGETAHUAN
        </th>
      </tr>
      <tr style="background:#fff">
        <!-- No: header kosong, angka tetap tampil di tbody -->
        <th style="border:1px solid #000;padding:4px 6px;width:26px;text-align:center"></th>
        <th style="border:1px solid #000;padding:4px 6px;width:18px;text-align:center"></th>
        <th style="border:1px solid #000;padding:4px 6px;text-align:left">Nama Mata Pelajaran</th>
        <th style="border:1px solid #000;padding:4px 8px;text-align:center;width:48px">Angka</th>
        <th style="border:1px solid #000;padding:4px 8px;text-align:center">Deskripsi</th>
      </tr>
    </thead>
    <tbody>

    <?php foreach ($ordered_rows as $row): ?>

      <?php if ($row['type'] === 'group'): ?>
        <!-- ── Baris Header Kelompok (Kelompok A / B / C) ── -->
        <tr style="background:#f0f0f0">
          <td colspan="5" style="border:1px solid #000;padding:4px 8px;font-weight:700">
            Kelompok <?= htmlspecialchars($row['kelompok']) ?>
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
          $desk = $n['catatan'] ?? ($n['deskripsi'] ?? '');
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
          $desk = $n['catatan'] ?? ($n['deskripsi'] ?? '');
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
        Rata-rata
      </td>
      <td style="border:1px solid #000;padding:5px 8px;text-align:center">
        <?= $avg_nilai > 0 ? number_format($avg_nilai, 2) : '' ?>
      </td>
      <td style="border:1px solid #000;padding:5px 8px"></td>
    </tr>

    </tbody>
  </table>

  <!-- ── KRITERIA PENILAIAN ────────────────────────────────── -->
  <?php if ($is_bulanan): ?>
  <!-- Raport BULANAN: kriteria teks miring per baris seperti foto referensi -->
  <div style="margin-top:8px;font-family:'Times New Roman',serif;font-size:9.5pt;font-style:italic">
    <em>Kriteria :</em> A : <?= (int)$krit_a ?> - 100<br>
    <span style="margin-left:52px">B : <?= (int)$krit_b ?> - <?= (int)$krit_a - 1 ?></span><br>
    <span style="margin-left:52px">C : <?= (int)$krit_c ?> - <?= (int)$krit_b - 1 ?></span><br>
    <span style="margin-left:52px">D : &lt; <?= (int)$krit_c ?></span>
  </div>
  <?php else: ?>
  <!-- Raport STS/Semester: kriteria teks inline -->
  <div style="margin-top:8px;font-family:'Times New Roman',serif;font-size:9.5pt">
    <em>Keterangan Nilai :</em>&nbsp;&nbsp;
    A (<?= (int)$krit_a ?>–100) &nbsp;|&nbsp;
    B (<?= (int)$krit_b ?>–<?= (int)$krit_a - 1 ?>) &nbsp;|&nbsp;
    C (<?= (int)$krit_c ?>–<?= (int)$krit_b - 1 ?>) &nbsp;|&nbsp;
    D (&lt;<?= (int)$krit_c ?>)
  </div>

  <!-- ── KETIDAKHADIRAN (ABSENSI) — hanya untuk STS & Semester ── -->
  <div style="margin-top:10px;font-family:'Times New Roman',serif;font-size:10pt">
    <table style="border:1px solid #000;border-collapse:collapse;width:auto">
      <thead>
        <tr style="background:#f0f0f0">
          <th colspan="6" style="border:1px solid #000;padding:4px 10px;text-align:center;font-weight:700">
            KETIDAKHADIRAN
          </th>
        </tr>
        <tr>
          <th style="border:1px solid #000;padding:3px 10px;text-align:center;font-size:9pt">Sakit</th>
          <th style="border:1px solid #000;padding:3px 6px;font-size:9pt">Hari</th>
          <th style="border:1px solid #000;padding:3px 10px;text-align:center;font-size:9pt">Izin</th>
          <th style="border:1px solid #000;padding:3px 6px;font-size:9pt">Hari</th>
          <th style="border:1px solid #000;padding:3px 10px;text-align:center;font-size:9pt">Tanpa Keterangan</th>
          <th style="border:1px solid #000;padding:3px 6px;font-size:9pt">Hari</th>
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
          CATATAN WALI KELAS
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
        <div>Mengetahui,</div>
        <div>Kepala SMA IHBS</div>
        <!-- Tanda tangan kepala sekolah -->
        <div style="height:<?= ($kepsek && $kepsek['tanda_tangan']) ? 'auto' : '70px' ?>;
                    margin:6px 0;display:flex;align-items:center;justify-content:center">
          <?php if ($kepsek && $kepsek['tanda_tangan']): ?>
            <img src="<?= UPLOAD_URL . htmlspecialchars($kepsek['tanda_tangan']) ?>"
                 alt="TTD Kepala Sekolah"
                 style="max-height:70px;max-width:160px;object-fit:contain;display:block;margin:0 auto">
          <?php endif; ?>
        </div>
        <div style="font-weight:700;text-decoration:underline">
          <?= htmlspecialchars($kepsek['nama_lengkap'] ?? '__________________________') ?>
        </div>
      </td>

      <!-- Spacer tengah -->
      <td style="width:10%"></td>

      <!-- Wali Kelas — KANAN -->
      <td style="width:45%;vertical-align:top;text-align:center">
        <!-- Tanggal + Kota -->
        <?php
          // Tentukan tanggal cetak
          $tgl_cetak = '';
          if ($is_bulanan && $bulan) {
              // Hari terakhir bulan tersebut
              $tgl_cetak = $sekolah['kota'] . ', '
                         . date('j') . ' '
                         . ($bulan_names[$bulan] ?? '')
                         . ' ' . ($ta['nama'] ? substr($ta['nama'],0,4) : date('Y'));
          } else {
              $tgl_cetak = $sekolah['kota'] . ', ' . date('j F Y');
          }
        ?>
        <div><?= htmlspecialchars($tgl_cetak) ?></div>
        <div>Wali Kelas</div>
        <!-- Tanda tangan wali kelas -->
        <div style="height:<?= ($wali_kelas && $wali_kelas['tanda_tangan']) ? 'auto' : '70px' ?>;
                    margin:6px 0;display:flex;align-items:center;justify-content:center">
          <?php if ($wali_kelas && $wali_kelas['tanda_tangan']): ?>
            <img src="<?= UPLOAD_URL . htmlspecialchars($wali_kelas['tanda_tangan']) ?>"
                 alt="TTD Wali Kelas"
                 style="max-height:70px;max-width:160px;object-fit:contain;display:block;margin:0 auto">
          <?php endif; ?>
        </div>
        <div style="font-weight:700;text-decoration:underline">
          <?= htmlspecialchars($wali_kelas['nama_lengkap'] ?? '__________________________') ?>
        </div>
      </td>
    </tr>
  </table>

</div><!-- /.raport-page -->
