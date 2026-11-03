<?php
/**
 * pages/jenis_raport/index.php
 * Kelola jenis raport — superadmin & admin saja
 */
require_role(['super_admin', 'admin']);

// ── Handle POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Token tidak valid.');
        header('Location: index.php?page=jenis_raport'); exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $nama        = trim($_POST['nama'] ?? '');
        $kode        = trim($_POST['kode'] ?? '');
        $tipe        = $_POST['tipe'] ?? 'bulanan';
        $tipe_basis  = $_POST['tipe_basis'] ?? 'bulanan';
        $deskripsi   = trim($_POST['deskripsi'] ?? '');
        $judul_cetak = trim($_POST['judul_cetak'] ?? '');
        $is_aktif    = isset($_POST['is_aktif']) ? 1 : 0;
        $urutan      = (int)($_POST['urutan'] ?? 0);

        // Validasi tipe & tipe_basis
        if (!in_array($tipe, ['bulanan', 'uts', 'semester'], true)) {
            $tipe = 'bulanan';
        }
        if (!in_array($tipe_basis, ['bulanan', 'uts', 'semester'], true)) {
            $tipe_basis = $tipe; // fallback ke tipe
        }

        if (!$nama) {
            flash('error', 'Nama jenis raport wajib diisi.');
            header('Location: index.php?page=jenis_raport'); exit;
        }
        if (!$kode) {
            flash('error', 'Kode jenis raport wajib diisi.');
            header('Location: index.php?page=jenis_raport'); exit;
        }

        // Normalisasi kode: lowercase, spasi → underscore
        $kode = strtolower(preg_replace('/\s+/', '_', $kode));

        if ($id) {
            // Cek duplikat kode (kecuali diri sendiri)
            $dup = db_row('SELECT id FROM jenis_raport WHERE kode = :k AND id != :id', [':k' => $kode, ':id' => $id]);
            if ($dup) {
                flash('error', 'Kode "' . $kode . '" sudah digunakan oleh jenis raport lain.');
                header('Location: index.php?page=jenis_raport'); exit;
            }
            db_execute(
                'UPDATE jenis_raport SET nama=:n, kode=:k, tipe=:t, tipe_basis=:tb, deskripsi=:d, judul_cetak=:jc, is_aktif=:a, urutan=:u WHERE id=:id',
                [':n' => $nama, ':k' => $kode, ':t' => $tipe, ':tb' => $tipe_basis, ':d' => $deskripsi, ':jc' => $judul_cetak ?: null, ':a' => $is_aktif, ':u' => $urutan, ':id' => $id]
            );
            flash('success', 'Jenis raport berhasil diperbarui.');
        } else {
            // Cek duplikat kode
            $dup = db_row('SELECT id FROM jenis_raport WHERE kode = :k', [':k' => $kode]);
            if ($dup) {
                flash('error', 'Kode "' . $kode . '" sudah digunakan.');
                header('Location: index.php?page=jenis_raport'); exit;
            }
            db_execute(
                'INSERT INTO jenis_raport (nama, kode, tipe, tipe_basis, deskripsi, judul_cetak, is_aktif, urutan) VALUES (:n,:k,:t,:tb,:d,:jc,:a,:u)',
                [':n' => $nama, ':k' => $kode, ':t' => $tipe, ':tb' => $tipe_basis, ':d' => $deskripsi, ':jc' => $judul_cetak ?: null, ':a' => $is_aktif, ':u' => $urutan]
            );
            flash('success', 'Jenis raport berhasil ditambahkan.');
        }

    } elseif ($action === 'toggle_aktif') {
        $id = (int)$_POST['id'];
        $row = db_row('SELECT is_aktif FROM jenis_raport WHERE id = :id', [':id' => $id]);
        if ($row) {
            $new = $row['is_aktif'] ? 0 : 1;
            db_execute('UPDATE jenis_raport SET is_aktif = :a WHERE id = :id', [':a' => $new, ':id' => $id]);
            flash('success', 'Status jenis raport diperbarui.');
        }

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        db_execute('DELETE FROM jenis_raport WHERE id = :id', [':id' => $id]);
        flash('success', 'Jenis raport berhasil dihapus.');
    }

    header('Location: index.php?page=jenis_raport'); exit;
}

// ── Data ──────────────────────────────────────────────────────
$list = db_query('SELECT * FROM jenis_raport ORDER BY urutan ASC, id ASC');

// Helper label tipe
$tipe_label = ['bulanan' => 'Bulanan', 'uts' => 'UTS', 'semester' => 'Semester'];
$tipe_badge = ['bulanan' => 'badge-blue', 'uts' => 'badge-purple', 'semester' => 'badge-orange'];
$basis_label = ['bulanan' => 'Format Bulanan', 'uts' => 'Format UTS', 'semester' => 'Format Semester'];
?>
<style>
/* ── Responsive overrides khusus halaman Jenis Raport ── */
@media (max-width: 640px) {
    /* Kolom sekunder disembunyikan di mobile */
    .jr-col-kode,
    .jr-col-format,
    .jr-col-judul,
    .jr-col-urutan { display: none; }

    /* Tombol aksi susun ke bawah */
    .jr-aksi-wrap { flex-direction: column; align-items: stretch; }
    .jr-aksi-wrap .btn,
    .jr-aksi-wrap form { width: 100%; }
    .jr-aksi-wrap form button { width: 100%; }

    /* Modal: grid 2-kolom jadi 1 kolom di HP kecil */
    .jr-grid-2 { grid-template-columns: 1fr !important; }
}
</style>

<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Jenis Raport</h1>
            <p class="page-subtitle">Kelola jenis raport (Bulanan, UTS, Semester)</p>
        </div>
        <button onclick="openModalJR()" class="btn btn-primary" style="border-radius:30px">+ Tambah</button>
    </div>

    <!-- Ringkasan: 1 kolom di mobile, 3 di desktop -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
        <?php
        $cnt_bulanan  = count(array_filter($list, fn($r) => $r['tipe'] === 'bulanan'));
        $cnt_uts      = count(array_filter($list, fn($r) => $r['tipe'] === 'uts'));
        $cnt_semester = count(array_filter($list, fn($r) => $r['tipe'] === 'semester'));
        $tiles = [
            ['label' => 'Bulanan',  'count' => $cnt_bulanan,  'badge' => 'badge-blue'],
            ['label' => 'UTS',      'count' => $cnt_uts,      'badge' => 'badge-purple'],
            ['label' => 'Semester', 'count' => $cnt_semester, 'badge' => 'badge-orange'],
        ];
        foreach ($tiles as $t):
        ?>
        <div class="card" style="margin-bottom:0">
            <div class="card-body" style="padding:14px 18px;display:flex;align-items:center;gap:12px">
                <span class="badge <?= $t['badge'] ?>" style="font-size:.9rem;padding:6px 14px"><?= $t['count'] ?></span>
                <span class="font-semibold text-gray-700"><?= $t['label'] ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-header">Daftar Jenis Raport</div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama</th>
                        <th class="jr-col-kode">Kode</th>
                        <th>Tipe</th>
                        <th class="jr-col-format">Format Nilai</th>
                        <th class="jr-col-judul">Judul Cetak</th>
                        <th class="jr-col-urutan">Urutan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-6 text-gray-400">Belum ada data.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($list as $i => $jr): ?>
                    <tr>
                        <td class="text-gray-400 text-xs"><?= $i + 1 ?></td>
                        <td class="font-semibold">
                            <?= e($jr['nama']) ?>
                            <!-- Info tambahan di mobile (hanya muncul saat kolom lain disembunyikan) -->
                            <div class="text-xs text-gray-400 mt-1 hidden sm:hidden" style="display:none" class="jr-mobile-meta">
                                <code style="font-size:.75rem;background:#f3f4f6;padding:1px 5px;border-radius:4px"><?= e($jr['kode']) ?></code>
                            </div>
                        </td>
                        <td class="jr-col-kode"><code style="font-size:.78rem;background:#f3f4f6;padding:2px 6px;border-radius:4px"><?= e($jr['kode']) ?></code></td>
                        <td><span class="badge <?= $tipe_badge[$jr['tipe']] ?? 'badge-gray' ?>"><?= $tipe_label[$jr['tipe']] ?? $jr['tipe'] ?></span></td>
                        <td class="jr-col-format"><span class="text-xs text-gray-500"><?= e($basis_label[$jr['tipe_basis'] ?? $jr['tipe']] ?? '-') ?></span></td>
                        <td class="jr-col-judul text-xs text-gray-600"><?= $jr['judul_cetak'] ? e($jr['judul_cetak']) : '<span class="text-gray-300">—</span>' ?></td>
                        <td class="jr-col-urutan text-center"><?= (int)$jr['urutan'] ?></td>
                        <td>
                            <?php if ($jr['is_aktif']): ?>
                                <span class="badge badge-green">Aktif</span>
                            <?php else: ?>
                                <span class="badge badge-gray">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="flex gap-1 jr-aksi-wrap">
                                <button onclick='editJR(<?= json_encode($jr) ?>)'
                                    class="btn btn-sm btn-secondary" style="border-radius:30px">Edit</button>

                                <!-- Toggle aktif/nonaktif -->
                                <form method="POST" action="index.php?page=jenis_raport" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_aktif">
                                    <input type="hidden" name="id" value="<?= $jr['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $jr['is_aktif'] ? 'btn-secondary' : 'btn-primary' ?>"
                                        style="border-radius:30px">
                                        <?= $jr['is_aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>

                                <!-- Hapus -->
                                <form method="POST" action="index.php?page=jenis_raport" style="display:inline"
                                    onsubmit="return confirm('Hapus jenis raport ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $jr['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                        style="border-radius:30px">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah / Edit -->
<div class="modal-overlay" id="modalJR">
    <div class="modal" style="max-width:460px;display:flex;flex-direction:column;max-height:90vh;overflow:hidden">
        <div class="modal-header" style="flex-shrink:0">
            <span id="modalJRTitle">Tambah Jenis Raport</span>
            <button onclick="Modal.close('modalJR')"
                class="text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="index.php?page=jenis_raport"
              style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="jrId" value="">
            <div class="modal-body" style="overflow-y:auto;flex:1;min-height:0">

                <div class="form-group">
                    <label class="form-label">Nama Jenis Raport <span class="text-red-500">*</span></label>
                    <input type="text" name="nama" id="jrNama" class="form-control" required
                        placeholder="contoh: Raport Bulanan Diniyah">
                </div>

                <div class="form-group">
                    <label class="form-label">Kode <span class="text-red-500">*</span></label>
                    <input type="text" name="kode" id="jrKode" class="form-control" required
                        placeholder="contoh: bulanan_diniyah">
                    <div class="text-xs text-gray-400 mt-1">Huruf kecil, spasi otomatis diganti underscore. Harus unik.</div>
                </div>

                <div class="grid grid-cols-2 gap-3 jr-grid-2">
                    <div class="form-group">
                        <label class="form-label">Tipe <span class="text-red-500">*</span></label>
                        <select name="tipe" id="jrTipe" class="form-control">
                            <option value="bulanan">Bulanan</option>
                            <option value="uts">UTS</option>
                            <option value="semester">Semester</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Format Nilai Basis</label>
                        <select name="tipe_basis" id="jrTipeBasis" class="form-control">
                            <option value="bulanan">Format Bulanan</option>
                            <option value="uts">Format UTS</option>
                            <option value="semester">Format Semester</option>
                        </select>
                        <div class="text-xs text-gray-400 mt-1">Logika perhitungan nilai yang digunakan.</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Judul Cetak Kustom</label>
                    <input type="text" name="judul_cetak" id="jrJudulCetak" class="form-control"
                        placeholder="Kosongkan = gunakan judul otomatis">
                    <div class="text-xs text-gray-400 mt-1">Contoh: <em>LAPORAN BULANAN DINIYAH (PERIODE: %s)</em> — tulis <code>%s</code> untuk sisipkan bulan/semester otomatis.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Deskripsi</label>
                    <textarea name="deskripsi" id="jrDeskripsi" class="form-control" rows="2"
                        placeholder="Deskripsi singkat (opsional)"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3 jr-grid-2">
                    <div class="form-group">
                        <label class="form-label">Urutan Tampil</label>
                        <input type="number" name="urutan" id="jrUrutan" class="form-control"
                            value="0" min="0" placeholder="0">
                    </div>
                    <div class="form-group" style="padding-top:28px">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_aktif" value="1" id="jrAktif"
                                class="w-4 h-4 accent-orange-500" checked>
                            <span class="form-label mb-0">Aktif</span>
                        </label>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" onclick="Modal.close('modalJR')" class="btn btn-secondary"
                    style="border-radius:30px">Batal</button>
                <button type="submit" class="btn btn-primary" style="border-radius:30px">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalJR() {
    document.getElementById('modalJRTitle').textContent = 'Tambah Jenis Raport';
    document.getElementById('jrId').value           = '';
    document.getElementById('jrNama').value         = '';
    document.getElementById('jrKode').value         = '';
    document.getElementById('jrTipe').value         = 'bulanan';
    document.getElementById('jrTipeBasis').value    = 'bulanan';
    document.getElementById('jrJudulCetak').value   = '';
    document.getElementById('jrDeskripsi').value    = '';
    document.getElementById('jrUrutan').value       = '0';
    document.getElementById('jrAktif').checked      = true;
    Modal.open('modalJR');
}

function editJR(data) {
    document.getElementById('modalJRTitle').textContent = 'Edit Jenis Raport';
    document.getElementById('jrId').value           = data.id;
    document.getElementById('jrNama').value         = data.nama;
    document.getElementById('jrKode').value         = data.kode;
    document.getElementById('jrTipe').value         = data.tipe;
    document.getElementById('jrTipeBasis').value    = data.tipe_basis || data.tipe;
    document.getElementById('jrJudulCetak').value   = data.judul_cetak || '';
    document.getElementById('jrDeskripsi').value    = data.deskripsi || '';
    document.getElementById('jrUrutan').value       = data.urutan || 0;
    document.getElementById('jrAktif').checked      = data.is_aktif == 1;
    Modal.open('modalJR');
}

// Auto-generate kode dari nama (hanya saat tambah baru)
document.getElementById('jrNama').addEventListener('input', function () {
    if (document.getElementById('jrId').value !== '') return; // skip saat edit
    const kode = this.value.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
    document.getElementById('jrKode').value = kode;
});
</script>
