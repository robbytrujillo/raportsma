<?php
/**
 * pages/nilai/index.php
 * Daftar & input nilai — akses berdasarkan status guru di tabel guru:
 *   admin          → lihat semua, edit semua
 *   kepala_sekolah → lihat semua (read-only)
 *   guru biasa     → hanya kelas & mapel yang ditugaskan di guru_mapel_kelas
 *   wali_kelas     → semua mapel di kelas yang dipimpinnya + mapel yg diajarnya
 *   koordinator    → disamakan guru biasa untuk sementara
 */
require_role(['admin', 'guru', 'wali_kelas', 'kepala_sekolah']);
require_once __DIR__ . '/../../excel.php';

$user     = current_user();
$role     = $user['role'];   // dari tabel users (guru / wali_kelas / admin)
$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');

// Ambil status detail dari tabel guru (guru / wali_kelas / koordinator)
$guru_detail = db_row('SELECT * FROM guru WHERE user_id = :uid', [':uid' => $user['id']]);
$guru_status = $guru_detail['status'] ?? 'guru'; // default guru biasa

// Kelas yang dipimpin wali kelas ini (dari tabel kelas.wali_kelas_id)
$wali_kelas_ids = [];
if ($guru_status === 'wali_kelas') {
    $wk_rows = db_query(
        'SELECT id FROM kelas WHERE wali_kelas_id = :uid',
        [':uid' => $user['id']]
    );
    $wali_kelas_ids = array_column($wk_rows, 'id');
}

// Handle DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete') {
    if (verify_csrf()) {
        $nid = (int)$_POST['id'];
        if ($role === 'admin') {
            db_execute('DELETE FROM nilai WHERE id = :id', [':id'=>$nid]);
        } elseif ($guru_status === 'wali_kelas') {
            // Wali kelas bisa hapus nilai di kelasnya atau nilai yang dia input
            db_execute(
                'DELETE FROM nilai WHERE id = :id
                 AND (guru_user_id = :uid OR kelas_id IN (' . (empty($wali_kelas_ids) ? '0' : implode(',', $wali_kelas_ids)) . '))',
                [':id'=>$nid, ':uid'=>$user['id']]
            );
        } else {
            // Guru biasa hanya hapus nilai yang dia input
            db_execute('DELETE FROM nilai WHERE id = :id AND guru_user_id = :uid',
                [':id'=>$nid, ':uid'=>$user['id']]);
        }
        flash('success','Data nilai dihapus.');
    }
    header('Location: index.php?page=nilai'); exit;
}

// ── Filter ─────────────────────────────────────────────────────
$ta_id    = (int)($_GET['ta']    ?? ($ta_aktif['id'] ?? 0));
$kelas_id = (int)($_GET['kelas'] ?? 0);
$jenis    = $_GET['jenis'] ?? '';
$ta_list  = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');

// ── Kelas yang bisa dipilih ─────────────────────────────────────
if (in_array($role, ['admin','kepala_sekolah'])) {
    // Admin & kepala sekolah: semua kelas
    $kelas_list = db_query(
        'SELECT * FROM kelas WHERE tahun_ajaran_id = :ta ORDER BY nama_kelas',
        [':ta' => $ta_id]
    );
} elseif ($guru_status === 'wali_kelas') {
    // Wali kelas: kelas yang dipimpin + kelas yang diajar
    // :uid2 = nilai sama dengan :uid — PDO tidak boleh pakai named param duplikat
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         LEFT JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id AND gmk.guru_user_id = :uid
         WHERE k.tahun_ajaran_id = :ta
           AND (k.wali_kelas_id = :uid2 OR gmk.id IS NOT NULL)
         ORDER BY k.nama_kelas',
        [':uid' => $user['id'], ':uid2' => $user['id'], ':ta' => $ta_id]
    );
} else {
    // Guru biasa: hanya kelas yang diajar
    $kelas_list = db_query(
        'SELECT DISTINCT k.* FROM kelas k
         INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id = k.id
         WHERE gmk.guru_user_id = :uid AND gmk.tahun_ajaran_id = :ta
         ORDER BY k.nama_kelas',
        [':uid' => $user['id'], ':ta' => $ta_id]
    );
}

// ── Build query nilai ───────────────────────────────────────────
$where  = ['n.tahun_ajaran_id = :ta'];
$params = [':ta' => $ta_id];

if (in_array($role, ['admin','kepala_sekolah'])) {
    // Admin & kepala sekolah: lihat semua
} elseif ($guru_status === 'wali_kelas') {
    // Wali kelas: lihat semua nilai di kelas yang dipimpin ATAU nilai yang dia input sendiri
    if (!empty($wali_kelas_ids)) {
        $in = implode(',', array_map('intval', $wali_kelas_ids));
        $where[] = "(n.kelas_id IN ($in) OR n.guru_user_id = :uid)";
    } else {
        $where[] = 'n.guru_user_id = :uid';
    }
    $params[':uid'] = $user['id'];
} else {
    // Guru biasa: hanya nilai yang dia input
    $where[] = 'n.guru_user_id = :uid';
    $params[':uid'] = $user['id'];
}

if ($kelas_id) {
    $where[] = 'n.kelas_id = :kid';
    $params[':kid'] = $kelas_id;
}
if ($jenis) {
    $where[] = 'n.jenis_raport = :jenis';
    $params[':jenis'] = $jenis;
}

$whereStr = implode(' AND ', $where);

// ── Export CSV (filter-aware, ambil SEMUA tanpa LIMIT) ──────────
if (isset($_GET['export'])) {
    $exp_all = db_query(
        "SELECT n.*, u.nama_lengkap as nama_siswa, m.nama_mapel, k.nama_kelas, g.nama_lengkap as nama_guru
         FROM nilai n
         LEFT JOIN users u ON u.id = n.siswa_user_id
         LEFT JOIN mapel m ON m.id = n.mapel_id
         LEFT JOIN kelas k ON k.id = n.kelas_id
         LEFT JOIN users g ON g.id = n.guru_user_id
         WHERE $whereStr ORDER BY u.nama_lengkap, m.nama_mapel, n.bulan",
        $params
    );
    $exp_headers = ['nama_siswa','kelas','mapel','jenis_raport','bulan',
                    'nh1','nh2','nh3','tugas','sts','sas','nilai_akhir','predikat','catatan'];
    $exp_rows = [];
    foreach ($exp_all as $n) {
        $exp_rows[] = [
            $n['nama_siswa'], $n['nama_kelas'], $n['nama_mapel'],
            $n['jenis_raport'], $n['bulan'],
            $n['nilai_harian_1'], $n['nilai_harian_2'], $n['nilai_harian_3'],
            $n['nilai_tugas'], $n['nilai_sts'], $n['nilai_sas'],
            $n['nilai_akhir'], $n['predikat'], $n['catatan'],
        ];
    }
    csv_download('export_nilai_' . date('Ymd') . '.csv', $exp_headers, $exp_rows);
}

// ── Paginasi ────────────────────────────────────────────────────
$per_opts = [10, 25, 50, 100];
$per      = in_array((int)($_GET['per'] ?? 0), $per_opts) ? (int)$_GET['per'] : 10;
$page_n   = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page_n - 1) * $per;

$total = (int)(db_row(
    "SELECT COUNT(*) as n
     FROM nilai n
     LEFT JOIN users u ON u.id = n.siswa_user_id
     LEFT JOIN mapel m ON m.id = n.mapel_id
     LEFT JOIN kelas k ON k.id = n.kelas_id
     WHERE $whereStr",
    $params
)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
if ($page_n > $pages) $page_n = $pages;
$offset = ($page_n - 1) * $per;

$nilai_list = db_query(
    "SELECT n.*, u.nama_lengkap as nama_siswa, m.nama_mapel, k.nama_kelas, g.nama_lengkap as nama_guru
     FROM nilai n
     LEFT JOIN users u ON u.id = n.siswa_user_id
     LEFT JOIN mapel m ON m.id = n.mapel_id
     LEFT JOIN kelas k ON k.id = n.kelas_id
     LEFT JOIN users g ON g.id = n.guru_user_id
     WHERE $whereStr ORDER BY u.nama_lengkap, m.nama_mapel, n.bulan
     LIMIT $per OFFSET $offset",
    $params
);

// Query string untuk tautan paginasi (pertahankan semua filter)
$qstr_n = http_build_query(['page'=>'nilai','ta'=>$ta_id,'kelas'=>$kelas_id,'jenis'=>$jenis,'per'=>$per]);
?>
<div class="page-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Data Nilai</h1>
      <p class="page-subtitle">Input dan kelola nilai siswa</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="index.php?page=nilai&ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>&jenis=<?= e($jenis) ?>&export=1"
         class="btn btn-secondary">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
          <polyline points="7 10 12 15 17 10"/>
          <line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        Export CSV
      </a>
      <a href="index.php?page=nilai.import" class="btn btn-secondary">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        Import CSV
      </a>
      <?php if ($role !== 'kepala_sekolah'): ?>
      <a href="index.php?page=nilai.input" class="btn btn-primary">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Input Nilai
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter Bar -->
  <form method="GET" action="index.php" class="card mb-5">
    <div class="card-body">
      <input type="hidden" name="page" value="nilai">
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div>
          <label class="form-label">Tahun Ajaran</label>
          <select name="ta" class="form-control" onchange="this.form.submit()">
            <?php foreach ($ta_list as $ta): ?>
            <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_id?'selected':'' ?>>
              <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Kelas</label>
          <select name="kelas" class="form-control">
            <option value="">Semua Kelas</option>
            <?php foreach ($kelas_list as $k): ?>
            <option value="<?= $k['id'] ?>" <?= $k['id']==$kelas_id?'selected':'' ?>><?= e($k['nama_kelas']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Jenis Raport</label>
          <select name="jenis" class="form-control">
            <option value="">Semua</option>
            <option value="bulanan"  <?= $jenis==='bulanan' ?'selected':'' ?>>Bulanan</option>
            <option value="sts"      <?= $jenis==='sts'     ?'selected':'' ?>>STS</option>
            <option value="semester" <?= $jenis==='semester'?'selected':'' ?>>Semester</option>
          </select>
        </div>
        <div>
          <label class="form-label">Tampil</label>
          <select name="per" class="form-control" onchange="this.form.submit()">
            <?php foreach ([10,25,50,100] as $opt): ?>
            <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?>/hal</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex items-end">
          <button type="submit" class="btn btn-primary w-full">Filter</button>
        </div>
      </div>
    </div>
  </form>

  <div class="card">
    <div class="card-header">
      <span>Daftar Nilai</span>
      <span class="text-sm text-gray-500"><?= $total ?> data</span>
    </div>
    <div class="table-container">
      <table class="data-table" id="dataTable">
        <thead>
          <tr>
            <th>#</th><th>Siswa</th><th>Kelas</th><th>Mata Pelajaran</th>
            <th>Jenis</th><th>Bln</th>
            <th>NH1</th><th>NH2</th><th>NH3</th><th>Tugas</th><th>STS</th><th>SAS</th>
            <th>Nilai Akhir</th><th>Pred.</th>
            <?php if ($role === 'admin'): ?><th>Guru</th><?php endif; ?>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($nilai_list)): ?>
          <tr><td colspan="16" class="text-center py-8 text-gray-400">Belum ada data nilai.</td></tr>
          <?php else: ?>
          <?php foreach ($nilai_list as $i => $n): ?>
          <?php $pred = $n['predikat'] ?? get_predikat((float)$n['nilai_akhir']); ?>
          <tr>
            <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
            <td class="font-semibold whitespace-nowrap"><?= e($n['nama_siswa']) ?></td>
            <td><?= e($n['nama_kelas']) ?></td>
            <td><?= e($n['nama_mapel']) ?></td>
            <td>
              <?php
                $j = $n['jenis_raport'];
                $jBadge = $j==='bulanan' ? 'badge-blue' : ($j==='sts' ? 'badge-purple' : 'badge-orange');
                $jLabel = $j==='bulanan' ? 'Bulanan' : ($j==='sts' ? 'STS' : 'Semester');
              ?>
              <span class="badge <?= $jBadge ?>"><?= $jLabel ?></span>
            </td>
            <td><?= $n['bulan'] ? date('M', mktime(0,0,0,$n['bulan'],1)) : '—' ?></td>
            <td><?= $n['nilai_harian_1'] ?></td>
            <td><?= $n['nilai_harian_2'] ?></td>
            <td><?= $n['nilai_harian_3'] ?></td>
            <td><?= $n['nilai_tugas'] ?></td>
            <td><?= $n['nilai_sts'] ?></td>
            <td><?= $n['nilai_sas'] ?></td>
            <td class="font-bold text-orange-600"><?= number_format($n['nilai_akhir'],2) ?></td>
            <td>
              <span class="badge <?= $pred==='A'?'badge-green':($pred==='B'?'badge-blue':($pred==='C'?'badge-orange':'badge-red')) ?>">
                <?= $pred ?>
              </span>
            </td>
            <?php if ($role === 'admin'): ?>
            <td class="text-xs text-gray-500"><?= e($n['nama_guru']) ?></td>
            <?php endif; ?>
            <td>
              <div class="flex gap-1">
                <?php if ($role !== 'kepala_sekolah'): ?>
                <a href="index.php?page=nilai.input&id=<?= $n['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                <form method="POST" action="index.php?page=nilai" style="display:inline"
                  onsubmit="return confirm('Hapus nilai ini?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $n['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger">×</button>
                </form>
                <?php else: ?>
                <span class="text-xs text-gray-400">—</span>
                <?php endif; ?>
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
      <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages ?> (<?= $total ?> data)</span>
      <div class="pagination">
        <a href="?<?= $qstr_n ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>">‹ Prev</a>
        <?php
          $start = max(1, $page_n - 3); $end = min($pages, $page_n + 3);
          if ($start > 1): ?><a href="?<?= $qstr_n ?>&p=1" class="page-link">1</a><?php
            if ($start > 2): ?><span class="page-link disabled">…</span><?php endif;
          endif;
          for ($pi = $start; $pi <= $end; $pi++):
        ?><a href="?<?= $qstr_n ?>&p=<?= $pi ?>" class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a><?php
          endfor;
          if ($end < $pages):
            if ($end < $pages - 1): ?><span class="page-link disabled">…</span><?php endif;
            ?><a href="?<?= $qstr_n ?>&p=<?= $pages ?>" class="page-link"><?= $pages ?></a><?php
          endif;
        ?>
        <a href="?<?= $qstr_n ?>&p=<?= min($pages,$page_n+1) ?>" class="page-link <?= $page_n>=$pages?'disabled':'' ?>">Next ›</a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
