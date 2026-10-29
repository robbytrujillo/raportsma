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
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Preview Raport</h1>
            <p class="page-subtitle"><?= e($siswa['nama_lengkap']) ?> &mdash; <?= e($ta['nama'] ?? '') ?></p>
        </div>
        <div class="flex gap-2">
            <a href="javascript:history.back()" class="btn btn-secondary" style="border-radius: 30px">&larr; Kembali</a>
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