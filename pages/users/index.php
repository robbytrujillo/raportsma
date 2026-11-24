<?php
/**
 * pages/users/index.php
 * Daftar semua pengguna &mdash; super_admin (CRUD penuh) atau admin (hanya lihat)
 */
require_login();
if (!is_admin_or_super()) { require_role('super_admin'); } // trigger 403

$can_crud = is_super_admin(); // hanya super_admin boleh tambah/edit/hapus

// -- Handle TOGGLE AKTIF (super_admin only) ---------------------
if ($can_crud && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); }
    else {
        $tid     = (int)($_POST['id'] ?? 0);
        if ($tid === (int)current_user()['id']) {
            flash('error', 'Tidak dapat mengubah status akun Anda sendiri.');
        } else {
            $cur     = (int)(db_row('SELECT is_active FROM users WHERE id=:id', [':id'=>$tid])['is_active'] ?? 1);
            $new_val = $cur ? 0 : 1;
            db_execute('UPDATE users SET is_active=:a WHERE id=:id', [':a'=>$new_val,':id'=>$tid]);
            flash('success', 'Status pengguna diubah menjadi ' . ($new_val ? 'Aktif' : 'Nonaktif') . '.');
        }
    }
    $back = http_build_query(['page'=>'users','q'=>$_POST['q']??'','role'=>$_POST['role']??'','p'=>$_POST['page_n']??1]);
    header("Location: index.php?$back"); exit;
}

// -- Handle DELETE (super_admin only) ---------------------------
if ($can_crud && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); }
    else {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)current_user()['id']) {
            flash('error', 'Tidak dapat menghapus akun Anda sendiri.');
        } else {
            db_execute('DELETE FROM users WHERE id = :id', [':id' => $id]);
            flash('success', 'Pengguna berhasil dihapus.');
        }
    }
    header('Location: /users'); exit;
}

// -- Filter & Paginasi ------------------------------------------
$search = trim($_GET['q'] ?? '');
$role_f = $_GET['role'] ?? '';
$page_n = max(1, (int)($_GET['p'] ?? 1));
$per    = 15;
$offset = ($page_n - 1) * $per;

$cur_user   = current_user();
$cur_role   = $cur_user['role'];

// Unit filter untuk admin (bukan super_admin)
// Admin putra hanya lihat: super_admin, kepsek, admin (semua — karena sedikit),
// guru/wali_kelas unit putra, siswa kelas unit putra
$unit_join  = '';
$unit_cond  = '';
$unit_params = [];
if ($cur_role === 'admin') {
    $admin_guru = db_row('SELECT unit FROM guru WHERE user_id = :uid', [':uid' => $cur_user['id']]);
    $admin_unit = $admin_guru['unit'] ?? 'putra';
    if ($admin_unit === 'putra' || $admin_unit === 'putri') {
        $unit_join = "LEFT JOIN guru g_uf ON g_uf.user_id = u.id
                      LEFT JOIN siswa s_uf ON s_uf.user_id = u.id
                      LEFT JOIN kelas k_uf ON k_uf.id = s_uf.kelas_id";
        $unit_cond = "AND (
            u.role IN ('super_admin','admin','kepala_sekolah')
            OR (u.role IN ('guru','wali_kelas') AND g_uf.unit = :u_unit)
            OR (u.role = 'siswa' AND k_uf.unit = :u_unit2)
        )";
        $unit_params = [':u_unit' => $admin_unit, ':u_unit2' => $admin_unit];
    }
}

$where = ['1=1'];
$params = [];
if ($search) {
    $where[]       = '(u.nama_lengkap LIKE :q1 OR u.username LIKE :q2 OR u.email LIKE :q3)';
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}
if ($role_f) {
    $where[] = 'u.role = :role';
    $params[':role'] = $role_f;
}
$whereStr = implode(' AND ', $where);
$allParams = array_merge($params, $unit_params);

// Gunakan alias u agar konsisten dengan JOIN
$total = (int)(db_row(
    "SELECT COUNT(DISTINCT u.id) as n FROM users u $unit_join WHERE $whereStr $unit_cond",
    $allParams
)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
$users = db_query(
    "SELECT DISTINCT u.* FROM users u $unit_join WHERE $whereStr $unit_cond
     ORDER BY u.nama_lengkap LIMIT $per OFFSET $offset",
    $allParams
);

$role_labels = [
    'super_admin'  => ['label'=>'Super Admin',  'class'=>'badge-red'],
    'admin'        => ['label'=>'Admin',         'class'=>'badge-orange'],
    'wali_kelas'   => ['label'=>'Wali Kelas',    'class'=>'badge-orange'],
    'guru'         => ['label'=>'Guru',           'class'=>'badge-blue'],
    'siswa'        => ['label'=>'Murid',          'class'=>'badge-green'],
    'kepala_sekolah'=>['label'=>'Kepala Sekolah', 'class'=>'badge-purple'],
];
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Manajemen Pengguna</h1>
            <p class="page-subtitle">
                <?= $can_crud ? 'Kelola akun seluruh pengguna sistem' : 'Lihat daftar pengguna sistem (hanya Super Admin yang dapat mengelola)' ?>
                <?php if (!empty($admin_unit) && $cur_role === 'admin'): ?>
                &mdash; <span class="badge <?= $admin_unit==='putra' ? 'badge-blue' : 'badge-orange' ?>"
                    style="font-size:.75rem">
                    Unit <?= ucfirst($admin_unit) ?>
                </span>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($can_crud): ?>
        <a href="/users.create" class="btn btn-primary" style="border-radius: 30px">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <line x1="12" y1="5" x2="12" y2="19" />
                <line x1="5" y1="12" x2="19" y2="12" />
            </svg>
            Tambah Pengguna
        </a>
        <?php endif; ?>
    </div>

    <?php if (!$can_crud): ?>
    <div class="alert alert-info" style="margin-bottom:16px">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
            style="flex-shrink:0">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
        Anda login sebagai <strong>Admin</strong>. Hanya <strong>Super Admin</strong> yang dapat menambah, mengedit,
        atau menghapus akun pengguna.
    </div>
    <?php endif; ?>

    <div class="card">
        <!-- Filter Bar -->
        <div class="card-header flex-wrap gap-3">
            <form method="GET" action="index.php" class="flex flex-wrap gap-2 items-center flex-1">
                <input type="hidden" name="page" value="users">
                <input type="text" name="q" placeholder="Cari nama / username..." value="<?= e($search) ?>"
                    class="form-control" style="max-width:220px; border-radius: 30px">
                <select name="role" class="form-control" style="max-width:160px; border-radius: 30px">
                    <option value="">Semua Role</option>
                    <?php foreach ($role_labels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $role_f===$k?'selected':'' ?>><?= $v['label'] ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm" style="border-radius: 30px">Filter</button>
                <?php if ($search || $role_f): ?>
                <a href="/users" class="btn btn-secondary btn-sm" style="border-radius: 30px">Reset</a>
                <?php endif; ?>
            </form>
            <span class="text-sm text-gray-500"><?= $total ?> pengguna</span>
        </div>

        <div class="table-container">
            <table class="data-table" id="dataTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama Lengkap</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <?php if ($can_crud): ?><th>Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="<?= $can_crud ? 7 : 6 ?>" class="text-center py-8 text-gray-400">Tidak ada data.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($users as $i => $u): ?>
                    <tr>
                        <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="avatar" style="width:32px;height:32px;font-size:.7rem">
                                    <?php if ($u['foto']): ?>
                                    <img src="<?= UPLOAD_URL . e($u['foto']) ?>" alt="">
                                    <?php else: ?>
                                    <?= strtoupper(substr($u['nama_lengkap'],0,1)) ?>
                                    <?php endif; ?>
                                </div>
                                <span class="font-semibold"><?= e($u['nama_lengkap']) ?></span>
                            </div>
                        </td>
                        <td class="text-gray-500"><?= e($u['username']) ?></td>
                        <td class="text-gray-500"><?= e($u['email'] ?: '-') ?></td>
                        <td>
                            <?php $rl = $role_labels[$u['role']] ?? ['label'=>$u['role'],'class'=>'badge-gray']; ?>
                            <span class="badge <?= $rl['class'] ?>"><?= $rl['label'] ?></span>
                        </td>
                        <td>
                            <span class="badge <?= $u['is_active'] ? 'badge-green' : 'badge-red' ?>">
                                <?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <?php if ($can_crud): ?>
                        <td>
                            <div class="flex gap-1">
                                <a href="/users.edit?id=<?= $u['id'] ?>" class="btn btn-sm btn-secondary tooltip-wrap"
                                    style="border-radius: 30px">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                                        viewBox="0 0 24 24">
                                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" />
                                        <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" />
                                    </svg>
                                    <span class="tooltip-text">Edit</span>
                                </a>
                                <?php if ($u['id'] != current_user()['id']): ?>
                                <!-- Tombol Toggle Aktif/Nonaktif -->
                                <form method="POST" action="/users" style="display:inline"
                                    onsubmit="return confirm('<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> pengguna <?= e($u['nama_lengkap']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="q" value="<?= e($search) ?>">
                                    <input type="hidden" name="role" value="<?= e($role_f) ?>">
                                    <button type="submit"
                                        class="btn btn-sm <?= $u['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                        style="border-radius:30px">
                                        <?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                                <form method="POST" action="/users" style="display:inline"
                                    onsubmit="return confirm('Hapus pengguna <?= e($u['nama_lengkap']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger tooltip-wrap"
                                        style="border-radius: 30px">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                                            viewBox="0 0 24 24">
                                            <polyline points="3 6 5 6 21 6" />
                                            <path d="M19 6l-1 14H6L5 6" />
                                            <path d="M10 11v6" />
                                            <path d="M14 11v6" />
                                            <path d="M9 6V4h6v2" />
                                        </svg>
                                        <span class="tooltip-text">Hapus</span>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1):
            // Windowed: tampilkan 2 halaman di kiri & kanan halaman aktif + first/last
            $pg_url = fn($p) => '?page=users&q='.urlencode($search).'&role='.$role_f.'&p='.$p;
            $win    = 2; // jumlah halaman di kiri & kanan aktif
            $start  = max(2, $page_n - $win);
            $end    = min($pages - 1, $page_n + $win);
        ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 pagination-wrap">
            <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages ?></span>
            <div class="pagination">
                <!-- Prev -->
                <a href="<?= $pg_url(max(1,$page_n-1)) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>"
                    style="border-radius: 30px;">&lsaquo; Prev</a>

                <!-- Halaman 1 selalu tampil -->
                <a href="<?= $pg_url(1) ?>" class="page-link <?= $page_n===1?'active':'' ?>">1</a>

                <!-- Ellipsis kiri -->
                <?php if ($start > 2): ?>
                <span class="page-link disabled" style="border:none;padding:6px 4px;cursor:default">…</span>
                <?php endif; ?>

                <!-- Window tengah -->
                <?php for ($pi = $start; $pi <= $end; $pi++): ?>
                <a href="<?= $pg_url($pi) ?>" class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a>
                <?php endfor; ?>

                <!-- Ellipsis kanan -->
                <?php if ($end < $pages - 1): ?>
                <span class="page-link disabled" style="border:none;padding:6px 4px;cursor:default">…</span>
                <?php endif; ?>

                <!-- Halaman terakhir selalu tampil -->
                <?php if ($pages > 1): ?>
                <a href="<?= $pg_url($pages) ?>" class="page-link <?= $page_n===$pages?'active':'' ?>"><?= $pages ?></a>
                <?php endif; ?>

                <!-- Next -->
                <a href="<?= $pg_url(min($pages,$page_n+1)) ?>" class="page-link <?= $page_n>=$pages?'disabled':'' ?>"
                    style="border-radius: 30px;">Next &rsaquo;</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>