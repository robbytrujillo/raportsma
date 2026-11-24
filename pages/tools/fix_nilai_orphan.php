<?php
/**
 * pages/tools/fix_nilai_orphan.php
 * Deteksi & perbaiki nilai orphan — mapel tidak sesuai penugasan di kelas tsb.
 * Admin-only. Akses: ?page=tools.fix_nilai_orphan
 */
require_role(['admin', 'super_admin']);

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    // Helper: cari pengganti mapel berdasarkan nama di kelas+TA
    $cari_pengganti = function(int $kelas_id, int $ta_id, string $nama_mapel): ?array {
        return db_row(
            "SELECT m.id FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id = m.id
             WHERE gmk.kelas_id = :kid AND gmk.tahun_ajaran_id = :ta AND m.nama_mapel = :nama
             LIMIT 1",
            [':kid'=>$kelas_id,':ta'=>$ta_id,':nama'=>$nama_mapel]
        );
    };

    if ($action === 'pindah_semua') {
        $dipindah = 0; $gagal = 0;
        $orphans = db_query(
            "SELECT n.id, n.mapel_id, n.kelas_id, n.tahun_ajaran_id, m.nama_mapel
             FROM nilai n LEFT JOIN mapel m ON m.id = n.mapel_id
             WHERE NOT EXISTS (SELECT 1 FROM guru_mapel_kelas gmk
                 WHERE gmk.mapel_id=n.mapel_id AND gmk.kelas_id=n.kelas_id AND gmk.tahun_ajaran_id=n.tahun_ajaran_id)"
        );
        foreach ($orphans as $o) {
            $pg = $cari_pengganti((int)$o['kelas_id'], (int)$o['tahun_ajaran_id'], $o['nama_mapel']);
            if ($pg) { db_execute("UPDATE nilai SET mapel_id=:m WHERE id=:id",[':m'=>$pg['id'],':id'=>$o['id']]); $dipindah++; }
            else $gagal++;
        }
        $msg = "{$dipindah} nilai berhasil dipindahkan.";
        if ($gagal) $msg .= " {$gagal} tidak bisa dipindah (perlu hapus manual).";
        flash($dipindah > 0 ? 'success' : 'error', $msg);
        header('Location: /tools.fix_nilai_orphan'); exit;
    }

    if ($action === 'pindah_terpilih') {
        $dipindah = 0;
        foreach (array_filter(array_map('intval', (array)($_POST['orphan_ids'] ?? []))) as $nid) {
            $o = db_row("SELECT n.id,n.mapel_id,n.kelas_id,n.tahun_ajaran_id,m.nama_mapel
                         FROM nilai n LEFT JOIN mapel m ON m.id=n.mapel_id WHERE n.id=:id",[':id'=>$nid]);
            if (!$o) continue;
            $pg = $cari_pengganti((int)$o['kelas_id'], (int)$o['tahun_ajaran_id'], $o['nama_mapel']);
            if ($pg) { db_execute("UPDATE nilai SET mapel_id=:m WHERE id=:id",[':m'=>$pg['id'],':id'=>$o['id']]); $dipindah++; }
        }
        flash('success', "{$dipindah} nilai berhasil dipindahkan.");
        header('Location: /tools.fix_nilai_orphan'); exit;
    }

    // ── PINDAH KE MAPEL MANUAL (untuk yang tidak ada pengganti otomatis) ──
    if ($action === 'pindah_ke_mapel') {
        $ids        = array_filter(array_map('intval', (array)($_POST['orphan_ids'] ?? [])));
        $target_mid = (int)($_POST['target_mapel_id'] ?? 0);
        $dipindah   = 0;
        if ($target_mid && $ids) {
            // Verifikasi mapel tujuan valid (ada di guru_mapel_kelas)
            $valid = db_row("SELECT m.id, m.nama_mapel FROM mapel m
                             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
                             WHERE m.id=:mid LIMIT 1", [':mid'=>$target_mid]);
            if ($valid) {
                $ph = implode(',', $ids);
                $dipindah = db_execute(
                    "UPDATE nilai SET mapel_id=:mid WHERE id IN ($ph)
                     AND NOT EXISTS (SELECT 1 FROM guru_mapel_kelas gmk
                         WHERE gmk.mapel_id=nilai.mapel_id AND gmk.kelas_id=nilai.kelas_id AND gmk.tahun_ajaran_id=nilai.tahun_ajaran_id)",
                    [':mid'=>$target_mid]
                );
                flash('success', "{$dipindah} nilai berhasil dipindahkan ke \"{$valid['nama_mapel']}\".");
            } else {
                flash('error', 'Mapel tujuan tidak valid.');
            }
        } else {
            flash('error', 'Pilih mapel tujuan terlebih dahulu.');
        }
        header('Location: /tools.fix_nilai_orphan'); exit;
    }

    if ($action === 'hapus_semua') {
        $deleted = db_execute("DELETE n FROM nilai n WHERE NOT EXISTS (
            SELECT 1 FROM guru_mapel_kelas gmk
            WHERE gmk.mapel_id=n.mapel_id AND gmk.kelas_id=n.kelas_id AND gmk.tahun_ajaran_id=n.tahun_ajaran_id)");
        flash('success', "Berhasil menghapus {$deleted} nilai tidak sesuai.");
        header('Location: /tools.fix_nilai_orphan'); exit;
    }

    if ($action === 'hapus_terpilih') {
        $ids = array_filter(array_map('intval', (array)($_POST['orphan_ids'] ?? [])));
        if ($ids) {
            $ph = implode(',', $ids);
            $deleted = db_execute("DELETE n FROM nilai n WHERE n.id IN ($ph)
                AND NOT EXISTS (SELECT 1 FROM guru_mapel_kelas gmk
                WHERE gmk.mapel_id=n.mapel_id AND gmk.kelas_id=n.kelas_id AND gmk.tahun_ajaran_id=n.tahun_ajaran_id)");
            flash('success', "Berhasil menghapus {$deleted} nilai tidak sesuai.");
        }
        header('Location: /tools.fix_nilai_orphan'); exit;
    }
}

// ── Ambil semua orphan ────────────────────────────────────────────────────
$orphan_rows = db_query(
    "SELECT n.id, n.jenis_raport, n.bulan, n.nilai_akhir, n.predikat,
            u_siswa.nama_lengkap AS nama_siswa, s.nis,
            m.id AS mapel_id, m.nama_mapel, m.kelompok,
            k.id AS kelas_id, k.nama_kelas,
            ta.id AS ta_id, ta.nama AS ta_nama, ta.semester,
            u_guru.nama_lengkap AS nama_guru
     FROM nilai n
     LEFT JOIN users u_siswa ON u_siswa.id=n.siswa_user_id
     LEFT JOIN siswa s       ON s.user_id=n.siswa_user_id
     LEFT JOIN mapel m       ON m.id=n.mapel_id
     LEFT JOIN kelas k       ON k.id=n.kelas_id
     LEFT JOIN tahun_ajaran ta ON ta.id=n.tahun_ajaran_id
     LEFT JOIN users u_guru  ON u_guru.id=n.guru_user_id
     WHERE NOT EXISTS (SELECT 1 FROM guru_mapel_kelas gmk
         WHERE gmk.mapel_id=n.mapel_id AND gmk.kelas_id=n.kelas_id AND gmk.tahun_ajaran_id=n.tahun_ajaran_id)
     ORDER BY ta.id DESC, k.nama_kelas, u_siswa.nama_lengkap, m.nama_mapel"
);

// Cache pengganti
$pg_cache = [];
foreach ($orphan_rows as &$r) {
    $ck = $r['ta_id'].'_'.$r['kelas_id'].'_'.$r['nama_mapel'];
    if (!array_key_exists($ck, $pg_cache)) {
        $pg_cache[$ck] = db_row(
            "SELECT m.id, m.nama_mapel, m.kelompok FROM mapel m
             INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
             WHERE gmk.kelas_id=:kid AND gmk.tahun_ajaran_id=:ta AND m.nama_mapel=:nama LIMIT 1",
            [':kid'=>$r['kelas_id'],':ta'=>$r['ta_id'],':nama'=>$r['nama_mapel']]
        ) ?: null;
    }
    $r['pengganti'] = $pg_cache[$ck];
}
unset($r);

$total      = count($orphan_rows);
$bisa_pindah= count(array_filter($orphan_rows, fn($r) => $r['pengganti']));
$hanya_hapus= $total - $bisa_pindah;

// Kelompokkan per kelas+ta — simpan juga daftar unik kelas & mapel untuk filter
$grouped   = [];
$kelas_list= [];   // untuk dropdown filter kelas
$mapel_set = [];   // untuk dropdown filter mapel

foreach ($orphan_rows as $r) {
    $gk = $r['ta_id'].'_'.$r['kelas_id'];
    if (!isset($grouped[$gk])) {
        $grouped[$gk] = [
            'ta_id'=>$r['ta_id'], 'kelas_id'=>$r['kelas_id'],
            'ta_nama'=>$r['ta_nama'].'/'.$r['semester'],
            'nama_kelas'=>$r['nama_kelas'], 'rows'=>[],
        ];
        $kelas_list[$r['kelas_id']] = $r['nama_kelas'];
    }
    $grouped[$gk]['rows'][] = $r;
    $mapel_set[$r['nama_mapel']] = true;
}
asort($kelas_list);
ksort($mapel_set);

// Ambil daftar mapel resmi per kelas+ta (dari guru_mapel_kelas) untuk modal pindah manual
$mapel_per_kelas = [];
foreach ($grouped as $gk => $grp) {
    $mapel_per_kelas[$gk] = db_query(
        "SELECT DISTINCT m.id, m.nama_mapel, m.kelompok FROM mapel m
         INNER JOIN guru_mapel_kelas gmk ON gmk.mapel_id=m.id
         WHERE gmk.kelas_id=:kid AND gmk.tahun_ajaran_id=:ta
         ORDER BY m.kelompok, m.urutan, m.nama_mapel",
        [':kid'=>$grp['kelas_id'], ':ta'=>$grp['ta_id']]
    );
}

$bulan_id = BULAN_ID;
// Tab kelas aktif (dari GET)
$active_kelas = (int)($_GET['kelas_tab'] ?? array_key_first($kelas_list) ?? 0);
?>

<div class="page-content" style="max-width:1100px">

<!-- Header -->
<div class="page-header" style="margin-bottom:12px">
    <div>
        <h1 class="page-title">Perbaiki Nilai Tidak Sesuai</h1>
        <p class="page-subtitle">Deteksi &amp; perbaiki nilai yang mapel-nya tidak sesuai penugasan guru di kelas tersebut</p>
    </div>
</div>

<?php if ($total === 0): ?>
<div class="card">
    <div class="card-body" style="text-align:center;padding:48px 20px">
        <svg width="48" height="48" fill="none" stroke="#22c55e" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 12px">
            <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
        <p style="font-weight:700;color:#15803d;font-size:1rem">Tidak ada nilai tidak sesuai.</p>
        <p style="color:#57606a;font-size:.875rem">Semua data nilai sudah sesuai penugasan guru.</p>
    </div>
</div>

<?php else: ?>

<!-- ══ CARD UTAMA ══════════════════════════════════════════════════════════ -->
<div class="card" style="overflow:visible">

    <!-- ── Ringkasan + tombol global ── -->
    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;gap:0;flex-wrap:wrap;align-items:stretch">
        <!-- Statistik -->
        <div style="display:flex;gap:20px;flex-wrap:wrap;flex:1;align-items:center;border-right:1px solid #e5e7eb;padding-right:20px;margin-right:20px">
            <?php foreach ([
                [$total,       '#dc2626','Total Tidak Sesuai'],
                [$bisa_pindah, '#16a34a','Bisa Dipindah'],
                [$hanya_hapus, '#6b7280','Perlu Dihapus'],
                [count($grouped),'#ea580c','Kelas Terdampak'],
            ] as [$v,$c,$l]): ?>
            <div style="text-align:center;min-width:60px">
                <div style="font-size:1.5rem;font-weight:800;color:<?= $c ?>;line-height:1"><?= $v ?></div>
                <div style="font-size:.7rem;color:#57606a;margin-top:2px"><?= $l ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Tombol aksi global -->
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?php if ($bisa_pindah > 0): ?>
            <form method="POST" action="/tools.fix_nilai_orphan"
                  onsubmit="return confirm('Pindahkan <?= $bisa_pindah ?> nilai ke mapel yang benar?\nNilai tidak akan hilang, hanya mapel_id yang diperbarui.')">
                <?= csrf_field() ?><input type="hidden" name="action" value="pindah_semua">
                <button class="btn btn-sm" style="border-radius:20px;background:#16a34a;color:#fff;border:none;font-size:.8rem">
                    ✓ Pindahkan Semua (<?= $bisa_pindah ?>)
                </button>
            </form>
            <?php endif; ?>
            <?php if ($hanya_hapus > 0): ?>
            <form method="POST" action="/tools.fix_nilai_orphan"
                  onsubmit="return confirm('Hapus <?= $hanya_hapus ?> nilai yang tidak ada mapel penggantinya?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="hapus_semua">
                <button class="btn btn-sm btn-danger" style="border-radius:20px;font-size:.8rem">
                    🗑 Hapus Tak Bisa Pindah (<?= $hanya_hapus ?>)
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Filter / Search bar ── -->
    <div style="padding:12px 20px;background:#f9fafb;border-bottom:1px solid #e5e7eb;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <div style="position:relative;flex:1;min-width:180px;max-width:280px">
            <svg width="14" height="14" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"
                 style="position:absolute;left:10px;top:50%;transform:translateY(-50%)">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="searchInput" placeholder="Cari murid / mapel / guru..."
                   style="width:100%;padding:6px 10px 6px 30px;border:1px solid #d1d5db;border-radius:20px;font-size:.82rem;outline:none">
        </div>
        <div>
            <select id="filterMapel" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:20px;font-size:.82rem;outline:none;background:#fff">
                <option value="">Semua Mapel</option>
                <?php foreach (array_keys($mapel_set) as $mn): ?>
                <option value="<?= e($mn) ?>"><?= e($mn) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <select id="filterStatus" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:20px;font-size:.82rem;outline:none;background:#fff">
                <option value="">Semua Status</option>
                <option value="pindah">Bisa Dipindah</option>
                <option value="hapus">Perlu Dihapus</option>
            </select>
        </div>
        <div style="margin-left:auto;font-size:.78rem;color:#9ca3af" id="filterCount"></div>
    </div>

    <!-- ── Tab kelas ── -->
    <div style="display:flex;gap:0;flex-wrap:nowrap;overflow-x:auto;border-bottom:2px solid #e5e7eb;background:#fff;scroll-behavior:smooth" id="tabBar">
        <?php foreach ($grouped as $gk => $grp):
            $grp_total = count($grp['rows']);
            $grp_pindah = count(array_filter($grp['rows'], fn($r) => $r['pengganti']));
            $is_active  = ($grp['kelas_id'] == $active_kelas);
        ?>
        <button onclick="switchTab('<?= $gk ?>')"
            id="tab_<?= $gk ?>"
            data-kelas="<?= e($grp['nama_kelas']) ?>"
            style="flex-shrink:0;padding:10px 16px;border:none;background:transparent;cursor:pointer;
                   font-size:.82rem;font-weight:<?= $is_active?'700':'500' ?>;
                   color:<?= $is_active?'var(--primary,#f97316)':'#57606a' ?>;
                   border-bottom:<?= $is_active?'2px solid var(--primary,#f97316)':'2px solid transparent' ?>;
                   margin-bottom:-2px;white-space:nowrap;transition:color .15s">
            <?= e($grp['nama_kelas']) ?>
            <span style="display:inline-flex;align-items:center;justify-content:center;
                         width:18px;height:18px;border-radius:50%;font-size:.68rem;font-weight:700;margin-left:4px;
                         background:<?= $grp_pindah===$grp_total?'#dcfce7':($grp_pindah>0?'#fef9c3':'#fef2f2') ?>;
                         color:<?= $grp_pindah===$grp_total?'#15803d':($grp_pindah>0?'#b45309':'#dc2626') ?>">
                <?= $grp_total ?>
            </span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ── Panel per kelas ── -->
    <?php foreach ($grouped as $gk => $grp):
        $grp_bisa  = count(array_filter($grp['rows'], fn($r) => $r['pengganti']));
        $grp_hapus = count($grp['rows']) - $grp_bisa;
        $is_active = ($grp['kelas_id'] == $active_kelas);
    ?>
    <div id="panel_<?= $gk ?>" style="display:<?= $is_active?'block':'none' ?>">

        <!-- Sub-header panel -->
        <div style="padding:10px 20px;background:#fffbf5;border-bottom:1px solid #f3f4f6;
                    display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <span style="font-size:.85rem;color:#57606a">TA: <strong><?= e($grp['ta_nama']) ?></strong></span>
            <span style="font-size:.82rem;color:#16a34a;font-weight:600"><?= $grp_bisa ?> bisa dipindah</span>
            <?php if ($grp_hapus): ?>
            <span style="font-size:.82rem;color:#dc2626;font-weight:600"><?= $grp_hapus ?> perlu hapus</span>
            <?php endif; ?>
            <span style="font-size:.78rem;color:#9ca3af" id="visibleCount_<?= $gk ?>"></span>
        </div>

        <!-- Tabel -->
        <form method="POST" action="/tools.fix_nilai_orphan" id="form_<?= $gk ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="act_<?= $gk ?>" value="pindah_terpilih">
            <div style="overflow-x:auto">
            <table class="data-table" style="font-size:.82rem;min-width:700px" id="tbl_<?= $gk ?>">
                <thead>
                    <tr>
                        <th style="width:32px;text-align:center">
                            <input type="checkbox" onclick="toggleAll('<?= $gk ?>')" style="cursor:pointer">
                        </th>
                        <th>Murid</th>
                        <th>Mapel Tidak Sesuai → Mapel Benar</th>
                        <th style="width:95px">Jenis/Bulan</th>
                        <th>Guru Input</th>
                        <th style="width:65px;text-align:center">Nilai</th>
                        <th style="width:45px;text-align:center">Pred</th>
                        <th style="width:90px;text-align:center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grp['rows'] as $r):
                    $ada = $r['pengganti'] !== null;
                    $pred_colors = ['A'=>'#16a34a','B'=>'#2563eb','C'=>'#ca8a04','D'=>'#dc2626'];
                ?>
                <tr class="orphan-row" style="background:<?= $ada?'#f0fdf4':'#fef2f2' ?>"
                    data-siswa="<?= strtolower(e($r['nama_siswa'])) ?>"
                    data-mapel="<?= strtolower(e($r['nama_mapel'])) ?>"
                    data-guru="<?= strtolower(e($r['nama_guru'])) ?>"
                    data-status="<?= $ada?'pindah':'hapus' ?>">
                    <td style="text-align:center">
                        <input type="checkbox" name="orphan_ids[]" value="<?= $r['id'] ?>"
                               class="row-chk" data-action="<?= $ada?'pindah':'hapus' ?>"
                               style="cursor:pointer;accent-color:<?= $ada?'#16a34a':'#dc2626' ?>">
                    </td>
                    <td>
                        <div style="font-weight:600;color:#1f2328"><?= e($r['nama_siswa']??'—') ?></div>
                        <div style="font-size:.7rem;color:#9ca3af"><?= e($r['nis']??'') ?></div>
                    </td>
                    <td>
                        <span style="font-weight:600;color:#dc2626;text-decoration:line-through"><?= e($r['nama_mapel']??'—') ?></span>
                        <span style="font-size:.7rem;color:#9ca3af;margin-left:3px">Kel.<?= e($r['kelompok']) ?> · ID<?= (int)$r['mapel_id'] ?></span>
                        <?php if ($ada): ?>
                        <span style="color:#9ca3af;margin:0 3px">→</span>
                        <span style="font-weight:600;color:#16a34a"><?= e($r['pengganti']['nama_mapel']) ?></span>
                        <span style="font-size:.7rem;color:#9ca3af;margin-left:3px">Kel.<?= e($r['pengganti']['kelompok']) ?> · ID<?= (int)$r['pengganti']['id'] ?></span>
                        <?php else: ?>
                        <span style="color:#9ca3af;margin:0 3px">→</span>
                        <span style="font-size:.75rem;color:#9ca3af;font-style:italic">tidak ada pengganti</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.78rem">
                        <?= e($r['jenis_raport']) ?><?= $r['bulan'] ? '/<br>'.e($bulan_id[$r['bulan']]??$r['bulan']) : '' ?>
                    </td>
                    <td style="font-size:.78rem"><?= e($r['nama_guru']??'—') ?></td>
                    <td style="text-align:center;font-weight:700;color:#ea580c"><?= number_format((float)$r['nilai_akhir'],2) ?></td>
                    <td style="text-align:center;font-weight:700;color:<?= $pred_colors[$r['predikat']]??'#9ca3af' ?>"><?= e($r['predikat']??'—') ?></td>
                    <td style="text-align:center">
                        <?php if ($ada): ?>
                        <button type="button"
                            onclick="pindahSatu(<?= $r['id'] ?>,'<?= $gk ?>')"
                            style="padding:3px 10px;border-radius:14px;background:#16a34a;color:#fff;border:none;font-size:.75rem;cursor:pointer">
                            Pindah
                        </button>
                        <?php else: ?>
                        <button type="button"
                            onclick="hapusSatu(<?= $r['id'] ?>,'<?= addslashes(e($r['nama_siswa'])) ?>','<?= addslashes(e($r['nama_mapel'])) ?>','<?= $gk ?>')"
                            style="padding:3px 10px;border-radius:14px;background:#dc2626;color:#fff;border:none;font-size:.75rem;cursor:pointer">
                            Hapus
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Toolbar bulk -->
            <div id="toolbar_<?= $gk ?>" style="padding:10px 16px;border-top:1px solid #e5e7eb;display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#fafafa">
                <button type="button"
                    onclick="aksiDipilih('<?= $gk ?>','pindah')"
                    class="btn btn-sm" style="border-radius:20px;background:#16a34a;color:#fff;border:none;font-size:.8rem">
                    ✓ Pindah Dipilih
                </button>
                <button type="button"
                    onclick="aksiDipilih('<?= $gk ?>','hapus')"
                    class="btn btn-sm btn-danger" style="border-radius:20px;font-size:.8rem">
                    🗑 Hapus Dipilih
                </button>
                <span class="sel-count" style="font-size:.75rem;color:#9ca3af">Centang baris → pilih aksi</span>
            </div>
        </form>
    </div>
    <?php endforeach; ?>

</div><!-- /.card -->
<?php endif; ?>
</div><!-- /.page-content -->

<script>
// ── Tab switching ──────────────────────────────────────────────────────────
const PRIMARY = getComputedStyle(document.documentElement).getPropertyValue('--primary') || '#f97316';

function switchTab(gk) {
    // Sembunyikan semua panel, non-aktifkan semua tab
    document.querySelectorAll('[id^="panel_"]').forEach(p => p.style.display = 'none');
    document.querySelectorAll('[id^="tab_"]').forEach(t => {
        t.style.fontWeight   = '500';
        t.style.color        = '#57606a';
        t.style.borderBottom = '2px solid transparent';
    });
    // Tampilkan panel & aktifkan tab
    const panel = document.getElementById('panel_' + gk);
    const tab   = document.getElementById('tab_'   + gk);
    if (panel) panel.style.display = 'block';
    if (tab)   {
        tab.style.fontWeight   = '700';
        tab.style.color        = PRIMARY.trim() || '#f97316';
        tab.style.borderBottom = '2px solid ' + (PRIMARY.trim() || '#f97316');
    }
    // Scroll tab into view
    if (tab) tab.scrollIntoView({behavior:'smooth', inline:'nearest', block:'nearest'});
    // Reset filter saat ganti tab
    applyFilter();
}

// ── Filter / Search ────────────────────────────────────────────────────────
function applyFilter() {
    const q      = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
    const mapel  = (document.getElementById('filterMapel')?.value || '').toLowerCase();
    const status = document.getElementById('filterStatus')?.value || '';

    // Temukan panel yang aktif
    const activePanel = [...document.querySelectorAll('[id^="panel_"]')].find(p => p.style.display !== 'none');
    if (!activePanel) return;

    const gk = activePanel.id.replace('panel_', '');
    const rows = activePanel.querySelectorAll('.orphan-row');
    let visible = 0;

    rows.forEach(row => {
        const matchQ = !q ||
            row.dataset.siswa.includes(q) ||
            row.dataset.mapel.includes(q) ||
            row.dataset.guru.includes(q);
        const matchMapel  = !mapel  || row.dataset.mapel === mapel;
        const matchStatus = !status || row.dataset.status === status;
        const show = matchQ && matchMapel && matchStatus;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const countEl = document.getElementById('visibleCount_' + gk);
    if (countEl) countEl.textContent = visible + ' dari ' + rows.length + ' baris ditampilkan';

    // Update label count di header filter
    document.getElementById('filterCount').textContent =
        (q || mapel || status) ? visible + ' baris cocok' : '';

    // Sync master checkbox & counter setelah filter berubah
    updateSelectedCount(gk);
    syncMasterCheckbox(gk);
}

document.getElementById('searchInput')?.addEventListener('input',  applyFilter);
document.getElementById('filterMapel')?.addEventListener('change', applyFilter);
document.getElementById('filterStatus')?.addEventListener('change', applyFilter);

// ── Checkbox helpers ───────────────────────────────────────────────���───────
// Hanya centang baris yang TERLIHAT (tidak di-hide oleh filter)
function toggleAll(gk) {
    const tbl    = document.getElementById('tbl_' + gk);
    const master = tbl?.querySelector('thead input[type=checkbox]');
    if (!master) return;
    // Ambil hanya baris yang visible
    const visibleChks = [...tbl.querySelectorAll('tbody .row-chk')]
        .filter(chk => chk.closest('tr').style.display !== 'none');
    visibleChks.forEach(chk => { chk.checked = master.checked; });
    updateSelectedCount(gk);
}

// Update counter "X dipilih" di toolbar
function updateSelectedCount(gk) {
    const tbl     = document.getElementById('tbl_' + gk);
    const toolbar = document.getElementById('toolbar_' + gk);
    if (!tbl || !toolbar) return;
    const checked = tbl.querySelectorAll('tbody .row-chk:checked').length;
    const countEl = toolbar.querySelector('.sel-count');
    if (countEl) countEl.textContent = checked > 0 ? checked + ' dipilih' : 'Centang baris → pilih aksi';
}

// ── Aksi bulk dari toolbar ─────────────────────────────────────────────────
function aksiDipilih(gk, aksi) {
    const tbl = document.getElementById('tbl_' + gk);
    if (!tbl) return;

    // Kumpulkan semua checkbox yang dicentang & terlihat
    const checked = [...tbl.querySelectorAll('tbody .row-chk:checked')]
        .filter(c => c.closest('tr').style.display !== 'none');

    if (checked.length === 0) {
        alert('Belum ada baris yang dipilih.\nCentang baris terlebih dahulu.');
        return;
    }

    const idPindahOtomatis = checked.filter(c => c.dataset.action === 'pindah').map(c => c.value);
    const idTanpaPengganti = checked.filter(c => c.dataset.action === 'hapus').map(c => c.value);

    if (aksi === 'pindah') {
        if (idTanpaPengganti.length > 0) {
            // Ada yang tidak ada pengganti otomatis → tampilkan modal pilih mapel manual
            bukaModalPindahManual(gk, idPindahOtomatis, idTanpaPengganti);
        } else {
            // Semua bisa dipindah otomatis
            kirimAksi(gk, 'pindah_terpilih', idPindahOtomatis);
        }
    } else {
        const semua = checked.map(c => c.value);
        if (!confirm('Hapus ' + semua.length + ' nilai yang dipilih?\n'
            + (idPindahOtomatis.length > 0 ? '⚠ ' + idPindahOtomatis.length + ' di antaranya sebenarnya bisa dipindah.\n' : '')
            + 'Lanjutkan?')) return;
        kirimAksi(gk, 'hapus_terpilih', semua);
    }
}

// ── Modal pindah manual ────────────────────────────────────────────────────
function bukaModalPindahManual(gk, idOtomatis, idManual) {
    const modal   = document.getElementById('modalPindahManual');
    const sel     = document.getElementById('modalMapelTujuan');
    const infoEl  = document.getElementById('modalPindahInfo');
    const gkInput = document.getElementById('modalGk');
    if (!modal || !sel) return;

    // Simpan state di modal
    gkInput.value = gk;
    modal.dataset.idOtomatis = JSON.stringify(idOtomatis);
    modal.dataset.idManual   = JSON.stringify(idManual);

    // Isi dropdown mapel dari data yang di-inject PHP
    const mapelList = window.MAPEL_PER_KELAS[gk] || [];
    sel.innerHTML = '<option value="">-- Pilih Mapel Tujuan --</option>';
    let lastKelompok = '';
    mapelList.forEach(m => {
        if (m.kelompok !== lastKelompok) {
            sel.insertAdjacentHTML('beforeend',
                '<optgroup label="Kelompok ' + m.kelompok + '">');
            lastKelompok = m.kelompok;
        }
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.nama_mapel + ' (Kel.' + m.kelompok + ')';
        sel.appendChild(opt);
    });

    // Info
    infoEl.innerHTML =
        (idOtomatis.length > 0
            ? '<span style="color:#16a34a">✓ ' + idOtomatis.length + ' nilai akan dipindah otomatis (nama cocok)</span><br>'
            : '') +
        '<span style="color:#ea580c">⚠ ' + idManual.length + ' nilai tidak ada padanan nama — pilih mapel tujuan di bawah</span>';

    modal.style.display = 'flex';
}

function tutupModalPindahManual() {
    const modal = document.getElementById('modalPindahManual');
    if (modal) modal.style.display = 'none';
}

function konfirmasiPindahManual() {
    const modal      = document.getElementById('modalPindahManual');
    const sel        = document.getElementById('modalMapelTujuan');
    const gk         = document.getElementById('modalGk').value;
    const idOtomatis = JSON.parse(modal.dataset.idOtomatis || '[]');
    const idManual   = JSON.parse(modal.dataset.idManual   || '[]');
    const targetMid  = sel.value;

    if (!targetMid) {
        alert('Pilih mapel tujuan terlebih dahulu!');
        return;
    }

    tutupModalPindahManual();

    // Jika ada yang otomatis, proses dulu — tapi kita gabung dalam satu POST saja
    // dengan action pindah_ke_mapel untuk idManual, lalu pindah_terpilih untuk idOtomatis
    const f = document.getElementById('form_' + gk);
    f.querySelectorAll('.row-chk').forEach(c => c.disabled = true);
    // Hapus hidden input lama
    f.querySelectorAll('input[data-tmp]').forEach(i => i.remove());

    if (idOtomatis.length > 0) {
        // Submit pindah_terpilih untuk yang otomatis dulu — tapi jika ada manual juga,
        // kita gabung semua ke pindah_ke_mapel dengan target_mapel_id
        // (backend pindah_ke_mapel hanya update yang orphan, yang sudah valid tidak akan berubah)
    }

    // Kirim pindah_ke_mapel untuk idManual (dan idOtomatis jika ada)
    const semuaIds = [...idManual, ...idOtomatis];
    document.getElementById('act_' + gk).value = 'pindah_ke_mapel';
    semuaIds.forEach(id => {
        const tmp = document.createElement('input');
        tmp.type='hidden'; tmp.name='orphan_ids[]'; tmp.value=id; tmp.dataset.tmp='1';
        f.appendChild(tmp);
    });
    // Tambah target_mapel_id
    const tmid = document.createElement('input');
    tmid.type='hidden'; tmid.name='target_mapel_id'; tmid.value=targetMid; tmid.dataset.tmp='1';
    f.appendChild(tmid);
    f.submit();
}

// Helper: submit form dengan ID tertentu
function kirimAksi(gk, action, ids) {
    const f = document.getElementById('form_' + gk);
    // Nonaktifkan semua checkbox agar tidak ikut submit ganda
    f.querySelectorAll('.row-chk').forEach(c => c.disabled = true);
    // Set action
    document.getElementById('act_' + gk).value = action;
    // Tambah hidden input untuk setiap ID
    ids.forEach(id => {
        const tmp = document.createElement('input');
        tmp.type = 'hidden'; tmp.name = 'orphan_ids[]'; tmp.value = id;
        f.appendChild(tmp);
    });
    f.submit();
}

// ── Aksi satu baris ────────────────────────────────────────────────────────
function pindahSatu(id, gk) {
    kirimAksi(gk, 'pindah_terpilih', [id]);
}

function hapusSatu(id, siswa, mapel, gk) {
    if (!confirm('Hapus nilai ' + siswa + ' (' + mapel + ')?\nTidak ada mapel pengganti di kelas ini.')) return;
    kirimAksi(gk, 'hapus_terpilih', [id]);
}

// ── Sync master checkbox state setelah filter ─────────────────────────────
function syncMasterCheckbox(gk) {
    const tbl    = document.getElementById('tbl_' + gk);
    if (!tbl) return;
    const visibleChks = [...tbl.querySelectorAll('tbody .row-chk')]
        .filter(chk => chk.closest('tr').style.display !== 'none');
    const master = tbl.querySelector('thead input[type=checkbox]');
    if (!master || visibleChks.length === 0) { if (master) master.indeterminate = false; return; }
    const allChecked  = visibleChks.every(c => c.checked);
    const someChecked = visibleChks.some(c => c.checked);
    master.checked       = allChecked;
    master.indeterminate = !allChecked && someChecked;
}

// ── Init ───────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    applyFilter();
    document.querySelectorAll('.row-chk').forEach(chk => {
        chk.addEventListener('change', function() {
            const panel = this.closest('[id^="panel_"]');
            if (!panel) return;
            const gk = panel.id.replace('panel_', '');
            updateSelectedCount(gk);
            syncMasterCheckbox(gk);
        });
    });
    // Tutup modal klik overlay
    document.getElementById('modalPindahManual')?.addEventListener('click', function(e) {
        if (e.target === this) tutupModalPindahManual();
    });
});

// ── Data mapel per kelas di-inject dari PHP ────────────────────────────────
window.MAPEL_PER_KELAS = <?= json_encode(array_map(
    fn($list) => array_map(fn($m) => ['id'=>$m['id'],'nama_mapel'=>$m['nama_mapel'],'kelompok'=>$m['kelompok']], $list),
    $mapel_per_kelas
)) ?>;
</script>

<!-- ── Modal Pindah Manual ──────────────────────────────────────────────── -->
<div id="modalPindahManual"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;
            align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:480px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.2)">
        <!-- Header -->
        <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between">
            <div style="font-weight:700;font-size:.95rem;color:#1f2328">Pilih Mapel Tujuan</div>
            <button onclick="tutupModalPindahManual()"
                    style="background:none;border:none;font-size:1.3rem;color:#9ca3af;cursor:pointer;line-height:1">&times;</button>
        </div>
        <!-- Body -->
        <div style="padding:18px 20px">
            <p id="modalPindahInfo" style="font-size:.83rem;line-height:1.6;margin-bottom:14px;
               background:#fffbf5;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px"></p>
            <label style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:6px">
                Pindahkan nilai ke mapel:
            </label>
            <select id="modalMapelTujuan"
                    style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;
                           font-size:.85rem;outline:none;background:#fff">
                <option value="">-- Pilih Mapel Tujuan --</option>
            </select>
            <p style="font-size:.75rem;color:#9ca3af;margin-top:8px">
                Daftar mapel di atas adalah mapel yang terdaftar dalam penugasan guru di kelas ini.
                Nilai akan dipindah ke mapel yang Anda pilih.
            </p>
        </div>
        <!-- Footer -->
        <div style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;gap:8px;justify-content:flex-end">
            <button onclick="tutupModalPindahManual()"
                    class="btn btn-sm btn-secondary" style="border-radius:20px">Batal</button>
            <button onclick="konfirmasiPindahManual()"
                    class="btn btn-sm" style="border-radius:20px;background:#16a34a;color:#fff;border:none">
                ✓ Pindahkan Sekarang
            </button>
        </div>
        <input type="hidden" id="modalGk">
    </div>
</div>
