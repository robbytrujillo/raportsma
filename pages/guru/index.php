<?php
/**
 * pages/guru/index.php
 * Daftar Guru — admin saja
 */
require_role('admin');
require_once __DIR__ . '/../../excel.php';

// ── Export CSV ─────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $rows = db_query(
        "SELECT u.nama_lengkap, u.username, u.email, g.nip, g.status, g.bidang_studi
         FROM users u
         LEFT JOIN guru g ON g.user_id = u.id
         WHERE u.role IN ('guru','wali_kelas') AND u.is_active = 1
         ORDER BY u.nama_lengkap"
    );
    $headers = ['nama_lengkap','username','email','nip','status','bidang_studi'];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [$r['nama_lengkap'],$r['username'],$r['email'],
                  $r['nip'],$r['status'],$r['bidang_studi']];
    }
    csv_download('export_guru_' . date('Ymd') . '.csv', $headers, $out);
}

// ── Handle DELETE ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); }
    else {
        $id = (int)($_POST['id'] ?? 0);
        // Hapus user (CASCADE akan hapus data guru)
        db_execute('DELETE FROM users WHERE id = :id AND role IN ("guru","wali_kelas")', [':id' => $id]);
        flash('success', 'Data guru berhasil dihapus.');
    }
    header('Location: index.php?page=guru'); exit;
}

$search = trim($_GET['q'] ?? '');
$params = [];
$where  = "u.role IN ('guru','wali_kelas')";
if ($search) {
    $where .= ' AND (u.nama_lengkap LIKE :q OR u.username LIKE :q OR g.nip LIKE :q)';
    $params[':q'] = "%$search%";
}

// ── Paginasi ────────────────────────────────────────────────────
$page_n = max(1, (int)($_GET['p'] ?? 1));
$per    = 20;
$offset = ($page_n - 1) * $per;

$total = (int)(db_row(
    "SELECT COUNT(*) as n FROM users u LEFT JOIN guru g ON g.user_id = u.id WHERE $where",
    $params
)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));

$guru_list = db_query(
    "SELECT u.id, u.nama_lengkap, u.username, u.email, u.is_active, u.tanda_tangan,
            g.id as guru_id, g.nip, g.status, g.bidang_studi
     FROM users u
     LEFT JOIN guru g ON g.user_id = u.id
     WHERE $where ORDER BY u.nama_lengkap
     LIMIT $per OFFSET $offset",
    $params
);

$status_map = ['guru'=>['Guru','badge-blue'], 'wali_kelas'=>['Wali Kelas','badge-orange'], 'koordinator'=>['Koordinator','badge-purple']];
$qstr = http_build_query(['page'=>'guru','q'=>$search]);
?>
<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Data Guru</h1>
      <p class="page-subtitle">Kelola data guru dan wali kelas</p>
    </div>
    <div class="flex gap-2">
      <a href="index.php?page=guru&export=1" class="btn btn-secondary">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
          <polyline points="7 10 12 15 17 10"/>
          <line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        Export CSV
      </a>
      <a href="index.php?page=guru.import" class="btn btn-secondary">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        Import CSV
      </a>
      <a href="index.php?page=guru.create" class="btn btn-primary">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Tambah Guru
      </a>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <form method="GET" action="index.php" class="flex gap-2 flex-wrap">
        <input type="hidden" name="page" value="guru">
        <input type="text" name="q" placeholder="Cari nama / NIP..." value="<?= e($search) ?>" class="form-control" style="max-width:240px">
        <button type="submit" class="btn btn-primary btn-sm">Cari</button>
        <?php if ($search): ?><a href="index.php?page=guru" class="btn btn-secondary btn-sm">Reset</a><?php endif; ?>
      </form>
      <span class="text-sm text-gray-500"><?= $total ?> guru</span>
    </div>

    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th><th>Nama Guru</th><th>NIP</th><th>Bidang Studi</th><th>Username</th><th>Status</th><th>TTD</th><th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($guru_list)): ?>
          <tr><td colspan="8" class="text-center py-8 text-gray-400">Belum ada data guru.</td></tr>
          <?php else: ?>
          <?php foreach ($guru_list as $i => $g): ?>
          <tr>
            <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
            <td>
              <div class="font-semibold"><?= e($g['nama_lengkap']) ?></div>
              <div class="text-xs text-gray-400"><?= e($g['email'] ?: '-') ?></div>
            </td>
            <td class="text-gray-500 text-xs"><?= e($g['nip'] ?: '-') ?></td>
            <td class="text-xs"><?= e($g['bidang_studi'] ?: '—') ?></td>
            <td class="text-gray-500"><?= e($g['username']) ?></td>
            <td>
              <?php [$lbl,$cls] = $status_map[$g['status'] ?? 'guru'] ?? ['Guru','badge-blue']; ?>
              <span class="badge <?= $cls ?>"><?= $lbl ?></span>
            </td>
            <td>
              <?php if ($g['tanda_tangan']): ?>
                <img src="<?= UPLOAD_URL . e($g['tanda_tangan']) ?>" alt="TTD" style="height:36px;object-fit:contain">
              <?php else: ?>
                <span class="text-gray-400 text-xs">Belum ada</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="flex gap-1">
                <a href="index.php?page=guru.edit&id=<?= $g['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <form method="POST" action="index.php?page=guru" style="display:inline"
                  onsubmit="return confirm('Hapus guru <?= e($g['nama_lengkap']) ?>?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $g['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginasi -->
    <?php if ($pages > 1): ?>
    <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100">
      <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages ?> (<?= $total ?> guru)</span>
      <div class="pagination">
        <a href="?<?= $qstr ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>">‹ Prev</a>
        <?php
          $start = max(1, $page_n - 3);
          $end   = min($pages, $page_n + 3);
          if ($start > 1): ?><a href="?<?= $qstr ?>&p=1" class="page-link">1</a><?php
            if ($start > 2): ?><span class="page-link disabled">…</span><?php endif;
          endif;
          for ($pi = $start; $pi <= $end; $pi++):
        ?>
        <a href="?<?= $qstr ?>&p=<?= $pi ?>" class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a>
        <?php endfor;
          if ($end < $pages):
            if ($end < $pages - 1): ?><span class="page-link disabled">…</span><?php endif;
            ?><a href="?<?= $qstr ?>&p=<?= $pages ?>" class="page-link"><?= $pages ?></a><?php
          endif;
        ?>
        <a href="?<?= $qstr ?>&p=<?= min($pages,$page_n+1) ?>" class="page-link <?= $page_n>=$pages?'disabled':'' ?>">Next ›</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
