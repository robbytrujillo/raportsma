<?php
/**
 * pages/raport/manage.php
 * Manajemen Raport — khusus super_admin.
 * Fitur:
 *   - Tampilkan semua periode nilai (kelas + TA + jenis + bulan)
 *   - Hapus semua nilai untuk satu periode (bulk delete)
 *   - Edit bulan: pindahkan semua nilai dari bulan lama ke bulan baru
 *   - Lihat status publikasi per periode
 */
require_login();
if (!is_super_admin()) {
    http_response_code(403);
    echo '<div class="page-content"><div class="alert alert-danger">Hanya Super Admin yang dapat mengakses halaman ini.</div></div>';
    return;
}

$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_list  = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');
$ta_id    = (int)($_GET['ta'] ?? ($ta_aktif['id'] ?? 0));
$kelas_id = (int)($_GET['kelas'] ?? 0);

// Kelas list
$kelas_list = $ta_id
    ? db_query('SELECT * FROM kelas WHERE tahun_ajaran_id = :ta ORDER BY nama_kelas', [':ta' => $ta_id])
    : [];

// ── Handle: Hapus satu periode ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_periode') {
    if (!verify_csrf()) { flash('error', 'Token tidak valid.'); header('Location: index.php?page=raport.manage&ta='.$ta_id.'&kelas='.$kelas_id); exit; }

    $d_kelas = (int)($_POST['kelas_id']        ?? 0);
    $d_ta    = (int)($_POST['tahun_ajaran_id'] ?? 0);
    $d_jenis = $_POST['jenis_raport']          ?? '';
    $d_bulan = (int)($_POST['bulan']           ?? 0) ?: null;

    if ($d_kelas && $d_ta && in_array($d_jenis, ['bulanan','sts','semester'])) {
        $params = [':kid'=>$d_kelas, ':ta'=>$d_ta, ':jenis'=>$d_jenis];
        $bulan_cond = '';
        if ($d_bulan) {
            $bulan_cond = 'AND bulan = :bulan';
            $params[':bulan'] = $d_bulan;
        } else {
            $bulan_cond = 'AND bulan IS NULL';
        }
        $jumlah = db_execute(
            "DELETE FROM nilai WHERE kelas_id=:kid AND tahun_ajaran_id=:ta AND jenis_raport=:jenis $bulan_cond",
            $params
        );
        // Hapus juga entri publikasi
        $pub_params = [':k'=>$d_kelas,':t'=>$d_ta,':j'=>$d_jenis,':b'=>$d_bulan,':b2'=>$d_bulan];
        db_execute(
            'DELETE FROM raport_publikasi WHERE kelas_id=:k AND tahun_ajaran_id=:t AND jenis_raport=:j AND (bulan=:b OR (bulan IS NULL AND :b2 IS NULL))',
            $pub_params
        );
        flash('success', "Berhasil menghapus $jumlah data nilai untuk periode tersebut.");
    } else {
        flash('error', 'Parameter tidak lengkap.');
    }
    header('Location: index.php?page=raport.manage&ta='.$ta_id.'&kelas='.$kelas_id); exit;
}

// ── Handle: Edit bulan ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_bulan') {
    if (!verify_csrf()) { flash('error', 'Token tidak valid.'); header('Location: index.php?page=raport.manage&ta='.$ta_id.'&kelas='.$kelas_id); exit; }

    $e_kelas      = (int)($_POST['kelas_id']        ?? 0);
    $e_ta         = (int)($_POST['tahun_ajaran_id'] ?? 0);
    $e_jenis      = $_POST['jenis_raport']          ?? '';
    $e_bulan_lama = (int)($_POST['bulan_lama']      ?? 0) ?: null;
    $e_bulan_baru = (int)($_POST['bulan_baru']      ?? 0) ?: null;

    if ($e_kelas && $e_ta && in_array($e_jenis, ['bulanan','sts','semester']) && $e_bulan_baru) {
        // Cek konflik nyata: apakah ada siswa+mapel yang sudah punya data di bulan baru?
        // Query ini mendeteksi overlap per-row (bukan hanya per-kelas), sesuai unique key nilai.
        if ($e_bulan_lama) {
            $konflik = db_row(
                'SELECT COUNT(*) as n FROM nilai lama
                 INNER JOIN nilai baru
                    ON  baru.siswa_user_id   = lama.siswa_user_id
                    AND baru.kelas_id        = lama.kelas_id
                    AND baru.tahun_ajaran_id = lama.tahun_ajaran_id
                    AND baru.mapel_id        = lama.mapel_id
                    AND baru.jenis_raport    = lama.jenis_raport
                    AND baru.bulan           = :bulan_baru
                 WHERE lama.kelas_id=:kid AND lama.tahun_ajaran_id=:ta
                   AND lama.jenis_raport=:jenis AND lama.bulan=:bulan_lama',
                [':kid'=>$e_kelas,':ta'=>$e_ta,':jenis'=>$e_jenis,
                 ':bulan_lama'=>$e_bulan_lama,':bulan_baru'=>$e_bulan_baru]
            );
        } else {
            $konflik = db_row(
                'SELECT COUNT(*) as n FROM nilai lama
                 INNER JOIN nilai baru
                    ON  baru.siswa_user_id   = lama.siswa_user_id
                    AND baru.kelas_id        = lama.kelas_id
                    AND baru.tahun_ajaran_id = lama.tahun_ajaran_id
                    AND baru.mapel_id        = lama.mapel_id
                    AND baru.jenis_raport    = lama.jenis_raport
                    AND baru.bulan           = :bulan_baru
                 WHERE lama.kelas_id=:kid AND lama.tahun_ajaran_id=:ta
                   AND lama.jenis_raport=:jenis AND lama.bulan IS NULL',
                [':kid'=>$e_kelas,':ta'=>$e_ta,':jenis'=>$e_jenis,':bulan_baru'=>$e_bulan_baru]
            );
        }
        if ((int)($konflik['n'] ?? 0) > 0) {
            flash('error', 'Bulan tujuan sudah memiliki data nilai yang konflik (siswa & mapel yang sama). Hapus dulu data di bulan tersebut sebelum memindahkan.');
            header('Location: index.php?page=raport.manage&ta='.$ta_id.'&kelas='.$kelas_id); exit;
        }

        $params = [':bulan_baru'=>$e_bulan_baru,':kid'=>$e_kelas,':ta'=>$e_ta,':jenis'=>$e_jenis];
        $bulan_cond = '';
        if ($e_bulan_lama) {
            $bulan_cond = 'AND bulan = :bulan_lama';
            $params[':bulan_lama'] = $e_bulan_lama;
        } else {
            $bulan_cond = 'AND bulan IS NULL';
        }
        try {
            $jumlah = db_execute(
                "UPDATE nilai SET bulan=:bulan_baru WHERE kelas_id=:kid AND tahun_ajaran_id=:ta AND jenis_raport=:jenis $bulan_cond",
                $params
            );
            // Update publikasi juga
            $pub_params = [':b_baru'=>$e_bulan_baru,':k'=>$e_kelas,':t'=>$e_ta,':j'=>$e_jenis,':b'=>$e_bulan_lama,':b2'=>$e_bulan_lama];
            db_execute(
                'UPDATE raport_publikasi SET bulan=:b_baru WHERE kelas_id=:k AND tahun_ajaran_id=:t AND jenis_raport=:j AND (bulan=:b OR (bulan IS NULL AND :b2 IS NULL))',
                $pub_params
            );
            flash('success', "Berhasil memindahkan $jumlah data nilai ke bulan baru.");
        } catch (Exception $ex) {
            // Tangkap duplicate key yang lolos dari cek di atas
            if (str_contains($ex->getMessage(), '1062') || str_contains($ex->getMessage(), 'Duplicate')) {
                flash('error', 'Gagal: terdapat data nilai duplikat di bulan tujuan. Hapus dulu semua data di bulan tersebut, kemudian coba lagi.');
            } else {
                flash('error', 'Gagal memindahkan data: ' . $ex->getMessage());
            }
        }
    } else {
        flash('error', 'Parameter tidak lengkap atau bulan baru tidak dipilih.');
    }
    header('Location: index.php?page=raport.manage&ta='.$ta_id.'&kelas='.$kelas_id); exit;
}

// ── Ambil daftar periode yang tersedia ────────────────────────
$periodes = [];
if ($ta_id) {
    $params = [':ta' => $ta_id];
    $kelas_cond = '';
    if ($kelas_id) {
        $kelas_cond = 'AND n.kelas_id = :kid';
        $params[':kid'] = $kelas_id;
    }
    $periodes = db_query(
        "SELECT n.kelas_id, n.tahun_ajaran_id, n.jenis_raport, n.bulan,
                k.nama_kelas, ta.nama as ta_nama, ta.semester,
                COUNT(n.id) as jml_nilai,
                COUNT(DISTINCT n.siswa_user_id) as jml_siswa,
                COUNT(DISTINCT n.mapel_id) as jml_mapel,
                COALESCE(rp.is_published, 0) as is_published
         FROM nilai n
         LEFT JOIN kelas k ON k.id = n.kelas_id
         LEFT JOIN tahun_ajaran ta ON ta.id = n.tahun_ajaran_id
         LEFT JOIN raport_publikasi rp
               ON  rp.kelas_id = n.kelas_id
               AND rp.tahun_ajaran_id = n.tahun_ajaran_id
               AND rp.jenis_raport = n.jenis_raport
               AND (rp.bulan = n.bulan OR (rp.bulan IS NULL AND n.bulan IS NULL))
         WHERE n.tahun_ajaran_id = :ta $kelas_cond
         GROUP BY n.kelas_id, n.tahun_ajaran_id, n.jenis_raport, n.bulan,
                  k.nama_kelas, ta.nama, ta.semester, rp.is_published
         ORDER BY k.nama_kelas, n.jenis_raport, n.bulan",
        $params
    );
}

$bulan_names = BULAN_ID;
$jenis_label = ['bulanan'=>'Bulanan','sts'=>'STS','semester'=>'Semester'];
?>
<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Manajemen Raport</h1>
            <p class="page-subtitle">Kelola periode nilai &mdash; hapus atau perbaiki bulan yang salah</p>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" action="index.php" class="card mb-5">
        <div class="card-body">
            <input type="hidden" name="page" value="raport.manage">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
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
                <div>
                    <label class="form-label">Kelas <small class="text-gray-400">(opsional)</small></label>
                    <select name="kelas" class="form-control">
                        <option value="">-- Semua Kelas --</option>
                        <?php foreach ($kelas_list as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $k['id']==$kelas_id?'selected':'' ?>>
                            <?= e($k['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary" style="border-radius:30px">Tampilkan</button>
                </div>
            </div>
        </div>
    </form>

    <?php if ($ta_id && empty($periodes)): ?>
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            <p>Tidak ada data nilai untuk filter ini.</p>
        </div>
    </div>

    <?php elseif (!empty($periodes)): ?>
    <div class="card">
        <div class="card-header">
            <span>Daftar Periode Nilai</span>
            <span class="badge badge-orange"><?= count($periodes) ?> periode</span>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kelas</th>
                        <th>Jenis</th>
                        <th>Bulan</th>
                        <th>Siswa</th>
                        <th>Mapel</th>
                        <th>Total Nilai</th>
                        <th>Status</th>
                        <th style="min-width:180px">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($periodes as $p):
                    $bulan_nama = $p['bulan'] ? ($bulan_names[(int)$p['bulan']] ?? 'Bln '.$p['bulan']) : '-';
                    $jl = $jenis_label[$p['jenis_raport']] ?? $p['jenis_raport'];
                    $pub = (int)$p['is_published'];
                ?>
                <tr>
                    <td class="font-semibold"><?= e($p['nama_kelas']) ?></td>
                    <td><span class="badge <?= $p['jenis_raport']==='bulanan'?'badge-blue':($p['jenis_raport']==='sts'?'badge-purple':'badge-orange') ?>"><?= $jl ?></span></td>
                    <td><?= e($bulan_nama) ?></td>
                    <td class="text-center"><?= $p['jml_siswa'] ?></td>
                    <td class="text-center"><?= $p['jml_mapel'] ?></td>
                    <td class="text-center text-gray-500"><?= $p['jml_nilai'] ?></td>
                    <td>
                        <span class="badge <?= $pub ? 'badge-green' : 'badge-gray' ?>">
                            <?= $pub ? 'Rilis' : 'Draft' ?>
                        </span>
                    </td>
                    <td>
                        <div class="flex gap-1">
                            <!-- Tombol Edit Bulan -->
                            <?php if (in_array($p['jenis_raport'], ['bulanan','sts'])): ?>
                            <button type="button"
                                onclick="mgr_editBulan(<?= (int)$p['kelas_id'] ?>, <?= (int)$p['tahun_ajaran_id'] ?>, '<?= addslashes($p['jenis_raport']) ?>', <?= $p['bulan'] ? (int)$p['bulan'] : 0 ?>, '<?= addslashes($p['nama_kelas']) ?>', '<?= addslashes($bulan_nama) ?>')"
                                class="btn btn-sm btn-secondary tooltip-wrap" style="border-radius:30px">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                                <span class="tooltip-text">Edit Bulan</span>
                            </button>
                            <?php endif; ?>
                            <!-- Tombol Hapus Periode -->
                            <button type="button"
                                onclick="mgr_hapusPeriode(<?= (int)$p['kelas_id'] ?>, <?= (int)$p['tahun_ajaran_id'] ?>, '<?= addslashes($p['jenis_raport']) ?>', <?= $p['bulan'] ? (int)$p['bulan'] : 0 ?>, '<?= addslashes($p['nama_kelas']) ?>', '<?= addslashes($jl) ?>', '<?= addslashes($bulan_nama) ?>', <?= (int)$p['jml_nilai'] ?>, <?= $pub ?>)"
                                class="btn btn-sm btn-danger tooltip-wrap" style="border-radius:30px">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                                    <path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/>
                                </svg>
                                <span class="tooltip-text">Hapus Periode</span>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <rect x="3" y="4" width="18" height="18" rx="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <p>Pilih tahun ajaran untuk menampilkan data.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* ── Manajemen Raport: modal overlay ── */
.mgr-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.mgr-modal.is-open {
    display: flex !important;
}
</style>

<!-- ── Modal: Konfirmasi Hapus Periode ──────────────────────── -->
<div id="modalDelete" class="mgr-modal">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:460px;width:90%;
                box-shadow:0 8px 40px rgba(0,0,0,.2)">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
            <div style="width:44px;height:44px;background:#fee2e2;border-radius:50%;display:flex;
                        align-items:center;justify-content:center;flex-shrink:0">
                <svg width="22" height="22" fill="none" stroke="#dc2626" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                </svg>
            </div>
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:#1f2937;margin:0">Hapus Periode Raport</h3>
                <p style="font-size:.82rem;color:#6b7280;margin:2px 0 0" id="deleteDesc"></p>
            </div>
        </div>
        <div id="deleteWarning" style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;
             padding:10px 14px;font-size:.82rem;color:#92400e;margin-bottom:16px"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button type="button" onclick="mgr_closeModals()" class="btn btn-secondary" style="border-radius:30px">Batal</button>
            <form id="formDelete" method="POST" action="index.php?page=raport.manage&ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_periode">
                <input type="hidden" name="kelas_id" id="del_kelas_id">
                <input type="hidden" name="tahun_ajaran_id" id="del_ta_id">
                <input type="hidden" name="jenis_raport" id="del_jenis">
                <input type="hidden" name="bulan" id="del_bulan">
                <button type="submit" class="btn btn-danger" style="border-radius:30px">Ya, Hapus Semua</button>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Edit Bulan ────────────────────────────────────── -->
<div id="modalEditBulan" class="mgr-modal">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:420px;width:90%;
                box-shadow:0 8px 40px rgba(0,0,0,.2)">
        <h3 style="font-size:1rem;font-weight:700;color:#1f2937;margin:0 0 4px">Edit Bulan Raport</h3>
        <p id="editBulanDesc" style="font-size:.82rem;color:#6b7280;margin:0 0 18px"></p>
        <form id="formEditBulan" method="POST" action="index.php?page=raport.manage&ta=<?= $ta_id ?>&kelas=<?= $kelas_id ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_bulan">
            <input type="hidden" name="kelas_id" id="eb_kelas_id">
            <input type="hidden" name="tahun_ajaran_id" id="eb_ta_id">
            <input type="hidden" name="jenis_raport" id="eb_jenis">
            <input type="hidden" name="bulan_lama" id="eb_bulan_lama">
            <div class="form-group">
                <label class="form-label">Bulan Baru <span class="text-red-500">*</span></label>
                <select name="bulan_baru" id="eb_bulan_baru" class="form-control" required>
                    <option value="">-- Pilih Bulan --</option>
                    <?php foreach (BULAN_ID as $bk => $bv): ?>
                    <option value="<?= $bk ?>"><?= e($bv) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-gray-400">Semua data nilai di periode ini akan dipindah ke bulan yang dipilih.</small>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                <button type="button" onclick="mgr_closeModals()" class="btn btn-secondary" style="border-radius:30px">Batal</button>
                <button type="submit" class="btn btn-primary" style="border-radius:30px">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function mgr_closeModals() {
    document.getElementById('modalDelete').classList.remove('is-open');
    document.getElementById('modalEditBulan').classList.remove('is-open');
}

function mgr_hapusPeriode(kelasId, taId, jenis, bulan, namaKelas, jenisLabel, bulanNama, jmlNilai, isPub) {
    document.getElementById('del_kelas_id').value = kelasId;
    document.getElementById('del_ta_id').value    = taId;
    document.getElementById('del_jenis').value    = jenis;
    document.getElementById('del_bulan').value    = bulan || '';

    document.getElementById('deleteDesc').textContent =
        namaKelas + ' \u2014 ' + jenisLabel + (bulan ? ' ' + bulanNama : '');

    var warn = 'Tindakan ini akan menghapus <strong>' + jmlNilai + ' data nilai</strong> secara permanen.';
    if (isPub) warn += ' <strong>Raport ini sudah diterbitkan ke siswa \u2014 mereka tidak akan bisa melihatnya lagi setelah dihapus.</strong>';
    document.getElementById('deleteWarning').innerHTML = warn;

    document.getElementById('modalDelete').classList.add('is-open');
}

function mgr_editBulan(kelasId, taId, jenis, bulan, namaKelas, bulanLamaNama) {
    document.getElementById('eb_kelas_id').value   = kelasId;
    document.getElementById('eb_ta_id').value      = taId;
    document.getElementById('eb_jenis').value      = jenis;
    document.getElementById('eb_bulan_lama').value = bulan || '';
    document.getElementById('eb_bulan_baru').value = bulan || '';

    document.getElementById('editBulanDesc').textContent =
        'Pindahkan nilai ' + namaKelas + ' dari bulan ' + bulanLamaNama;

    document.getElementById('modalEditBulan').classList.add('is-open');
}

// Tutup modal jika klik backdrop
document.getElementById('modalDelete').addEventListener('click', function(e) {
    if (e.target === this) mgr_closeModals();
});
document.getElementById('modalEditBulan').addEventListener('click', function(e) {
    if (e.target === this) mgr_closeModals();
});
</script>
