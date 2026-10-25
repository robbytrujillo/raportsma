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
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Preview Raport</h1>
            <p class="page-subtitle"><?= e($siswa['nama_lengkap']) ?> — <?= e($ta['nama'] ?? '') ?></p>
        </div>
        <div class="flex gap-2">
            <a href="javascript:history.back()" class="btn btn-secondary">← Kembali</a>
            <a href="index.php?page=raport.print&siswa=<?= $siswa_uid ?>&ta=<?= $ta_id ?>&jenis=<?= $jenis ?>&bulan=<?= $bulan ?>"
                target="_blank" class="btn btn-primary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="6 9 6 2 18 2 18 9" />
                    <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" />
                    <rect x="6" y="14" width="12" height="8" />
                </svg>
                Cetak / PDF
            </a>
        </div>
    </div>

    <div class="card" style="max-width:880px;margin:0 auto">
        <div class="card-body" style="padding:0">
            <!-- Embed raport content dalam preview -->
            <?php include __DIR__ . '/raport_template.php'; ?>
        </div>
    </div>
</div>