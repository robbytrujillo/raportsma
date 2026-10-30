<?php
/**
 * pages/raport/print.php
 * Halaman cetak raport &mdash; standalone (tanpa layout)
 * Bisa diprint/save as PDF dari browser
 */
require_once __DIR__ . '/../../auth.php';
require_login();

// Auto-create raport_publikasi if not yet migrated
db()->exec("CREATE TABLE IF NOT EXISTS raport_publikasi (id INT AUTO_INCREMENT PRIMARY KEY, kelas_id INT NOT NULL, tahun_ajaran_id INT NOT NULL, jenis_raport ENUM('bulanan','sts','semester') NOT NULL, bulan TINYINT DEFAULT NULL, is_published TINYINT(1) NOT NULL DEFAULT 0, published_by INT DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_pub (kelas_id, tahun_ajaran_id, jenis_raport, bulan), FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE, FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran(id) ON DELETE CASCADE, FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$user_ses  = current_user();
$role      = $user_ses['role'];
$siswa_uid   = (int)($_GET['siswa'] ?? 0);
$ta_id       = (int)($_GET['ta'] ?? 0);
$jenis       = $_GET['jenis'] ?? 'semester';
$bulan       = (int)($_GET['bulan'] ?? 0);
// Tanggal cetak: dari ?tgl= (format Y-m-d), fallback ke hari ini
$tgl_raw     = $_GET['tgl'] ?? '';
$tgl_override = ($tgl_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_raw))
    ? $tgl_raw : date('Y-m-d');
// Ukuran kertas: a4 (default) atau f4
$ukuran = in_array($_GET['ukuran'] ?? '', ['a4','f4']) ? $_GET['ukuran'] : 'a4';
$paper  = $ukuran === 'f4'
    ? ['w'=>'215mm','h'=>'330mm','pad_v'=>'12mm','pad_h'=>'18mm','size_css'=>'215mm 330mm']
    : ['w'=>'210mm','h'=>'297mm','pad_v'=>'10mm','pad_h'=>'15mm','size_css'=>'A4'];

// Siswa hanya bisa cetak raport sendiri
if ($role === 'siswa' && $siswa_uid !== (int)$user_ses['id']) {
    http_response_code(403); echo 'Akses ditolak.'; exit;
}

require_once __DIR__ . '/raport_data.php';

if (!$siswa) {
    http_response_code(404); echo 'Siswa tidak ditemukan.'; exit;
}

// Gate: siswa hanya bisa cetak jika raport sudah dipublikasi
if ($role === 'siswa') {
    $pub_bulan_p = (in_array($jenis,['bulanan','sts']) && $bulan) ? $bulan : null;
    $pub_chk = db_row(
        'SELECT is_published FROM raport_publikasi
         WHERE kelas_id=:k AND tahun_ajaran_id=:t AND jenis_raport=:j
           AND (bulan=:b OR (bulan IS NULL AND :b2 IS NULL))',
        [':k'=>$siswa['kelas_id'],':t'=>$ta_id,':j'=>$jenis,':b'=>$pub_bulan_p,':b2'=>$pub_bulan_p]
    );
    if (!$pub_chk || !(int)$pub_chk['is_published']) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Raport Belum Dirilis</title>
        <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f3f4f6}
        .box{background:#fff;border-radius:16px;padding:40px 32px;text-align:center;max-width:420px;box-shadow:0 4px 24px rgba(0,0,0,.1)}
        h2{margin:12px 0 8px;color:#1f2937}p{color:#6b7280;margin-bottom:20px}
        a{display:inline-block;padding:8px 20px;background:#f97316;color:#fff;border-radius:8px;text-decoration:none}</style></head>
        <body><div class="box">
        <svg width="56" height="56" fill="none" stroke="#f97316" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <h2>Raport Belum Dirilis</h2>
        <p>Raport ini sedang dalam proses penerbitan oleh sekolah. Silakan cek kembali nanti.</p>
        <a href="javascript:history.back()">&larr; Kembali</a>
        </div></body></html>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raport &mdash; <?= htmlspecialchars($siswa['nama_lengkap']) ?></title>
    <!-- Favicon -->
    <?php if (!empty($sekolah_l['logo'])): ?>
    <link rel="icon" type="image/png" href="<?= UPLOAD_URL . e($sekolah_l['logo']) ?>">
    <link rel="shortcut icon" href="<?= UPLOAD_URL . e($sekolah_l['logo']) ?>">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='5' fill='%23f97316'/><path d='M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z' fill='none' stroke='white' stroke-width='2'/></svg>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/style.css?v=<?= filemtime(__DIR__.'/../../style.css') ?>">
    <style>
    /* ── Dimensi kertas (px pada 96dpi) ── */
    <?php
      // Konversi mm ke px (96dpi: 1mm = 3.7795px)
      $mm2px = 3.7795;
      $pw_px = round((float)$paper['w'] * $mm2px);   // lebar kertas px
      $ph_px = round((float)$paper['h'] * $mm2px);   // tinggi kertas px
    ?>
    :root {
      --paper-w-px:  <?= $pw_px ?>px;
      --paper-h-px:  <?= $ph_px ?>px;
      --paper-w:     <?= $paper['w'] ?>;
      --paper-h:     <?= $paper['h'] ?>;
      --paper-pad-v: <?= $paper['pad_v'] ?>;
      --paper-pad-h: <?= $paper['pad_h'] ?>;
    }

    body { background: #f3f4f6; margin: 0; padding-top: 60px; }

    .print-toolbar {
      position: fixed; top: 0; left: 0; right: 0; z-index: 999;
      background: #f97316; color: #fff; padding: 8px 20px;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0,0,0,.2); gap: 10px;
      font-family: 'Poppins', sans-serif;
    }
    .print-toolbar button, .print-toolbar a {
      background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.5);
      color: #fff; padding: 6px 14px; border-radius: 8px; cursor: pointer;
      font-size: .82rem; font-weight: 600; text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
    }
    .print-toolbar button:hover, .print-toolbar a:hover { background: rgba(255,255,255,.35); }

    .btn-paper-active {
      background: rgba(255,255,255,.9) !important;
      color: #f97316 !important; font-weight: 700 !important;
    }

    /* ── Preview: raport ditampilkan sesuai lebar kertas ── */
    .raport-page {
      width: var(--paper-w);
      /* No min-height — prevents blank extra page when printing */
      padding: var(--paper-pad-v) var(--paper-pad-h);
      background: #fff;
      margin: 20px auto;
      box-shadow: 0 4px 24px rgba(0,0,0,.12);
      box-sizing: border-box;
    }

    /* ── Print: paksa 1 halaman dengan zoom otomatis ── */
    @media print {
      body { background: #fff !important; padding-top: 0 !important; margin: 0 !important; }
      .print-toolbar { display: none !important; }

      /* Wrapper scale &mdash; JS akan set --print-scale sebelum print */
      #printScaleWrap {
        width: var(--paper-w);
        transform-origin: top left;
        transform: scale(var(--print-scale, 1));
      }

      .raport-page {
        width: var(--paper-w) !important;
        min-height: 0 !important;           /* biarkan natural height */
        padding: var(--paper-pad-v) var(--paper-pad-h) !important;
        margin: 0 !important;
        box-shadow: none !important;
        page-break-after: always;
        break-after: page;
      }

      @page { size: <?= $paper['size_css'] ?>; margin: 0; }
    }

    /* A4 zoom + tightening sudah ditangani style.css @media print body[data-paper="a4"] */
    </style>
</head>

<body data-paper="<?= $ukuran ?>">

    <!-- Toolbar (hilang saat print) -->
    <div class="print-toolbar no-print">
      <div style="font-weight:700;font-size:.95rem;white-space:nowrap">
        Raport &mdash; <?= htmlspecialchars($siswa['nama_lengkap']) ?> | <?= htmlspecialchars($ta['nama']??'') ?>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">

        <!-- Pilih ukuran kertas -->
        <div style="display:flex;align-items:center;gap:4px;background:rgba(0,0,0,.15);
                    border-radius:30px;padding:3px">
          <button onclick="setUkuran('a4')"
            class="<?= $ukuran==='a4' ? 'btn-paper-active' : '' ?>"
            style="border-radius:30px;padding:4px 12px;border:none"
            title="Kertas A4 &mdash; 210 × 297 mm">
            A4
          </button>
          <button onclick="setUkuran('f4')"
            class="<?= $ukuran==='f4' ? 'btn-paper-active' : '' ?>"
            style="border-radius:30px;padding:4px 12px;border:none"
            title="Kertas F4 &mdash; 215 × 330 mm">
            F4
          </button>
        </div>

        <!-- Date picker tanggal cetak -->
        <label style="display:flex;align-items:center;gap:5px;font-size:.8rem;font-weight:600">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
          </svg>
          Tgl:
          <input type="date" id="tglCetak" value="<?= htmlspecialchars($tgl_override) ?>"
            style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.5);
                   color:#fff;padding:3px 7px;border-radius:30px;font-size:.8rem;cursor:pointer"
            onchange="applyTglCetak(this.value)">
        </label>

        <a href="<?= BASE_URL ?>/index.php?page=raport&ta=<?= $ta_id ?>&jenis=<?= urlencode($jenis) ?>&bulan=<?= $bulan ?>"
           onclick="if(window.opener||history.length<=1){window.close();return false;}"
           style="border-radius:30px">&larr; Kembali</a>

        <button onclick="window.print()" style="border-radius:30px;background:rgba(255,255,255,.9);color:#f97316;font-weight:700">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <polyline points="6 9 6 2 18 2 18 9"/>
            <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
            <rect x="6" y="14" width="12" height="8"/>
          </svg>
          Cetak / PDF
        </button>
      </div>
    </div>
    <script>
    function applyTglCetak(val) {
      const url = new URL(window.location.href);
      url.searchParams.set('tgl', val);
      window.location.href = url.toString();
    }
    function setUkuran(u) {
      const url = new URL(window.location.href);
      url.searchParams.set('ukuran', u);
      window.location.href = url.toString();
    }
    </script>

    <!-- Konten Raport -->
    <?php include __DIR__ . '/raport_template.php'; ?>

</body>

</html>