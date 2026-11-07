<?php
/**
 * pages/raport/view.php
 * Preview raport dalam layout aplikasi
 */
require_login();

$user_ses  = current_user();
$role      = $user_ses['role'];
$siswa_uid = (int)($_GET['siswa'] ?? 0);
$ta_id     = (int)($_GET['ta'] ?? 0);
$jenis     = $_GET['jenis'] ?? 'semester';
$bulan     = (int)($_GET['bulan'] ?? 0);

// Validasi akses: siswa hanya bisa lihat raport sendiri
if ($role === 'siswa' && $siswa_uid !== (int)$user_ses['id']) {
    http_response_code(403); echo '<div class="page-content"><div class="alert alert-danger">Akses ditolak.</div></div>'; return;
}

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/raport_data.php'; // Ambil data raport

if (!$siswa) {
    echo '<div class="page-content"><div class="alert alert-danger">Siswa tidak ditemukan.</div></div>'; return;
}

// Gate: siswa hanya bisa lihat jika raport sudah dipublikasi
if ($role === 'siswa') {
    $pub_bulan_v = (in_array($jenis,['bulanan','sts']) && $bulan) ? $bulan : null;
    $pub_check = db_row(
        'SELECT is_published FROM raport_publikasi
         WHERE kelas_id=:k AND tahun_ajaran_id=:t AND jenis_raport=:j
           AND (bulan=:b OR (bulan IS NULL AND :b2 IS NULL))',
        [':k'=>$siswa['kelas_id'],':t'=>$ta_id,':j'=>$jenis,':b'=>$pub_bulan_v,':b2'=>$pub_bulan_v]
    );
    if (!$pub_check || !(int)$pub_check['is_published']) {
        echo '<div class="page-content">
            <div class="card" style="max-width:480px;margin:40px auto">
                <div class="card-body" style="text-align:center;padding:40px 24px">
                    <svg width="56" height="56" fill="none" stroke="#f97316" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 16px">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    <h2 style="font-size:1.25rem;font-weight:700;margin-bottom:8px;color:#1f2937">Raport Belum Dirilis</h2>
                    <p style="color:#6b7280;margin-bottom:20px">Raport ini sedang dalam proses penerbitan oleh sekolah. Silakan cek kembali nanti.</p>
                    <a href="index.php?page=raport" class="btn btn-secondary">&larr; Kembali ke Raport</a>
                </div>
            </div>
        </div>'; return;
    }
}
?>

<?php
// ── Include raport variables needed by raport_template ──────
// raport_data.php already included above and sets: $siswa, $ta, $nilai_rows, etc.
// We also need sekolah info (same as print.php uses)
$sekolah_v   = sekolah_info();
$tgl_override = date('Y-m-d');
$ukuran      = 'a4';
$embed       = false;
$paper = ['w'=>'210mm','h'=>'297mm','pad_v'=>'10mm','pad_h'=>'15mm','size_css'=>'A4'];
$mm2px = 3.7795;
$pw_px = round(210 * $mm2px);
?>

<div class="page-content">
    <!-- Header preview -->
    <div class="preview-raport-header">
        <div class="preview-title">
            <h1>Preview Raport</h1>
            <p><?= e($siswa['nama_lengkap']) ?> &mdash; <?= e($ta['nama'] ?? '') ?></p>
        </div>
        <div class="preview-actions">
            <a href="javascript:history.back()" class="btn btn-secondary" style="border-radius:30px">&larr; Kembali</a>
            <a href="index.php?page=raport.print&siswa=<?= $siswa_uid ?>&ta=<?= $ta_id ?>&jenis=<?= urlencode($jenis) ?>&bulan=<?= $bulan ?>"
               target="_blank" class="btn btn-primary" style="border-radius:30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Cetak / PDF
            </a>
        </div>
    </div>

    <!-- ── Preview container: scale-to-fit, no iframe ── -->
    <div id="previewOuter" style="width:100%;overflow:hidden;margin:0 auto">
      <div id="previewWrap" style="transform-origin:top left">
        <style>
          #previewWrap .raport-page {
            width: 210mm;
            padding: 10mm 15mm;
            background: #fff;
            margin: 0 auto;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
            box-sizing: border-box;
            border-radius: 4px;
          }
          #previewWrap .no-print { display: none !important; }
          @media print { #previewOuter, #previewWrap { all: unset; } }

          /* ── Preview mode: sembunyikan sidebar, beri full width ─ */
          body.preview-raport-mode .sidebar {
            display: none !important;
          }
          body.preview-raport-mode .main-content {
            margin-left: 0 !important;
          }
          /* Perkecil padding page-content di preview mode */
          body.preview-raport-mode .page-content {
            padding: 16px 12px !important;
          }
          /* Tombol aksi di preview header lebih rapi */
          .preview-raport-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
          }
          .preview-raport-header .preview-title { min-width: 0; }
          .preview-raport-header .preview-title h1 {
            font-size: 1.1rem; font-weight: 700; margin: 0; color: #1f2937;
          }
          .preview-raport-header .preview-title p {
            font-size: .8rem; color: #6b7280; margin: 2px 0 0;
          }
          .preview-raport-header .preview-actions {
            display: flex; gap: 8px; flex-wrap: wrap; flex-shrink: 0;
          }
          @media (max-width: 480px) {
            .preview-raport-header { flex-direction: column; align-items: flex-start; }
            .preview-raport-header .preview-actions { width: 100%; }
            .preview-raport-header .preview-actions a { flex: 1; justify-content: center; }
          }
        </style>
        <?php include __DIR__ . '/raport_template.php'; ?>
      </div>
    </div>
</div>

<script>
(function () {
  // ── 1. Aktifkan preview mode: sembunyikan sidebar ─────────────
  document.body.classList.add('preview-raport-mode');

  var NATURAL_W = <?= $pw_px ?>; // 210mm @ 96 dpi

  function scalePreview() {
    var outer  = document.getElementById('previewOuter');
    var wrap   = document.getElementById('previewWrap');
    if (!outer || !wrap) return;

    // Reset supaya pengukuran natural akurat
    wrap.style.transform = '';
    wrap.style.width     = '';
    outer.style.height   = '';

    var availW = outer.getBoundingClientRect().width;
    if (!availW || availW <= 0) return;

    var pageEl   = wrap.querySelector('.raport-page');
    var naturalW = pageEl ? pageEl.getBoundingClientRect().width : NATURAL_W;
    if (!naturalW || naturalW <= 0) naturalW = NATURAL_W;

    if (availW >= naturalW) return; // cukup lebar, tidak perlu scale

    var scale    = availW / naturalW;
    wrap.style.transformOrigin = 'top left';
    wrap.style.transform       = 'scale(' + scale + ')';
    wrap.style.width           = naturalW + 'px';

    // Koreksi tinggi outer agar konten di bawah tidak tertimpa
    var wrappedH = wrap.scrollHeight;
    outer.style.height = Math.ceil(wrappedH * scale) + 'px';
  }

  window.addEventListener('load',   scalePreview);
  window.addEventListener('resize', scalePreview);
  document.addEventListener('DOMContentLoaded', function () {
    scalePreview();
    setTimeout(scalePreview, 400);
  });
  if (window.ResizeObserver) {
    var outer = document.getElementById('previewOuter');
    if (outer) new ResizeObserver(function(){ scalePreview(); }).observe(outer);
  }
})();
</script>