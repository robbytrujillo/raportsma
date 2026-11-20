<?php
/**
 * pages/kelas/index.php + form.php combined �" admin
 */
require_role(['admin','super_admin']);
require_once __DIR__ . '/../../excel.php';
$user = current_user();
$_exp_uf   = get_user_unit_filter($user);
$_exp_usql = $_exp_uf['sql']; // mis. "AND k.unit = 'putri'" atau ""

// �"��"� Export CSV �"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"�
// Export XLSX -- kolom sesuai template import kelas.
if (isset($_GET['export'])) {
    $rows = db_query(
        "SELECT k.nama_kelas, k.tingkat, k.tahun_ajaran_id, k.wali_kelas_id,
                u.nama_lengkap as nama_wali, ta.nama as ta_nama
         FROM kelas k
         LEFT JOIN users u ON u.id = k.wali_kelas_id
         LEFT JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id
         WHERE 1=1 {$_exp_usql}
         ORDER BY ta.id DESC, k.tingkat, k.nama_kelas"
    );
    xlsx_template('export_kelas_' . date('Ymd') . '.xlsx', [
        'title'    => 'Export Data Kelas -- Raporku',
        'subtitle' => 'Kolom merah = wajib saat re-import | Kolom [dalam kurung] = referensi saja',
        'columns'  => [
            ['header'=>'Nama Kelas',      'field'=>'nama_kelas',      'width'=>20,'required'=>true,  'type'=>'text','format'=>'@'],
            ['header'=>'Tingkat',         'field'=>'tingkat',         'width'=>12,'required'=>true,  'type'=>'dropdown',
             'dropdown'=>['VII','VIII','IX','X','XI','XII'],
             'note'=>'Pilih: VII / VIII / IX / X / XI / XII'],
            ['header'=>'Tahun Ajaran ID', 'field'=>'tahun_ajaran_id', 'width'=>16,'required'=>true,  'type'=>'number',
             'note'=>'ID tahun ajaran -- lihat kolom [Tahun Ajaran] di kanan'],
            ['header'=>'Wali Kelas ID',   'field'=>'wali_kelas_id',   'width'=>14,'required'=>false, 'type'=>'number',
             'note'=>'ID guru/wali kelas. Kosongkan jika belum ditentukan.'],
            // Kolom referensi
            ['header'=>'[Nama Wali Kelas]','field'=>'nama_wali',      'width'=>24,'required'=>false, 'type'=>'text','format'=>'@',
             'note'=>'Referensi saja -- tidak dibaca saat import'],
            ['header'=>'[Tahun Ajaran]',  'field'=>'ta_nama',         'width'=>18,'required'=>false, 'type'=>'text','format'=>'@',
             'note'=>'Referensi saja -- tidak dibaca saat import'],
        ],
        'examples' => array_map(fn($r) => [
            $r['nama_kelas'], $r['tingkat'], $r['tahun_ajaran_id'], $r['wali_kelas_id'],
            $r['nama_wali'], $r['ta_nama'],
        ], $rows),
    ]);
}

// �"��"� Handle ALL POST actions BEFORE any output �"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"�
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { flash('error','Token tidak valid.'); header('Location: /kelas'); exit; }

    $action  = $_POST['action'] ?? '';
    $ta_back = (int)($_POST['tahun_ajaran_id'] ?? ($_GET['ta'] ?? 0));

    if ($action === 'delete') {
        db_execute('DELETE FROM kelas WHERE id = :id', [':id' => (int)$_POST['id']]);
        flash('success', 'Kelas berhasil dihapus.');
        header('Location: /kelas?ta=' . $ta_back); exit;
    }

    if ($action === 'save') {
        $nama   = trim($_POST['nama_kelas'] ?? '');
        $tingkat= trim($_POST['tingkat'] ?? '');
        $wali   = ($_POST['wali_kelas_id'] ?? '') ?: null;
        $ta_id  = (int)($_POST['tahun_ajaran_id'] ?? 0);
        $kid    = (int)($_POST['id'] ?? 0);
        $unit   = in_array($_POST['unit'] ?? '', ['putra','putri','campuran']) ? $_POST['unit'] : 'campuran';

        if ($nama && $tingkat && $ta_id) {
            if ($kid) {
                db_execute('UPDATE kelas SET nama_kelas=:n, tingkat=:t, wali_kelas_id=:w, tahun_ajaran_id=:ta, unit=:u WHERE id=:id',
                    [':n'=>$nama,':t'=>$tingkat,':w'=>$wali,':ta'=>$ta_id,':u'=>$unit,':id'=>$kid]);
                flash('success','Kelas berhasil diperbarui.');
            } else {
                db_execute('INSERT INTO kelas (nama_kelas, tingkat, wali_kelas_id, tahun_ajaran_id, unit) VALUES (:n,:t,:w,:ta,:u)',
                    [':n'=>$nama,':t'=>$tingkat,':w'=>$wali,':ta'=>$ta_id,':u'=>$unit]);
                flash('success','Kelas berhasil ditambahkan.');
            }
        } else {
            flash('error','Nama kelas, tingkat, dan tahun ajaran wajib diisi.');
        }
        header("Location: /kelas?ta=$ta_id"); exit;
    }

    // ── Duplikasi kelas ke semester lain ───────────────────────────
    if ($action === 'duplikasi') {
        $ta_sumber = (int)($_POST['ta_sumber'] ?? 0);
        $ta_tujuan = (int)($_POST['ta_tujuan'] ?? 0);

        if (!$ta_sumber || !$ta_tujuan) {
            flash('error', 'Pilih tahun ajaran sumber dan tujuan.');
            header('Location: /kelas'); exit;
        }
        if ($ta_sumber === $ta_tujuan) {
            flash('error', 'Tahun ajaran sumber dan tujuan tidak boleh sama.');
            header('Location: /kelas?ta=' . $ta_sumber); exit;
        }

        // Ambil semua kelas dari sumber (filter unit jika ada)
        $_dup_uf   = get_user_unit_filter(current_user());
        $_dup_usql = $_dup_uf['sql'];
        $kelas_sumber = db_query(
            "SELECT * FROM kelas WHERE tahun_ajaran_id = :ta {$_dup_usql}",
            [':ta' => $ta_sumber]
        );

        if (empty($kelas_sumber)) {
            flash('error', 'Tidak ada kelas di tahun ajaran sumber.');
            header('Location: /kelas?ta=' . $ta_sumber); exit;
        }

        $duplikasi_count = 0;
        $lewat_count     = 0;
        foreach ($kelas_sumber as $k) {
            // Lewati jika nama kelas sudah ada di tujuan
            $exist = db_row(
                'SELECT id FROM kelas WHERE nama_kelas = :n AND tahun_ajaran_id = :ta',
                [':n' => $k['nama_kelas'], ':ta' => $ta_tujuan]
            );
            if ($exist) { $lewat_count++; continue; }

            db_execute(
                'INSERT INTO kelas (nama_kelas, tingkat, wali_kelas_id, tahun_ajaran_id, unit)
                 VALUES (:n, :t, :w, :ta, :u)',
                [
                    ':n'  => $k['nama_kelas'],
                    ':t'  => $k['tingkat'],
                    ':w'  => $k['wali_kelas_id'],
                    ':ta' => $ta_tujuan,
                    ':u'  => $k['unit'] ?? 'campuran',
                ]
            );
            $duplikasi_count++;
        }

        $msg = "Berhasil menduplikasi {$duplikasi_count} kelas ke tahun ajaran tujuan.";
        if ($lewat_count) $msg .= " ({$lewat_count} kelas dilewati karena sudah ada.)";
        flash('success', $msg);
        header('Location: /kelas?ta=' . $ta_tujuan); exit;
    }
}

// Ambil daftar tahun ajaran dan guru yang bisa jadi wali kelas
$ta_list    = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$wali_list  = db_query("SELECT u.id, u.nama_lengkap FROM users u WHERE u.role IN ('wali_kelas','guru') ORDER BY u.nama_lengkap");
$ta_aktif   = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_filter  = (int)($_GET['ta'] ?? ($ta_aktif['id'] ?? 0));

// �"��"� Paginasi �"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"��"�
$per_opts = [10, 25, 50, 100];
$per      = in_array((int)($_GET['per'] ?? 0), $per_opts) ? (int)$_GET['per'] : 10;
$page_n   = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page_n - 1) * $per;

$_unit_f   = get_user_unit_filter($user);
$_unit_sql = $_unit_f['sql'];

$total = (int)(db_row(
    "SELECT COUNT(*) as n FROM kelas k WHERE k.tahun_ajaran_id = :ta {$_unit_sql}",
    [':ta' => $ta_filter]
)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per));
if ($page_n > $pages) $page_n = $pages;
$offset = ($page_n - 1) * $per;

$kelas_list = db_query(
    "SELECT k.*, u.nama_lengkap as nama_wali, ta.nama as ta_nama, ta.semester
     FROM kelas k
     LEFT JOIN users u ON u.id = k.wali_kelas_id
     LEFT JOIN tahun_ajaran ta ON ta.id = k.tahun_ajaran_id
     WHERE k.tahun_ajaran_id = :ta {$_unit_sql} ORDER BY k.tingkat, k.nama_kelas
     LIMIT $per OFFSET $offset",
    [':ta' => $ta_filter]
);
$qstr_k = http_build_query(['page'=>'kelas','ta'=>$ta_filter,'per'=>$per]);
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Data Kelas</h1>
            <p class="page-subtitle">Kelola kelas per tahun ajaran</p>
        </div>
        <div class="flex gap-2">
            <a href="/kelas?export=1" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                Export CSV
            </a>
            <a href="/kelas.import" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                    <polyline points="17 8 12 3 7 8" />
                    <line x1="12" y1="3" x2="12" y2="15" />
                </svg>
                Import CSV
            </a>
            <button onclick="Modal.open('modalDuplikasiKelas')" class="btn btn-secondary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                </svg>
                Duplikasi Semester
            </button>
            <button onclick="Modal.open('modalKelas')" class="btn btn-primary" style="border-radius: 30px">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Tambah Kelas
            </button>
        </div>
    </div>

    <!-- Filter Tahun Ajaran + Per-page -->
    <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
        <div class="flex gap-2 flex-wrap">
            <?php foreach ($ta_list as $ta): ?>
            <a href="/kelas?ta=<?= $ta['id'] ?>&per=<?= $per ?>"
                class="badge text-sm py-1 px-3 <?= $ta['id']==$ta_filter ? 'badge-orange' : 'badge-gray' ?>"
                style="text-decoration:none;cursor:pointer">
                <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                <?= $ta['is_aktif'] ? ' ....' : '' ?>
            </a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="index.php" class="flex items-center gap-2">
            <input type="hidden" name="page" value="kelas">
            <input type="hidden" name="ta" value="<?= $ta_filter ?>">
            <label class="text-sm text-gray-500">Tampil:</label>
            <select name="per" class="form-control" style="width:90px" onchange="this.form.submit()">
                <?php foreach ([10,25,50,100] as $opt): ?>
                <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?>/hal</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Daftar Kelas</span>
            <span class="text-sm text-gray-500"><?= $total ?> kelas</span>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama Kelas</th>
                        <th>Tingkat</th>
                        <th>Unit</th>
                        <th>Wali Kelas</th>
                        <th>Tahun Ajaran</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kelas_list)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-8 text-gray-400">Belum ada kelas untuk tahun ajaran ini.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($kelas_list as $i => $k): ?>
                    <?php
                        $unit_badge = match($k['unit'] ?? 'campuran') {
                            'putra'    => ['Putra',    'badge-blue'],
                            'putri'    => ['Putri',    'badge-orange'],
                            default    => ['Campuran', 'badge-gray'],
                        };
                    ?>
                    <tr>
                        <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
                        <td class="font-semibold"><?= e($k['nama_kelas']) ?></td>
                        <td><span class="badge badge-blue"><?= e($k['tingkat']) ?></span></td>
                        <td><span class="badge <?= $unit_badge[1] ?>"><?= $unit_badge[0] ?></span></td>
                        <td><?= e($k['nama_wali'] ?: '-') ?></td>
                        <td class="text-gray-500 text-sm"><?= e($k['ta_nama']) ?> Sem.<?= $k['semester'] ?></td>
                        <td>
                            <div class="flex gap-1">
                                <button onclick='editKelas(<?= json_encode($k) ?>)' class="btn btn-sm btn-secondary"
                                    style="border-radius: 30px">Edit</button>
                                <form method="POST" action="/kelas" style="display:inline"
                                    onsubmit="return confirm('Hapus kelas <?= e($k['nama_kelas']) ?>?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                        style="border-radius: 30px">Hapus</button>
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
        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 pagination-wrap">
            <span class="text-sm text-gray-500">Halaman <?= $page_n ?> dari <?= $pages ?> (<?= $total ?> kelas)</span>
            <div class="pagination">
                <a href="?<?= $qstr_k ?>&p=<?= max(1,$page_n-1) ?>" class="page-link <?= $page_n<=1?'disabled':'' ?>">
                    < Prev</a>
                        <?php
          $start = max(1, $page_n - 3); $end = min($pages, $page_n + 3);
          if ($start > 1): ?><a href="?<?= $qstr_k ?>&p=1" class="page-link">1</a><?php
            if ($start > 2): ?><span class="page-link disabled">…</span><?php endif;
          endif;
          for ($pi = $start; $pi <= $end; $pi++):
        ?><a href="?<?= $qstr_k ?>&p=<?= $pi ?>" class="page-link <?= $pi===$page_n?'active':'' ?>"><?= $pi ?></a><?php
          endfor;
          if ($end < $pages):
            if ($end < $pages - 1): ?><span class="page-link disabled">…</span><?php endif;
            ?><a href="?<?= $qstr_k ?>&p=<?= $pages ?>" class="page-link"><?= $pages ?></a><?php
          endif;
        ?>
                        <a href="?<?= $qstr_k ?>&p=<?= min($pages,$page_n+1) ?>"
                            class="page-link <?= $page_n>=$pages?'disabled':'' ?>">Next ></a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Tambah/Edit Kelas -->
<div class="modal-overlay" id="modalKelas">
    <div class="modal">
        <div class="modal-header">
            <span id="modalKelasTitle">Tambah Kelas</span>
            <button onclick="Modal.close('modalKelas')"
                class="text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="/kelas?ta=<?= $ta_filter ?>" id="formKelas">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="kelasId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Kelas <span class="text-red-500">*</span></label>
                    <input type="text" name="nama_kelas" id="kelasNama" class="form-control" required
                        placeholder="contoh: X IPA 1">
                </div>
                <div class="form-group">
                    <label class="form-label">Tingkat <span class="text-red-500">*</span></label>
                    <select name="tingkat" id="kelasTingkat" class="form-control" required>
                        <option value="X">X</option>
                        <option value="XI">XI</option>
                        <option value="XII">XII</option>
                        <option value="VII">VII</option>
                        <option value="VIII">VIII</option>
                        <option value="IX">IX</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <select name="unit" id="kelasUnit" class="form-control">
                        <option value="campuran">Campuran (Putra &amp; Putri)</option>
                        <option value="putra">Putra</option>
                        <option value="putri">Putri</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Wali Kelas</label>
                    <select name="wali_kelas_id" id="kelasWali" class="form-control">
                        <option value="">-- Pilih Wali Kelas --</option>
                        <?php foreach ($wali_list as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= e($w['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tahun Ajaran <span class="text-red-500">*</span></label>
                    <select name="tahun_ajaran_id" id="kelasTa" class="form-control" required>
                        <?php foreach ($ta_list as $ta): ?>
                        <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_filter?'selected':'' ?>>
                            <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Modal.close('modalKelas')" class="btn btn-secondary"
                    style="border-radius: 30px">Batal</button>
                <button type="submit" class="btn btn-primary" style="border-radius: 30px">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function editKelas(data) {
    document.getElementById('modalKelasTitle').textContent = 'Edit Kelas';
    document.getElementById('kelasId').value = data.id;
    document.getElementById('kelasNama').value = data.nama_kelas;
    document.getElementById('kelasTingkat').value = data.tingkat;
    document.getElementById('kelasUnit').value = data.unit || 'campuran';
    document.getElementById('kelasWali').value = data.wali_kelas_id || '';
    document.getElementById('kelasTa').value = data.tahun_ajaran_id;
    Modal.open('modalKelas');
}
</script>

<!-- Modal Duplikasi Semester -->
<div class="modal-overlay" id="modalDuplikasiKelas">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <span>Duplikasi Kelas ke Semester Lain</span>
            <button onclick="Modal.close('modalDuplikasiKelas')"
                class="text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="/kelas">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="duplikasi">
            <div class="modal-body">
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:.85rem;color:#92400e;line-height:1.6">
                    <strong>Apa yang diduplikasi:</strong> Semua kelas (nama, tingkat, unit, wali kelas) dari semester sumber akan disalin ke semester tujuan.<br>
                    <strong>Tidak termasuk:</strong> nilai, absensi, dan data raport — data tersebut tetap terikat pada semester asalnya.
                </div>
                <div class="form-group">
                    <label class="form-label">Sumber <span class="text-red-500">*</span>
                        <span class="text-gray-400 font-normal">(kelas yang akan disalin dari)</span>
                    </label>
                    <select name="ta_sumber" id="dupSumber" class="form-control" required onchange="duplikasiUpdateInfo()">
                        <option value="">-- Pilih Semester Sumber --</option>
                        <?php foreach ($ta_list as $ta): ?>
                        <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_filter ? 'selected' : '' ?>>
                            <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?> (<?= $ta['semester']==1 ? 'Ganjil' : 'Genap' ?>)
                            <?= $ta['is_aktif'] ? ' — Aktif' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tujuan <span class="text-red-500">*</span>
                        <span class="text-gray-400 font-normal">(semester yang akan diisi)</span>
                    </label>
                    <select name="ta_tujuan" id="dupTujuan" class="form-control" required onchange="duplikasiUpdateInfo()">
                        <option value="">-- Pilih Semester Tujuan --</option>
                        <?php foreach ($ta_list as $ta): ?>
                        <option value="<?= $ta['id'] ?>">
                            <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?> (<?= $ta['semester']==1 ? 'Ganjil' : 'Genap' ?>)
                            <?= $ta['is_aktif'] ? ' — Aktif' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="dupWarning" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:.84rem;color:#991b1b">
                    ⚠️ Sumber dan tujuan tidak boleh sama.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="Modal.close('modalDuplikasiKelas')" class="btn btn-secondary"
                    style="border-radius: 30px">Batal</button>
                <button type="submit" id="dupSubmit" class="btn btn-primary" style="border-radius: 30px">
                    Duplikasi Sekarang
                </button>
            </div>
        </form>
    </div>
</div>
<script>
function duplikasiUpdateInfo() {
    var s = document.getElementById('dupSumber').value;
    var t = document.getElementById('dupTujuan').value;
    var warn = document.getElementById('dupWarning');
    var btn  = document.getElementById('dupSubmit');
    if (s && t && s === t) {
        warn.style.display = 'block';
        btn.disabled = true;
    } else {
        warn.style.display = 'none';
        btn.disabled = false;
    }
}
</script>