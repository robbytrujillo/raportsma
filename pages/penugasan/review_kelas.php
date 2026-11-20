<?php
/**
 * pages/penugasan/review_kelas.php
 * Review susunan mapel per kelas berdasarkan penugasan guru —
 * menampilkan format tabel raport (kelompok → urutan → nama mapel)
 * agar admin dapat memastikan penugasan sudah sesuai sebelum raport dicetak.
 */
require_role(['admin', 'super_admin', 'kepala_sekolah']);

$user       = current_user();
$ta_aktif   = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');
$ta_id      = (int)($_GET['ta']    ?? ($ta_aktif['id'] ?? 0));
$kelas_id   = (int)($_GET['kelas'] ?? 0);

$ta_list    = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');

$_unit_f   = get_user_unit_filter($user);
$_unit_sql = $_unit_f['sql'];

$kelas_list = db_query(
    "SELECT k.*, u.nama_lengkap as walas_nama
     FROM kelas k
     LEFT JOIN users u ON u.id = k.wali_kelas_id
     WHERE k.tahun_ajaran_id = :ta {$_unit_sql}
     ORDER BY k.nama_kelas",
    [':ta' => $ta_id]
);

// ── Data mapel per kelas dari penugasan ────────────────────────────────────
$kelas_row   = $kelas_id ? db_row('SELECT * FROM kelas WHERE id = :id', [':id' => $kelas_id]) : null;
$penugasan   = [];
$mapel_count = 0;
$guru_count  = 0;

if ($kelas_id && $ta_id) {
    // Ambil semua penugasan: mapel + info parent untuk kelas & TA ini
    $penugasan_raw = db_query(
        "SELECT gmk.id as gmk_id, gmk.guru_user_id,
                u.nama_lengkap as nama_guru,
                m.id as mapel_id, m.nama_mapel, m.kode_mapel,
                m.kelompok, m.urutan, m.parent_id, m.sub_label,
                p.nama_mapel as parent_nama, p.kelompok as parent_kelompok,
                p.urutan as parent_urutan
         FROM guru_mapel_kelas gmk
         INNER JOIN mapel m  ON m.id  = gmk.mapel_id
         LEFT  JOIN mapel p  ON p.id  = m.parent_id
         INNER JOIN users u  ON u.id  = gmk.guru_user_id
         WHERE gmk.kelas_id = :kid AND gmk.tahun_ajaran_id = :ta
         ORDER BY m.kelompok, m.urutan, m.nama_mapel, u.nama_lengkap",
        [':kid' => $kelas_id, ':ta' => $ta_id]
    );

    // Kumpulkan semua parent_id yang ada, lalu ambil data mapel induknya langsung
    // dari DB — agar urutan & kelompok parent selalu akurat (tidak tergantung JOIN)
    $all_parent_ids = array_filter(array_unique(array_column($penugasan_raw, 'parent_id')));
    $parent_db = [];
    if (!empty($all_parent_ids)) {
        $in_p = implode(',', array_map('intval', $all_parent_ids));
        foreach (db_query("SELECT id, nama_mapel, kelompok, urutan FROM mapel WHERE id IN ($in_p)") as $pr) {
            $parent_db[$pr['id']] = $pr;
        }
    }

    // Hitung berapa mapel yang sudah ada nilai (setidaknya 1 siswa) untuk TA ini
    $nilai_mapel_ids_raw = db_query(
        "SELECT DISTINCT mapel_id FROM nilai
         WHERE kelas_id = :kid AND tahun_ajaran_id = :ta",
        [':kid' => $kelas_id, ':ta' => $ta_id]
    );
    $nilai_mapel_ids = array_flip(array_column($nilai_mapel_ids_raw, 'mapel_id'));

    // Hitung jumlah siswa di kelas ini
    $jml_siswa = (int)(db_row(
        "SELECT COUNT(*) as n FROM siswa WHERE kelas_id = :kid",
        [':kid' => $kelas_id]
    )['n'] ?? 0);

    // ── Susun slot persis seperti raport_template.php ────────────────────────
    // Pisahkan: anak (punya parent_id) vs mandiri (tidak punya parent_id)
    $children_by_parent = [];  // parent_id => [mapel_data, ...]
    $singles_map        = [];  // mapel_id  => mapel_data
    $parent_meta        = [];  // parent_id => ['kelompok','urutan','nama']
    $mapel_id_list      = [];  // semua mapel_id unik (anak maupun mandiri)
    $guru_by_mapel      = [];  // mapel_id  => [guru_id => nama_guru]

    foreach ($penugasan_raw as $p) {
        $mid = $p['mapel_id'];
        $pid = $p['parent_id'];

        if (!in_array($mid, $mapel_id_list)) {
            $mapel_id_list[] = $mid;
        }

        // Kumpulkan guru unik per mapel_id
        if (!isset($guru_by_mapel[$mid])) {
            $guru_by_mapel[$mid] = [];
        }
        $guru_by_mapel[$mid][$p['guru_user_id']] = $p['nama_guru'];

        if ($pid !== null) {
            // Sub-mapel: kelompok & urutan slot diambil dari data parent langsung di DB
            // Prioritas: $parent_db (query terpisah) → fallback ke JOIN result
            if (!isset($children_by_parent[$pid])) {
                $children_by_parent[$pid] = [];
                $pd = $parent_db[$pid] ?? null;
                $parent_meta[$pid] = [
                    'kelompok' => $pd['kelompok'] ?? $p['parent_kelompok'] ?? $p['kelompok'],
                    'urutan'   => isset($pd['urutan']) ? (int)$pd['urutan'] : (int)($p['parent_urutan'] ?? 0),
                    'nama'     => $pd['nama_mapel']  ?? $p['parent_nama']  ?? '',
                ];
            }
            if (!isset($children_by_parent[$pid][$mid])) {
                $children_by_parent[$pid][$mid] = [
                    'mapel_id'   => $mid,
                    'nama_mapel' => $p['nama_mapel'],
                    'kode_mapel' => $p['kode_mapel'],
                    'kelompok'   => $p['kelompok'],
                    'urutan'     => (int)($p['urutan'] ?? 0),
                    'parent_id'  => $pid,
                    'sub_label'  => $p['sub_label'],
                    'ada_nilai'  => isset($nilai_mapel_ids[$mid]),
                ];
            }
        } else {
            // Mapel mandiri
            if (!isset($singles_map[$mid])) {
                $singles_map[$mid] = [
                    'mapel_id'   => $mid,
                    'nama_mapel' => $p['nama_mapel'],
                    'kode_mapel' => $p['kode_mapel'],
                    'kelompok'   => $p['kelompok'],
                    'urutan'     => (int)($p['urutan'] ?? 0),
                    'parent_id'  => null,
                    'sub_label'  => $p['sub_label'],
                    'ada_nilai'  => isset($nilai_mapel_ids[$mid]),
                ];
            }
        }
    }

    // ── Bangun slots (sama persis dengan raport_template.php) ────────────────
    $slots = [];

    foreach ($singles_map as $mid => $m) {
        $slots[] = [
            'kelompok'    => $m['kelompok'],
            'urutan'      => $m['urutan'],
            'type'        => 'single',
            'mapel_id'    => $mid,
            'mapel_data'  => $m,
        ];
    }

    foreach ($children_by_parent as $pid => $children) {
        $pm = $parent_meta[$pid];
        // Urutkan anak berdasarkan urutan → nama
        uasort($children, function ($a, $b) {
            $u = $a['urutan'] <=> $b['urutan'];
            return $u !== 0 ? $u : strcmp($a['nama_mapel'], $b['nama_mapel']);
        });
        $slots[] = [
            'kelompok'    => $pm['kelompok'],
            'urutan'      => $pm['urutan'],
            'type'        => 'parent_group',
            'parent_id'   => $pid,
            'parent_nama' => $pm['nama'],
            'children'    => array_values($children),
        ];
    }

    // Urutkan slot: kelompok → urutan (sama dengan raport_template)
    usort($slots, function ($a, $b) {
        $kg = strcmp($a['kelompok'] ?? '', $b['kelompok'] ?? '');
        if ($kg !== 0) return $kg;
        return $a['urutan'] <=> $b['urutan'];
    });

    $mapel_count = count($mapel_id_list);
    $guru_set    = array_unique(array_column($penugasan_raw, 'guru_user_id'));
    $guru_count  = count($guru_set);
    $penugasan   = $slots;  // $penugasan sekarang array slot terurut
}

// Label kelompok
$kelompok_labels = [
    'A'           => 'Kelompok A — Wajib Umum',
    'B'           => 'Kelompok B — Wajib Peminatan',
    'C'           => 'Kelompok C',
    'C Pilihan'   => 'Kelompok C Pilihan',
    'D'           => 'Kelompok D',
    'Muatan Lokal'=> 'Muatan Lokal',
];

$kelompok_colors = [
    'A'           => '#3b82f6',
    'B'           => '#8b5cf6',
    'C'           => '#10b981',
    'C Pilihan'   => '#06b6d4',
    'D'           => '#f59e0b',
    'Muatan Lokal'=> '#ec4899',
];
?>

<div class="page-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Review Mapel per Kelas</h1>
            <p class="page-subtitle">Verifikasi susunan mapel sesuai format raport berdasarkan penugasan guru</p>
        </div>
        <?php if ($kelas_id && $ta_id): ?>
        <div class="flex gap-2">
            <a href="/penugasan?ta=<?= $ta_id ?>&q=<?= urlencode($kelas_row['nama_kelas'] ?? '') ?>"
               class="btn btn-secondary" style="border-radius:30px">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/>
                    <line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
                Kelola Penugasan
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filter -->
    <form method="GET" action="index.php" class="card mb-4">
        <div class="card-body">
            <input type="hidden" name="page" value="penugasan.review_kelas">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                    <label class="form-label">Tahun Ajaran</label>
                    <select name="ta" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($ta_list as $ta): ?>
                        <option value="<?= $ta['id'] ?>" <?= $ta['id'] == $ta_id ? 'selected' : '' ?>>
                            <?= e($ta['nama']) ?> Sem.<?= $ta['semester'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Kelas</label>
                    <select name="kelas" class="form-control" onchange="this.form.submit()">
                        <option value="">— Pilih Kelas —</option>
                        <?php foreach ($kelas_list as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $k['id'] == $kelas_id ? 'selected' : '' ?>>
                            <?= e($k['nama_kelas']) ?>
                            <?php if ($k['walas_nama']): ?>
                            &nbsp;(<?= e($k['walas_nama']) ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn btn-primary w-100" style="border-radius:30px">Tampilkan</button>
                </div>
            </div>
        </div>
    </form>

    <?php if (!$kelas_id || !$ta_id): ?>
    <!-- Empty state: belum pilih kelas -->
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            <p>Pilih tahun ajaran dan kelas untuk melihat review susunan mapel.</p>
        </div>
    </div>

    <?php elseif (empty($penugasan)): ?>
    <!-- Belum ada penugasan -->
    <div class="card">
        <div class="card-body empty-state">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p style="font-weight:600;margin-bottom:4px">Belum Ada Penugasan</p>
            <p>Kelas <strong><?= e($kelas_row['nama_kelas'] ?? '') ?></strong> belum memiliki
               penugasan guru untuk tahun ajaran ini.</p>
            <a href="/penugasan?ta=<?= $ta_id ?>" class="btn btn-primary mt-3" style="border-radius:30px">
                + Tambah Penugasan
            </a>
        </div>
    </div>

    <?php else: ?>
    <!-- Summary strip -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="card">
            <div class="card-body" style="padding:14px 16px">
                <div class="text-xs font-semibold text-gray-400 uppercase mb-1">Kelas</div>
                <div class="font-bold text-gray-800"><?= e($kelas_row['nama_kelas'] ?? '-') ?></div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="padding:14px 16px">
                <div class="text-xs font-semibold text-gray-400 uppercase mb-1">Jumlah Mapel</div>
                <div class="font-bold text-orange-600" style="font-size:1.3rem"><?= $mapel_count ?></div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="padding:14px 16px">
                <div class="text-xs font-semibold text-gray-400 uppercase mb-1">Jumlah Guru</div>
                <div class="font-bold text-blue-600" style="font-size:1.3rem"><?= $guru_count ?></div>
            </div>
        </div>
        <div class="card">
            <div class="card-body" style="padding:14px 16px">
                <div class="text-xs font-semibold text-gray-400 uppercase mb-1">Jumlah Murid</div>
                <div class="font-bold text-green-600" style="font-size:1.3rem"><?= $jml_siswa ?></div>
            </div>
        </div>
    </div>

    <!-- Info: cara perbaiki urutan -->
    <div class="alert" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;
         margin-bottom:16px;display:flex;align-items:flex-start;gap:10px">
        <svg width="18" height="18" fill="none" stroke="#d97706" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px">
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <div style="font-size:.85rem;color:#92400e;line-height:1.6">
            <strong>Urutan tidak sesuai raport?</strong>
            Kolom <em>Urutan</em> menunjukkan nilai <code>urutan</code> tiap mapel di database.
            Mapel diurutkan: <strong>kelompok → urutan (ascending)</strong>.
            Jika mapel induk (misal PAI) perlu muncul sebelum Bahasa Indonesia,
            pastikan <code>urutan</code> PAI lebih kecil dari Bahasa Indonesia.
            &nbsp;<a href="/mapel" style="color:#d97706;font-weight:700;text-decoration:underline">Edit urutan di halaman Mata Pelajaran →</a>
        </div>
    </div>

    <!-- Tabel review mapel -->
    <div class="card">
        <div class="card-header">
            <span>Susunan Mapel — Format Rapor</span>
            <div class="flex items-center gap-2">
                <span style="display:inline-flex;align-items:center;gap:4px;font-size:.78rem;color:#16a34a;font-weight:600">
                    <span style="width:9px;height:9px;border-radius:50%;background:#22c55e;display:inline-block"></span>
                    Ada nilai
                </span>
                <span style="display:inline-flex;align-items:center;gap:4px;font-size:.78rem;color:#9ca3af;font-weight:600">
                    <span style="width:9px;height:9px;border-radius:50%;background:#d1d5db;display:inline-block"></span>
                    Belum ada nilai
                </span>
            </div>
        </div>
        <div class="table-container">
            <table class="data-table" style="font-size:.88rem">
                <thead>
                    <tr>
                        <th style="width:36px;text-align:center">No</th>
                        <th style="width:20px;text-align:center">Sub</th>
                        <th>Mata Pelajaran</th>
                        <th style="width:60px;text-align:center" title="Nilai urutan di tabel Mata Pelajaran">Urutan</th>
                        <th style="width:110px">Kode</th>
                        <th>Guru Pengampu</th>
                        <th style="width:100px;text-align:center">Nilai</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $row_no      = 0;
                $kelompok_prev = null;
                foreach ($penugasan as $slot):
                    $slot_kg    = $slot['kelompok'] ?? '';
                    $kg_label   = $kelompok_labels[$slot_kg] ?? ('Kelompok ' . $slot_kg);
                    $kg_color   = $kelompok_colors[$slot_kg] ?? '#6b7280';
                ?>

                <?php if ($slot_kg !== $kelompok_prev):
                    $kelompok_prev = $slot_kg;
                ?>
                <!-- Baris header kelompok -->
                <tr>
                    <td colspan="7" style="background:<?= $kg_color ?>18;border-left:4px solid <?= $kg_color ?>;
                        padding:8px 12px;font-weight:700;color:<?= $kg_color ?>;font-size:.82rem;letter-spacing:.3px">
                        <?= htmlspecialchars($kg_label) ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ($slot['type'] === 'single'):
                    $m          = $slot['mapel_data'];
                    $guru_names = array_values($guru_by_mapel[$slot['mapel_id']] ?? []);
                    $row_no++;
                ?>
                <!-- Baris mapel mandiri -->
                <tr class="<?= $m['ada_nilai'] ? '' : 'text-gray-400' ?>">
                    <td style="text-align:center;font-weight:700;color:#374151"><?= $row_no ?></td>
                    <td></td>
                    <td style="font-weight:600"><?= htmlspecialchars($m['nama_mapel']) ?></td>
                    <td style="text-align:center;font-size:.78rem;color:#9ca3af"><?= $m['urutan'] ?: '—' ?></td>
                    <td>
                        <?php if ($m['kode_mapel']): ?>
                        <span class="badge badge-gray" style="font-family:monospace;font-size:.75rem">
                            <?= e($m['kode_mapel']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php foreach ($guru_names as $gn): ?>
                        <span class="badge badge-blue" style="margin:1px 2px;font-size:.75rem">
                            <?= htmlspecialchars($gn) ?>
                        </span>
                        <?php endforeach; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($m['ada_nilai']): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;font-size:.78rem;
                              color:#16a34a;font-weight:600">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"
                                 viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                            Terisi
                        </span>
                        <?php else: ?>
                        <span style="font-size:.75rem;color:#9ca3af">—</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php elseif ($slot['type'] === 'parent_group'):
                    $pm_urutan = $parent_meta[$slot['parent_id']]['urutan'] ?? 0;
                    $row_no++;
                ?>
                <!-- Baris induk mapel -->
                <tr style="background:#fafafa">
                    <td style="text-align:center;font-weight:700;color:#374151"><?= $row_no ?></td>
                    <td></td>
                    <td style="font-weight:700;color:#374151">
                        <?= htmlspecialchars($slot['parent_nama']) ?>:
                    </td>
                    <td style="text-align:center;font-size:.78rem;color:#9ca3af"><?= $pm_urutan ?: '—' ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php foreach ($slot['children'] as $c):
                    $guru_names_c = array_values($guru_by_mapel[$c['mapel_id']] ?? []);
                ?>
                <!-- Baris sub-mapel (anak) -->
                <tr class="<?= $c['ada_nilai'] ? '' : 'text-gray-400' ?>">
                    <td></td>
                    <td style="text-align:center;font-weight:600;font-size:.8rem;color:#6b7280">
                        <?= htmlspecialchars($c['sub_label'] ?? '') ?>
                    </td>
                    <td style="padding-left:20px"><?= htmlspecialchars($c['nama_mapel']) ?></td>
                    <td></td>
                    <td>
                        <?php if ($c['kode_mapel']): ?>
                        <span class="badge badge-gray" style="font-family:monospace;font-size:.75rem">
                            <?= e($c['kode_mapel']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php foreach ($guru_names_c as $gn): ?>
                        <span class="badge badge-blue" style="margin:1px 2px;font-size:.75rem">
                            <?= htmlspecialchars($gn) ?>
                        </span>
                        <?php endforeach; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ($c['ada_nilai']): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;font-size:.78rem;
                              color:#16a34a;font-weight:600">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"
                                 viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                            Terisi
                        </span>
                        <?php else: ?>
                        <span style="font-size:.75rem;color:#9ca3af">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; // anak ?>
                <?php endif; // type ?>

                <?php endforeach; // slots ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f9fafb;font-weight:700">
                        <td colspan="2" style="padding:10px 12px;text-align:right;color:#6b7280;font-size:.82rem">
                            Total
                        </td>
                        <td style="padding:10px 12px">
                            <strong><?= $mapel_count ?></strong> mata pelajaran
                        </td>
                        <td></td>
                        <td></td>
                        <td style="padding:10px 12px">
                            <strong><?= $guru_count ?></strong> guru
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
