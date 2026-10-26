<?php
/**
 * pages/raport/print.php
 * Halaman cetak raport — standalone (tanpa layout)
 * Bisa diprint/save as PDF dari browser
 */
require_once __DIR__ . '/../../auth.php';
require_login();

$user_ses  = current_user();
$role      = $user_ses['role'];
$siswa_uid = (int)($_GET['siswa'] ?? 0);
$ta_id     = (int)($_GET['ta'] ?? 0);
$jenis     = $_GET['jenis'] ?? 'semester';
$bulan     = (int)($_GET['bulan'] ?? 0);

// Siswa hanya bisa cetak raport sendiri
if ($role === 'siswa' && $siswa_uid !== (int)$user_ses['id']) {
    http_response_code(403); echo 'Akses ditolak.'; exit;
}

require_once __DIR__ . '/raport_data.php';

if (!$siswa) {
    http_response_code(404); echo 'Siswa tidak ditemukan.'; exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Raport — <?= htmlspecialchars($siswa['nama_lengkap']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/style.css">
  <style>
    body { background: #fff; margin: 0; }
    .print-toolbar {
      position: fixed; top: 0; left: 0; right: 0; z-index: 999;
      background: #f97316; color: #fff; padding: 10px 24px;
      display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
      font-family: 'Poppins', sans-serif;
    }
    .print-toolbar button, .print-toolbar a {
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.5);
      color: #fff; padding: 7px 16px; border-radius: 8px;
      cursor: pointer; font-size: .875rem; font-weight: 600;
      text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
    }
    .print-toolbar button:hover, .print-toolbar a:hover { background: rgba(255,255,255,0.35); }
    body { padding-top: 52px; }
    .raport-page { margin: 24px auto; box-shadow: 0 4px 24px rgba(0,0,0,0.12); }
    @media print {
      .print-toolbar { display: none !important; }
      body { padding-top: 0; }
      .raport-page { margin: 0; box-shadow: none; }
    }
  </style>
</head>
<body>

<!-- Toolbar (hilang saat print) -->
<div class="print-toolbar no-print">
  <div style="font-weight:700;font-size:1rem">
    Raport — <?= htmlspecialchars($siswa['nama_lengkap']) ?> | <?= htmlspecialchars($ta['nama']??'') ?>
  </div>
  <div style="display:flex;gap:8px">
    <a href="<?= BASE_URL ?>/index.php?page=raport&ta=<?= $ta_id ?>&jenis=<?= urlencode($jenis) ?>&bulan=<?= $bulan ?>"
       onclick="if(window.opener||history.length<=1){window.close();return false;}">← Kembali</a>
    <button onclick="window.print()">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polyline points="6 9 6 2 18 2 18 9"/>
        <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
        <rect x="6" y="14" width="12" height="8"/>
      </svg>
      Cetak / Simpan PDF
    </button>
  </div>
</div>

<!-- Konten Raport -->
<?php include __DIR__ . '/raport_template.php'; ?>

</body>
</html>
