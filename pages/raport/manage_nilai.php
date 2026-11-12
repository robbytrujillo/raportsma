<?php
/**
 * pages/raport/manage_nilai.php
 * Manajemen Nilai Raport — hapus nilai per-siswa per-mapel
 * Akses: admin & super_admin
 *
 * Fitur:
 *   - Filter: Tahun Ajaran, Kelas, Siswa, Jenis Raport, Bulan
 *   - Tampilkan tabel nilai per-siswa per-mapel untuk periode yang dipilih
 *   - Hapus satu baris nilai (DELETE dari tabel nilai)
 *   - Hapus semua nilai satu siswa untuk periode yang dipilih
 */
require_login();
$_mn_user = current_user();
if (!in_array($_mn_user['role'], ['admin', 'super_admin'])) {
    http_response_code(403);
    echo '<div class="page-content"><div class="alert alert-danger">Hanya Admin yang dapat mengakses halaman ini.</div></div>';
    return;
}
$_mn_uf   = get_user_unit_filter($_mn_user);
$_mn_usql = $_mn_uf['sql']; // mis. "AND k.unit = 'putri'" atau ""

$ta_aktif  = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_list   = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$ta_id     = (int)($_GET['ta']     ?? ($ta_aktif['id'] ?? 0));
$kelas_id  = (int)($_GET['kelas']  ?? 0);
$siswa_id  = (int)($_GET['siswa']  ?? 0);
$jenis     = $_GET['jenis']        ?? '';
$bulan     = (int)($_GET['bulan']  ?? 0);

// ── Handle: Hapus satu baris nilai ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_nilai') {
    if (!verify_csrf()) { flash('error', 'Token tidak valid.'); header('Location: ' . $_SERVER['HTTP_REFERER']); exit; }

    $nid = (int)($_POST['nilai_id'] ?? 0);
    if ($nid) {
        db_execute('DELETE FROM nilai WHERE id = :id', [':id' => $nid]);
        flash('success', 'Data nilai berhasil dihapus.');
    }
    $qs = http_build_query(['page'=>'raport.manage_nilai','ta'=>$ta_id,'kelas'=>$kelas_id,'siswa'=>$siswa_id,'jenis'=>$jenis,'bulan'=>$bulan]);
    header("Location: index.php?$qs"); exit;
}

// ── Handle: Hapus semua nilai satu siswa untuk periode ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_siswa_periode') {
    if (!verify_csrf()) { flash('error', 'Token tidak valid.'); header('Location: ' . $_SERVER['HTTP_REFERER']); exit; }

    $d_siswa  = (int)($_POST['siswa_id']        ?? 0);
    $d_kelas  = (int)($_POST['kelas_id']         ?? 0);
    $d_ta     = (int)($_POST['tahun_ajaran_id']  ?? 0);
    $d_jenis  = $_POST['jenis_raport']           ?? '';
    $d_bulan  = (int)($_POST['bulan']            ?? 0) ?: null;

    if ($d_siswa && $d_kelas && $d_ta && in_array($d_jenis, ['bulanan','sts','semester'])) {
        $params     = [':sid'=>$d_siswa,':kid'=>$d_kelas,':ta'=>$d_ta,':jenis'=>$d_jenis];
        $bulan_cond = $d_bulan ? 'AND bulan = :bulan' : 'AND bulan IS NULL';
        if ($d_bulan) $params[':bulan'] = $d_bulan;
        $n = db_execute(
            "DELETE FROM nilai WHERE siswa_user_id=:sid AND kelas_id=:kid
              AND tahun_ajaran_id=:ta AND jenis_raport=:jenis $bulan_cond",
            $params
        );
        flash('success', "Berhasil menghapus $n data nilai untuk siswa tersebut.");
    } else {
        flash('error', 'Parameter tidak lengkap.');
    }
    $qs = http_build_query(['page'=>'raport.manage_nilai','ta'=>$ta_id,'kelas'=>$kelas_id,'siswa'=>$siswa_id,'jenis'=>$jenis,'bulan'=>$bulan]);
    header("Location: index.php?$qs"); exit;
}

// ── Handle: Hapus banyak nilai sekaligus (bulk) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_bulk') {
    if (!verify_csrf()) { flash('error', 'Token tidak valid.'); header('Location: ' . $_SERVER['HTTP_REFERER']); exit; }

    $raw_ids = $_POST['nilai_ids'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $raw_ids)));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deleted = db_execute("DELETE FROM nilai WHERE id IN ($placeholders)", array_values($ids));
        flash('success', "Berhasil menghapus $deleted data nilai yang dipilih.");
    } else {
        flash('error', 'Tidak ada data yang dipilih.');
    }
    $qs = http_build_query(['page'=>'raport.manage_nilai','ta'=>$ta_id,'kelas'=>$kelas_id,'siswa'=>$siswa_id,'jenis'=>$jenis,'bulan'=>$bulan]);
    header("Location: index.php?$qs"); exit;
}

// ── Kelas berdasarkan TA (difilter per unit) ────────────────────
$kelas_list = $ta_id
    ? db_query("SELECT * FROM kelas k WHERE k.tahun_ajaran_id = :ta {$_mn_usql} ORDER BY k.nama_kelas", [':ta' => $ta_id])
    : [];

// ── Siswa berdasarkan Kelas ─────────────────────────────────────
$siswa_list = [];
if ($kelas_id) {
    $siswa_list = db_query(
        'SELECT u.id, u.nama_lengkap, s.nisn FROM users u
         INNER JOIN siswa s ON s.user_id = u.id
         WHERE s.kelas_id = :kid ORDER BY u.nama_lengkap',
        [':kid' => $kelas_id]
    );
}

// ── Ambil data nilai jika filter sudah cukup ────────────────────
$nilai_rows = [];
$nama_siswa_sel = '';
$nama_kelas_sel = '';

if ($kelas_id && $jenis && ($siswa_id || true)) {
    // Ambil nama kelas
    foreach ($kelas_list as $k) {
        if ((int)$k['id'] === $kelas_id) { $nama_kelas_sel = $k['nama_kelas']; break; }
    }

    $params_v = [':ta' => $ta_id, ':kid' => $kelas_id, ':jenis' => $jenis];
    $cond     = ['n.tahun_ajaran_id = :ta', 'n.kelas_id = :kid', 'n.jenis_raport = :jenis'];

    if ($siswa_id) {
        $cond[]           = 'n.siswa_user_id = :sid';
        $params_v[':sid'] = $siswa_id;
        // Nama siswa
        foreach ($siswa_list as $s) {
            if ((int)$s['id'] === $siswa_id) { $nama_siswa_sel = $s['nama_lengkap']; break; }
        }
    }

    if (in_array($jenis, ['bulanan', 'sts']) && $bulan) {
        $cond[]            = 'n.bulan = :bulan';
        $params_v[':bulan'] = $bulan;
    } elseif (in_array($jenis, ['bulanan', 'sts']) && !$bulan) {
        // Jika tidak pilih bulan untuk jenis bulanan/sts, tampilkan semua bulan
    }

    $nilai_rows = db_query(
        'SELECT n.id as nilai_id, n.siswa_user_id, n.mapel_id, n.bulan,
                n.nilai_harian_1, n.nilai_harian_2, n.nilai_harian_3,
                n.nilai_tugas, n.nilai_sts, n.nilai_sas, n.nilai_akhir,
                n.predikat, n.catatan, n.deskripsi,
                u.nama_lengkap as nama_siswa,
                m.nama_mapel, m.kelompok,
                mp.nama_mapel as nama_parent
         FROM nilai n
         LEFT JOIN users u  ON u.id  = n.siswa_user_id
         LEFT JOIN mapel m  ON m.id  = n.mapel_id
         LEFT JOIN mapel mp ON mp.id = m.parent_id
         WHERE ' . implode(' AND ', $cond) . '
         ORDER BY u.nama_lengkap, m.kelompok, m.urutan, m.nama_mapel, n.bulan',
        $params_v
    );
}

$bulan_names = BULAN_ID;
$jenis_label = ['bulanan'=>'Bulanan','sts'=>'STS','semester'=>'Semester'];
$qstr = http_build_query(['page'=>'raport.manage_nilai','ta'=>$ta_id,'kelas'=>$kelas_id,'siswa'=>$siswa_id,'jenis'=>$jenis,'bulan'=>$bulan]);
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Manajemen Nilai Raport</h1>
            <p class="page-subtitle">Hapus nilai per-siswa per-mapel tanpa mengganggu kelas lain</p>
        </div>
        <a href="index.php?page=raport.manage" class="btn btn-secondary" style="border-radius:30px">
            &larr; Manajemen Raport
        </a>
    </div>

    <!-- ── Filter ──────────────────────────────────────────────── -->
    <form method="GET" action="index.php" class="card mb-5" id="filterForm">
        <div class="card-body">
            <input type="hidden" name="page" value="raport.manage_nilai">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <!-- Tahun Ajaran -->
                <div>
                    <label class="form-label">Tahun Ajaran</label>
                    <select name="ta" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Pilih TA --</option>
                        <?php foreach ($ta_list as $ta): ?>
                        <option value="<?= $ta['id'] ?>" <?= $ta['id']==$ta_id?'selected':'' ?>>
                            <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Kelas -->
                <div>
                    <label class="form-label">Kelas</label>
                    <select name="kelas" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Pilih Kelas --</option>
                        <?php foreach ($kelas_list as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $k['id']==$kelas_id?'selected':'' ?>>
                            <?= e($k['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Siswa -->
                <div>
                    <label class="form-label">Siswa <small class="text-gray-400">(opsional)</small></label>
                    <select name="siswa" class="form-control">
                        <option value="">-- Semua Siswa --</option>
                        <?php foreach ($siswa_list as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id']==$siswa_id?'selected':'' ?>>
                            <?= e($s['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Jenis Raport -->
                <div>
                    <label class="form-label">Jenis Raport</label>
                    <select name="jenis" class="form-control">
                        <option value="">-- Pilih Jenis --</option>
                        <option value="bulanan"  <?= $jenis==='bulanan' ?'selected':'' ?>>Bulanan</option>
                        <option value="sts"      <?= $jenis==='sts'     ?'selected':'' ?>>STS</option>
                        <option value="semester" <?= $jenis==='semester'?'selected':'' ?>>Semester</option>
                    </select>
                </div>
                <!-- Bulan (hanya untuk bulanan/sts) -->
                <div>
                    <label class="form-label">Bulan <small class="text-gray-400">(bulanan/sts)</small></label>
                    <select name="bulan" class="form-control" <?= !in_array($jenis,['bulanan','sts'])?'disabled':'' ?>>
                        <option value="">-- Semua Bulan --</option>
                        <?php foreach ($bulan_names as $bk => $bv): ?>
                        <option value="<?= $bk ?>" <?= $bulan==$bk?'selected':'' ?>><?= e($bv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Tombol -->
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-full" style="border-radius:30px">Tampilkan</button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($kelas_id && $jenis && !empty($nilai_rows)): ?>
    <!-- ── Info Bar ─────────────────────────────────────────── -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <div style="font-size:.875rem;color:#6b7280">
            Menampilkan <strong><?= count($nilai_rows) ?></strong> baris &mdash;
            Kelas <strong><?= e($nama_kelas_sel) ?></strong>,
            Jenis <strong><?= e($jenis_label[$jenis] ?? $jenis) ?></strong>
            <?= $bulan ? ', Bulan <strong>' . e($bulan_names[$bulan] ?? $bulan) . '</strong>' : '' ?>
            <?= $siswa_id && $nama_siswa_sel ? ', Siswa <strong>' . e($nama_siswa_sel) . '</strong>' : '' ?>
        </div>
        <div style="font-size:.75rem;color:#ef4444;font-weight:600">
            ⚠ Penghapusan bersifat permanen dan tidak dapat dibatalkan
        </div>
    </div>

    <!-- ── Toolbar: search + bulk-delete ───────────────────── -->
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <!-- Search -->
        <div style="position:relative;flex:1;min-width:200px;max-width:340px">
            <svg width="14" height="14" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"
                 style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="mnrSearch" placeholder="Cari nama siswa atau mapel…"
                   style="width:100%;padding:7px 10px 7px 32px;border:1px solid #e5e7eb;border-radius:8px;
                          font-size:.875rem;outline:none;transition:border-color .2s"
                   onfocus="this.style.borderColor='#f97316'" onblur="this.style.borderColor='#e5e7eb'">
        </div>
        <!-- Counter -->
        <span id="mnrCount" style="font-size:.8rem;color:#9ca3af;white-space:nowrap"></span>
        <!-- Tombol hapus terpilih — muncul saat ada yang dicentang -->
        <button id="btnBulkDelete" type="button"
            style="display:none;align-items:center;gap:6px;padding:7px 16px;border-radius:30px;
                   background:#dc2626;color:#fff;border:none;font-size:.82rem;font-weight:600;
                   cursor:pointer;white-space:nowrap"
            onclick="mnr_bulkDelete()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14H6L5 6"/>
                <path d="M10 11v6M14 11v6M9 6V4h6v2"/>
            </svg>
            <span id="btnBulkLabel">Hapus Terpilih</span>
        </button>
    </div>

    <!-- ── Tabel Nilai ──────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <span>Data Nilai</span>
            <span class="badge badge-orange" id="mnrBadge"><?= count($nilai_rows) ?> data</span>
        </div>
        <div class="table-container">
            <table class="data-table" id="tblNilai">
                <thead>
                    <tr>
                        <!-- Checkbox all -->
                        <th style="width:36px;text-align:center;padding:8px 6px">
                            <input type="checkbox" id="chkAll"
                                   style="width:15px;height:15px;cursor:pointer;accent-color:#f97316"
                                   title="Pilih semua">
                        </th>
                        <th>Siswa</th>
                        <?php if (in_array($jenis, ['bulanan','sts']) && !$bulan): ?><th>Bulan</th><?php endif; ?>
                        <th style="width:50px">Grp</th>
                        <th>Mapel</th>
                        <th style="width:62px;text-align:center">Nilai</th>
                        <th style="width:50px;text-align:center">Pred.</th>
                        <th style="width:72px;text-align:center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ($nilai_rows as $i => $n):
                    $pred = $n['predikat'] ?? '';
                    $predBadge = $pred==='A'?'badge-green':($pred==='B'?'badge-blue':($pred==='C'?'badge-orange':'badge-red'));
                    $desk = htmlspecialchars(trim($n['deskripsi'] ?? '') ?: ($n['catatan'] ?? ''));
                ?>
                <tr id="row-<?= $n['nilai_id'] ?>"
                    data-search="<?= strtolower(e($n['nama_siswa']) . ' ' . e($n['nama_mapel']) . ' ' . ($n['nama_parent'] ?? '')) ?>"
                    data-nilai-id="<?= $n['nilai_id'] ?>"
                    data-siswa-id="<?= (int)$n['siswa_user_id'] ?>"
                    data-nama-siswa="<?= e(addslashes($n['nama_siswa'])) ?>"
                    data-nama-mapel="<?= e(addslashes($n['nama_mapel'])) ?>">
                    <!-- Checkbox baris -->
                    <td style="text-align:center;padding:8px 6px">
                        <input type="checkbox" class="row-chk"
                               style="width:15px;height:15px;cursor:pointer;accent-color:#f97316"
                               value="<?= $n['nilai_id'] ?>">
                    </td>
                    <td class="font-semibold"
                        style="white-space:nowrap;max-width:140px;overflow:hidden;text-overflow:ellipsis"
                        title="<?= e($n['nama_siswa']) ?>"><?= e($n['nama_siswa']) ?></td>
                    <?php if (in_array($jenis, ['bulanan','sts']) && !$bulan): ?>
                    <td style="white-space:nowrap"><?= $n['bulan'] ? e($bulan_names[(int)$n['bulan']] ?? 'Bln '.$n['bulan']) : '—' ?></td>
                    <?php endif; ?>
                    <td><span class="badge badge-gray" style="font-size:.65rem;padding:2px 5px"><?= e($n['kelompok']) ?></span></td>
                    <td>
                        <?php if ($n['nama_parent']): ?>
                        <div style="font-size:.7rem;color:#9ca3af;line-height:1.2;margin-bottom:1px"><?= e($n['nama_parent']) ?></div>
                        <?php endif; ?>
                        <div class="font-medium" style="line-height:1.3"><?= e($n['nama_mapel']) ?></div>
                        <?php if ($desk): ?>
                        <div style="font-size:.68rem;color:#bbb;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px" title="<?= $desk ?>"><?= $desk ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;color:#ea580c"><?= number_format((float)$n['nilai_akhir'], 0) ?></td>
                    <td style="text-align:center"><?= $pred ? '<span class="badge ' . $predBadge . '" style="font-size:.65rem;padding:2px 5px">' . e($pred) . '</span>' : '—' ?></td>
                    <td style="text-align:center">
                        <div style="display:flex;align-items:center;justify-content:center;gap:4px">
                            <!-- Hapus 1 baris (icon trash) -->
                            <form method="POST" action="index.php?<?= $qstr ?>" style="margin:0"
                                  onsubmit="return confirm('Hapus nilai <?= e(addslashes($n['nama_mapel'])) ?> untuk <?= e(addslashes($n['nama_siswa'])) ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"   value="delete_nilai">
                                <input type="hidden" name="nilai_id" value="<?= $n['nilai_id'] ?>">
                                <button type="submit"
                                        title="Hapus nilai mapel ini"
                                        style="width:28px;height:28px;border-radius:8px;border:1px solid #fecaca;
                                               background:#fff5f5;color:#dc2626;cursor:pointer;
                                               display:inline-flex;align-items:center;justify-content:center;
                                               transition:background .15s">
                                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6l-1 14H6L5 6"/>
                                        <path d="M10 11v6M14 11v6M9 6V4h6v2"/>
                                    </svg>
                                </button>
                            </form>
                            <!-- Hapus semua nilai siswa ini (icon user-x) -->
                            <button type="button"
                                    title="Hapus semua mapel siswa <?= e(addslashes($n['nama_siswa'])) ?> di periode ini"
                                    style="width:28px;height:28px;border-radius:8px;border:1px solid #fed7aa;
                                           background:#fff7ed;color:#ea580c;cursor:pointer;
                                           display:inline-flex;align-items:center;justify-content:center;
                                           transition:background .15s"
                                    onclick="mnr_hapusSiswa(<?= (int)$n['siswa_user_id'] ?>,<?= $kelas_id ?>,<?= $ta_id ?>,'<?= addslashes($jenis) ?>',<?= $bulan ?: 0 ?>,'<?= e(addslashes($n['nama_siswa'])) ?>')">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <line x1="17" y1="8" x2="23" y2="14"/><line x1="23" y1="8" x2="17" y2="14"/>
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($kelas_id && $jenis && empty($nilai_rows)): ?>
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="12" y2="17"/>
            </svg>
            <p>Tidak ada data nilai untuk filter yang dipilih.</p>
        </div>
    </div>

    <?php else: ?>
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <p>Pilih kelas dan jenis raport untuk menampilkan data nilai.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Modal: Konfirmasi Hapus Semua Mapel Siswa ─────────────── -->
<div id="modalHapusSiswa" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);
     z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:460px;width:90%;
                box-shadow:0 8px 40px rgba(0,0,0,.2)">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
            <div style="width:44px;height:44px;background:#ffedd5;border-radius:50%;display:flex;
                        align-items:center;justify-content:center;flex-shrink:0">
                <svg width="22" height="22" fill="none" stroke="#ea580c" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:#1f2937;margin:0">Hapus Semua Nilai Siswa</h3>
                <p id="mnrSiswaDesc" style="font-size:.82rem;color:#6b7280;margin:2px 0 0"></p>
            </div>
        </div>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;
                    font-size:.82rem;color:#92400e;margin-bottom:16px">
            Tindakan ini akan menghapus <strong>semua mapel</strong> milik siswa ini untuk periode yang dipilih.
            Data tidak dapat dikembalikan.
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button type="button" onclick="document.getElementById('modalHapusSiswa').style.display='none'"
                    class="btn btn-secondary" style="border-radius:30px">Batal</button>
            <form id="formHapusSiswa" method="POST" action="index.php?<?= $qstr ?>" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action"          value="delete_siswa_periode">
                <input type="hidden" name="siswa_id"        id="mnr_siswa_id">
                <input type="hidden" name="kelas_id"        id="mnr_kelas_id">
                <input type="hidden" name="tahun_ajaran_id" id="mnr_ta_id">
                <input type="hidden" name="jenis_raport"    id="mnr_jenis">
                <input type="hidden" name="bulan"           id="mnr_bulan">
                <button type="submit" class="btn btn-danger" style="border-radius:30px">Ya, Hapus Semua</button>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Konfirmasi Hapus Terpilih (Bulk) ───────────────── -->
<div id="modalBulkDelete" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);
     z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:460px;width:90%;
                box-shadow:0 8px 40px rgba(0,0,0,.2)">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
            <div style="width:44px;height:44px;background:#fee2e2;border-radius:50%;display:flex;
                        align-items:center;justify-content:center;flex-shrink:0">
                <svg width="22" height="22" fill="none" stroke="#dc2626" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6l-1 14H6L5 6"/>
                    <path d="M10 11v6M14 11v6M9 6V4h6v2"/>
                </svg>
            </div>
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:#1f2937;margin:0">Hapus Nilai Terpilih</h3>
                <p id="bulkDesc" style="font-size:.82rem;color:#6b7280;margin:2px 0 0"></p>
            </div>
        </div>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;
                    font-size:.82rem;color:#7f1d1d;margin-bottom:16px">
            Semua baris yang dipilih akan dihapus secara permanen. Tindakan ini tidak dapat dibatalkan.
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button type="button" onclick="document.getElementById('modalBulkDelete').style.display='none'"
                    class="btn btn-secondary" style="border-radius:30px">Batal</button>
            <form id="formBulkDelete" method="POST" action="index.php?<?= $qstr ?>" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action"    value="delete_bulk">
                <input type="hidden" name="nilai_ids" id="bulkIds">
                <button type="submit" class="btn btn-danger" style="border-radius:30px">Ya, Hapus</button>
            </form>
        </div>
    </div>
</div>

<script>
// ── Hapus per-siswa ──────────────────────────────────────────────
function mnr_hapusSiswa(siswaId, kelasId, taId, jenis, bulan, namaSiswa) {
    document.getElementById('mnr_siswa_id').value = siswaId;
    document.getElementById('mnr_kelas_id').value = kelasId;
    document.getElementById('mnr_ta_id').value    = taId;
    document.getElementById('mnr_jenis').value    = jenis;
    document.getElementById('mnr_bulan').value    = bulan || '';

    var jenisLabel = {bulanan:'Bulanan',sts:'STS',semester:'Semester'};
    var bulanNames = <?= json_encode(array_map('strval', BULAN_ID)) ?>;
    var bDesc = bulan && bulanNames[bulan] ? ' — ' + bulanNames[bulan] : '';
    document.getElementById('mnrSiswaDesc').textContent =
        namaSiswa + ' | ' + (jenisLabel[jenis] || jenis) + bDesc;
    document.getElementById('modalHapusSiswa').style.display = 'flex';
}

// ── Hapus bulk terpilih ──────────────────────────────────────────
function mnr_bulkDelete() {
    var checked = document.querySelectorAll('#tblNilai .row-chk:checked');
    if (!checked.length) return;
    var ids = Array.from(checked).map(function(c) { return c.value; });
    document.getElementById('bulkIds').value  = ids.join(',');
    document.getElementById('bulkDesc').textContent = ids.length + ' baris nilai dipilih untuk dihapus.';
    document.getElementById('modalBulkDelete').style.display = 'flex';
}

// ── Tutup modal klik backdrop ────────────────────────────────────
['modalHapusSiswa','modalBulkDelete'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
});

// ── Checkbox logic ────────────────────────────────────────────────
(function() {
    var chkAll  = document.getElementById('chkAll');
    var btnBulk = document.getElementById('btnBulkDelete');
    var lblBulk = document.getElementById('btnBulkLabel');
    if (!chkAll) return;

    function syncBulkBtn() {
        var checked = document.querySelectorAll('#tblNilai .row-chk:checked');
        var n = checked.length;
        if (n > 0) {
            btnBulk.style.display = 'inline-flex';
            lblBulk.textContent   = 'Hapus ' + n + ' Terpilih';
        } else {
            btnBulk.style.display = 'none';
        }
        // Update indeterminate state of chkAll
        var allVisible = document.querySelectorAll('#tblNilai tbody tr:not([style*="display: none"]) .row-chk');
        var allVisibleChecked = document.querySelectorAll('#tblNilai tbody tr:not([style*="display: none"]) .row-chk:checked');
        chkAll.indeterminate = allVisibleChecked.length > 0 && allVisibleChecked.length < allVisible.length;
        chkAll.checked = allVisible.length > 0 && allVisibleChecked.length === allVisible.length;
    }

    // Checkbox all: hanya centang/uncentang baris yang terlihat
    chkAll.addEventListener('change', function() {
        document.querySelectorAll('#tblNilai tbody tr:not([style*="display: none"]) .row-chk')
            .forEach(function(c) { c.checked = chkAll.checked; });
        syncBulkBtn();
    });

    // Delegasi event checkbox per-baris
    document.getElementById('tblNilai').addEventListener('change', function(e) {
        if (e.target.classList.contains('row-chk')) syncBulkBtn();
    });

    // Klik baris → toggle checkbox (kecuali klik di tombol/form)
    document.querySelectorAll('#tblNilai tbody tr').forEach(function(tr) {
        tr.style.cursor = 'default';
        tr.addEventListener('click', function(e) {
            if (e.target.closest('button,form,input,a')) return;
            var chk = tr.querySelector('.row-chk');
            if (chk) { chk.checked = !chk.checked; syncBulkBtn(); }
        });
    });
})();

// ── Auto-disable bulan jika jenis bukan bulanan/sts ──────────────
(function() {
    var jenisEl = document.querySelector('select[name="jenis"]');
    var bulanEl = document.querySelector('select[name="bulan"]');
    if (!jenisEl || !bulanEl) return;
    jenisEl.addEventListener('change', function() {
        bulanEl.disabled = !['bulanan','sts'].includes(this.value);
        if (bulanEl.disabled) bulanEl.value = '';
    });
})();

// ── Live search ──────────────────────────────────────────────────
(function() {
    var input   = document.getElementById('mnrSearch');
    var badge   = document.getElementById('mnrBadge');
    var counter = document.getElementById('mnrCount');
    var chkAll  = document.getElementById('chkAll');
    if (!input) return;

    input.addEventListener('input', function() {
        var q    = this.value.trim().toLowerCase();
        var rows = document.querySelectorAll('#tblNilai tbody tr');
        var vis  = 0;
        rows.forEach(function(tr) {
            var haystack = (tr.getAttribute('data-search') || '').toLowerCase();
            var show = !q || haystack.indexOf(q) !== -1;
            tr.style.display = show ? '' : 'none';
            if (!show) {
                var chk = tr.querySelector('.row-chk');
                if (chk) chk.checked = false; // uncheck baris tersembunyi
            }
            if (show) vis++;
        });
        var total = rows.length;
        if (badge)   badge.textContent = (q ? vis + ' / ' + total : total) + ' data';
        if (counter) counter.textContent = q ? 'Menampilkan ' + vis + ' dari ' + total : '';
        // Update bulk button setelah search
        var checked = document.querySelectorAll('#tblNilai .row-chk:checked');
        var btnBulk = document.getElementById('btnBulkDelete');
        var lblBulk = document.getElementById('btnBulkLabel');
        if (btnBulk) {
            var n = checked.length;
            btnBulk.style.display = n > 0 ? 'inline-flex' : 'none';
            if (n > 0 && lblBulk) lblBulk.textContent = 'Hapus ' + n + ' Terpilih';
        }
        if (chkAll) { chkAll.checked = false; chkAll.indeterminate = false; }
    });
})();
</script>
