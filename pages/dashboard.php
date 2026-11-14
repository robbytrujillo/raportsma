<?php
/**
 * pages/dashboard.php
 * Dashboard utama &mdash; tampilan berbeda per role
 */
$user = current_user();
$role = $user['role'];

// ── Ambil data statistik sesuai role ─────────────────────────
$ta_aktif = db_row('SELECT * FROM tahun_ajaran WHERE is_aktif = 1 LIMIT 1');

// Unit filter untuk admin/kepsek yang punya unit spesifik
$_uf     = get_user_unit_filter($user);
$_usql   = $_uf['sql']; // mis. "AND k.unit = 'putri'" atau ""
// Filter unit untuk siswa (join via kelas)
$_usql_siswa = '';
if ($_uf['units']) {
    $u_in = implode("','", $_uf['units']);
    $_usql_siswa = "AND s.kelas_id IN (SELECT id FROM kelas WHERE unit IN ('$u_in'))";
}
// Filter unit untuk guru (join via tabel guru)
$_usql_guru = '';
if ($_uf['units']) {
    $u_in = implode("','", $_uf['units']);
    $_usql_guru = "AND u.id IN (SELECT user_id FROM guru WHERE unit IN ('$u_in'))";
}

if (in_array($role, ['admin','super_admin'])) {
    $ta_id_dash = $ta_aktif['id'] ?? 0;
    $total_siswa = db_row("SELECT COUNT(*) as n FROM users u INNER JOIN siswa s ON s.user_id = u.id WHERE u.role = 'siswa' AND u.is_active = 1 {$_usql_siswa}")['n'] ?? 0;
    $total_guru  = db_row("SELECT COUNT(*) as n FROM users u WHERE u.role IN ('guru','wali_kelas') AND u.is_active = 1 {$_usql_guru}")['n'] ?? 0;
    $total_kelas = db_row("SELECT COUNT(*) as n FROM kelas k WHERE k.tahun_ajaran_id = :ta {$_usql}", [':ta' => $ta_id_dash])['n'] ?? 0;
    $total_mapel = db_row('SELECT COUNT(*) as n FROM mapel')['n'] ?? 0;
    $total_nilai = db_row("SELECT COUNT(*) as n FROM nilai n INNER JOIN kelas k ON k.id = n.kelas_id WHERE n.tahun_ajaran_id = :ta {$_usql}", [':ta' => $ta_id_dash])['n'] ?? 0;

} elseif ($role === 'kepala_sekolah') {
    $ta_id_dash  = $ta_aktif['id'] ?? 0;
    $total_siswa = db_row("SELECT COUNT(*) as n FROM users u INNER JOIN siswa s ON s.user_id = u.id WHERE u.role = 'siswa' AND u.is_active = 1 {$_usql_siswa}")['n'] ?? 0;
    $total_kelas = db_row("SELECT COUNT(*) as n FROM kelas k WHERE k.tahun_ajaran_id = :ta {$_usql}", [':ta' => $ta_id_dash])['n'] ?? 0;
    $total_nilai = db_row("SELECT COUNT(*) as n FROM nilai n INNER JOIN kelas k ON k.id = n.kelas_id WHERE n.tahun_ajaran_id = :ta {$_usql}", [':ta' => $ta_id_dash])['n'] ?? 0;

} elseif (in_array($role, ['guru','wali_kelas'])) {
    // Kelas & mapel yang diajar guru ini
    $total_kelas = db_row('SELECT COUNT(DISTINCT kelas_id) as n FROM guru_mapel_kelas WHERE guru_user_id = :uid AND tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;
    $total_mapel = db_row('SELECT COUNT(DISTINCT mapel_id) as n FROM guru_mapel_kelas WHERE guru_user_id = :uid AND tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;
    $total_nilai = db_row('SELECT COUNT(*) as n FROM nilai WHERE guru_user_id = :uid AND tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;

    // Jumlah siswa di kelas yang diajar
    $total_siswa = db_row('SELECT COUNT(DISTINCT s.user_id) as n FROM siswa s
        INNER JOIN guru_mapel_kelas gmk ON gmk.kelas_id = s.kelas_id
        WHERE gmk.guru_user_id = :uid AND gmk.tahun_ajaran_id = :ta',
        [':uid' => $user['id'], ':ta' => $ta_aktif['id'] ?? 0])['n'] ?? 0;

} elseif ($role === 'siswa') {
    // ── Filter dashboard siswa ────────────────────────────────
    // Daftar semua tahun ajaran untuk dropdown
    $ta_list_siswa = db_query('SELECT * FROM tahun_ajaran ORDER BY id DESC');

    // TA yang dipilih (default: TA aktif)
    $filter_ta_id = (int)($_GET['f_ta'] ?? ($ta_aktif['id'] ?? 0));
    // Pastikan TA yang dipilih valid
    $filter_ta    = db_row('SELECT * FROM tahun_ajaran WHERE id = :id', [':id' => $filter_ta_id])
                    ?? $ta_aktif;
    $filter_ta_id = (int)($filter_ta['id'] ?? 0);

    // Filter jenis_raport: semua | bulanan | sts | semester
    $filter_jenis_opts = ['semua' => 'Semua Jenis', 'bulanan' => 'Bulanan', 'sts' => 'STS', 'semester' => 'Semester'];
    $filter_jenis = isset($_GET['f_jenis']) && array_key_exists($_GET['f_jenis'], $filter_jenis_opts)
                    ? $_GET['f_jenis'] : 'semua';

    // Kondisi SQL untuk jenis_raport
    $jenis_cond  = $filter_jenis !== 'semua' ? 'AND n.jenis_raport = :jenis' : '';
    $jenis_param = $filter_jenis !== 'semua' ? [':jenis' => $filter_jenis] : [];

    // Nilai siswa ini
    $siswa_data = db_row('SELECT s.*, k.nama_kelas FROM siswa s LEFT JOIN kelas k ON k.id = s.kelas_id WHERE s.user_id = :uid', [':uid' => $user['id']]);

    // ── JOIN raport_publikasi — hanya tampilkan nilai yang sudah dirilis ──
    // Pola JOIN ini ditambahkan ke semua query nilai siswa di dashboard.
    $rp_join = "INNER JOIN raport_publikasi rp
                        ON rp.kelas_id        = (SELECT kelas_id FROM siswa WHERE user_id = n.siswa_user_id LIMIT 1)
                       AND rp.tahun_ajaran_id = n.tahun_ajaran_id
                       AND rp.jenis_raport    = n.jenis_raport
                       AND (rp.bulan = n.bulan OR (rp.bulan IS NULL AND n.bulan IS NULL))
                       AND rp.is_published    = 1";

    // Cek apakah ada nilai tapi belum dirilis sama sekali di TA ini
    $ada_belum_rilis_dash = (int)(db_row(
        'SELECT COUNT(*) as n FROM nilai n
         LEFT JOIN raport_publikasi rp
                ON rp.kelas_id        = (SELECT kelas_id FROM siswa WHERE user_id = n.siswa_user_id LIMIT 1)
               AND rp.tahun_ajaran_id = n.tahun_ajaran_id
               AND rp.jenis_raport    = n.jenis_raport
               AND (rp.bulan = n.bulan OR (rp.bulan IS NULL AND n.bulan IS NULL))
         WHERE n.siswa_user_id = :uid
           AND n.tahun_ajaran_id = :ta
           AND COALESCE(rp.is_published, 0) = 0',
        [':uid' => $user['id'], ':ta' => $filter_ta_id]
    )['n'] ?? 0) > 0;

    // ── Filter Bulan ──────────────────────────────────────────
    $bulan_relevan = in_array($filter_jenis, ['bulanan', 'semua']);

    // Ambil daftar bulan yang tersedia (hanya dari yang sudah dirilis)
    $bulan_tersedia = [];
    if ($bulan_relevan) {
        $bulan_rows = db_query(
            "SELECT DISTINCT n.bulan FROM nilai n
             $rp_join
             WHERE n.siswa_user_id = :uid AND n.tahun_ajaran_id = :ta
               AND n.bulan IS NOT NULL AND n.nilai_akhir > 0
               $jenis_cond
             ORDER BY n.bulan DESC",
            array_merge([':uid' => $user['id'], ':ta' => $filter_ta_id], $jenis_param)
        );
        $bulan_tersedia = array_column($bulan_rows, 'bulan');
    }

    // Bulan default = bulan terbaru yang tersedia
    $bulan_default = !empty($bulan_tersedia) ? (int)$bulan_tersedia[0] : 0;

    // Bulan yang dipilih user; 0 = semua bulan
    $filter_bulan = 0;
    if ($bulan_relevan) {
        if (isset($_GET['f_bulan'])) {
            $fb = (int)$_GET['f_bulan'];
            $filter_bulan = ($fb === 0 || in_array($fb, $bulan_tersedia)) ? $fb : $bulan_default;
        } else {
            $filter_bulan = $bulan_default;
        }
    }

    // Kondisi SQL untuk bulan
    $bulan_cond  = ($bulan_relevan && $filter_bulan > 0) ? 'AND n.bulan = :bulan' : '';
    $bulan_param = ($bulan_relevan && $filter_bulan > 0) ? [':bulan' => $filter_bulan] : [];

    // Gabungkan semua param tambahan
    $extra_cond  = $jenis_cond . ' ' . $bulan_cond;
    $extra_param = array_merge($jenis_param, $bulan_param);

    // Semua query berikut hanya membaca nilai yang sudah is_published = 1
    $total_nilai = db_row(
        "SELECT COUNT(*) as n FROM nilai n $rp_join
         WHERE n.siswa_user_id = :uid AND n.tahun_ajaran_id = :ta $extra_cond",
        array_merge([':uid' => $user['id'], ':ta' => $filter_ta_id], $extra_param)
    )['n'] ?? 0;

    // Rata-rata nilai akhir siswa (dengan filter)
    $avg_nilai = db_row(
        "SELECT ROUND(AVG(n.nilai_akhir),2) as avg FROM nilai n $rp_join
         WHERE n.siswa_user_id = :uid AND n.tahun_ajaran_id = :ta $extra_cond",
        array_merge([':uid' => $user['id'], ':ta' => $filter_ta_id], $extra_param)
    )['avg'] ?? 0;

    // Peringkat di kelas
    if ($siswa_data) {
        $rank_data = db_query(
            "SELECT sv.user_id, ROUND(AVG(n.nilai_akhir),2) as avg_nilai
             FROM siswa sv
             INNER JOIN nilai n ON n.siswa_user_id = sv.user_id
             $rp_join
             WHERE sv.kelas_id = :kelas AND n.tahun_ajaran_id = :ta $extra_cond
             GROUP BY sv.user_id
             ORDER BY avg_nilai DESC",
            array_merge([':kelas' => $siswa_data['kelas_id'], ':ta' => $filter_ta_id], $extra_param)
        );
        $peringkat = 1;
        foreach ($rank_data as $i => $r) {
            if ($r['user_id'] == $user['id']) {
                $peringkat = $i + 1;
                break;
            }
        }
        $total_siswa_kelas = count($rank_data);
    }

    // ── Data grafik: nilai per mapel per bulan (bulanan, sudah dirilis) ──
    $chart_rows = db_query(
        "SELECT n.bulan, m.nama_mapel, n.nilai_akhir
         FROM nilai n
         INNER JOIN mapel m ON m.id = n.mapel_id
         $rp_join
         WHERE n.siswa_user_id = :uid
           AND n.tahun_ajaran_id = :ta
           AND n.jenis_raport = 'bulanan'
           AND n.bulan IS NOT NULL
           AND n.nilai_akhir > 0
         ORDER BY n.bulan ASC, m.urutan ASC, m.nama_mapel ASC",
        [':uid' => $user['id'], ':ta' => $filter_ta_id]
    );

    // Susun data: $chart_data[nama_mapel][bulan] = nilai_akhir
    $chart_mapel_list = [];
    $chart_bulan_list = [];
    $chart_data       = [];
    foreach ($chart_rows as $cr) {
        $nm = $cr['nama_mapel'];
        $bl = (int)$cr['bulan'];
        if (!in_array($nm, $chart_mapel_list)) $chart_mapel_list[] = $nm;
        if (!in_array($bl, $chart_bulan_list)) $chart_bulan_list[] = $bl;
        $chart_data[$nm][$bl] = (float)$cr['nilai_akhir'];
    }
    sort($chart_bulan_list);

    // Bar chart: rata-rata per mapel (sudah dirilis, ikut filter jenis + bulan)
    $leger_rows = db_query(
        "SELECT m.nama_mapel, ROUND(AVG(n.nilai_akhir),2) as avg_nilai
         FROM nilai n
         INNER JOIN mapel m ON m.id = n.mapel_id
         $rp_join
         WHERE n.siswa_user_id = :uid
           AND n.tahun_ajaran_id = :ta
           AND n.nilai_akhir > 0
           $extra_cond
         GROUP BY n.mapel_id
         ORDER BY m.urutan ASC, m.nama_mapel ASC",
        array_merge([':uid' => $user['id'], ':ta' => $filter_ta_id], $extra_param)
    );
}

// Aktivitas nilai terbaru (admin/guru/walas) — difilter per unit
$recent_nilai = [];
if (in_array($role, ['admin', 'super_admin', 'kepala_sekolah', 'guru', 'wali_kelas'])) {
    if (in_array($role, ['admin','super_admin','kepala_sekolah'])) {
        $uid_filter = '';
    } else {
        $uid_filter = 'AND n.guru_user_id = ' . (int)$user['id'];
    }
    $recent_nilai = db_query(
        "SELECT n.*, u.nama_lengkap as nama_siswa, m.nama_mapel, k.nama_kelas
         FROM nilai n
         LEFT JOIN users u ON u.id = n.siswa_user_id
         LEFT JOIN mapel m ON m.id = n.mapel_id
         LEFT JOIN kelas k ON k.id = n.kelas_id
         WHERE n.tahun_ajaran_id = :ta {$_usql} $uid_filter
         ORDER BY n.updated_at DESC LIMIT 5",
        [':ta' => $ta_aktif['id'] ?? 0]
    );
}
?>
<div class="page-content">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Selamat Datang, <?= e(explode(' ', $user['nama_lengkap'])[0]) ?>!</h1>
            <p class="page-subtitle">
                <?php if ($ta_aktif): ?>
                Tahun Ajaran <?= e($ta_aktif['nama']) ?> &mdash; Semester
                <?= $ta_aktif['semester'] == 1 ? 'Ganjil' : 'Genap' ?>
                <?php else: ?>
                Belum ada tahun ajaran aktif
                <?php endif; ?>
            </p>
        </div>
        <span class="badge badge-orange text-sm"><?= str_replace('_',' ', ucfirst($role)) ?></span>
    </div>

    <!-- ── FILTER DASHBOARD SISWA ───────────────────────────── -->
    <?php if ($role === 'siswa'): ?>
    <?php
    $filter_ta_nama    = e($filter_ta['nama'] ?? '');
    $filter_sem_label  = ($filter_ta['semester'] ?? 1) == 1 ? 'Ganjil' : 'Genap';
    $filter_kelas_nama = e($siswa_data['nama_kelas'] ?? '-');
    $bulan_id_arr_local = BULAN_ID; // [1=>'Januari', ...]
    $filter_bulan_nama  = ($filter_bulan > 0) ? ($bulan_id_arr_local[$filter_bulan] ?? '') : 'Semua Bulan';
    $is_filter_active   = ($filter_jenis !== 'semua'
                           || $filter_ta_id !== ($ta_aktif['id'] ?? 0)
                           || ($bulan_relevan && $filter_bulan !== $bulan_default));
  ?>
    <!-- Tombol toggle filter -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
        <button type="button" id="btnToggleFilter" onclick="toggleFilterSiswa()"
            style="display:inline-flex;align-items:center;gap:7px;background:#fff;border:1.5px solid <?= $is_filter_active ? '#f97316' : '#e5e7eb' ?>;border-radius:30px;padding:6px 16px 6px 12px;font-size:.82rem;font-weight:600;color:<?= $is_filter_active ? '#f97316' : '#6b7280' ?>;cursor:pointer;transition:all .18s;box-shadow:0 1px 3px rgba(0,0,0,.06)">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
            </svg>
            Filter Grafik
            <?php if ($is_filter_active): ?>
            <span
                style="background:#f97316;color:#fff;border-radius:20px;font-size:.68rem;font-weight:700;padding:1px 7px;margin-left:2px">Aktif</span>
            <?php endif; ?>
            <svg id="filterChevron" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"
                viewBox="0 0 24 24" style="margin-left:2px;transition:transform .2s">
                <polyline points="6 9 12 15 18 9" />
            </svg>
        </button>

        <!-- Pill ringkasan filter aktif — hanya tampil jika ada yang benar-benar berbeda dari default -->
        <?php
      $pill_parts = [];
      if ($filter_jenis !== 'semua') $pill_parts[] = $filter_jenis_opts[$filter_jenis];
      if ($filter_ta_id !== ($ta_aktif['id'] ?? 0)) $pill_parts[] = 'TA '.$filter_ta_nama;
      // Bulan masuk pill hanya jika user memilih bulan yang BUKAN default (termasuk 0 = semua bulan)
      if ($bulan_relevan && $filter_bulan !== $bulan_default) {
          $pill_parts[] = $filter_bulan === 0 ? 'Semua Bulan' : $filter_bulan_nama;
      }
    ?>
        <?php if ($is_filter_active): ?>
        <span
            style="display:inline-flex;align-items:center;gap:6px;background:#fff7ed;border:1px solid #fed7aa;border-radius:20px;padding:4px 12px;font-size:.78rem;color:#c2410c;flex-wrap:wrap">
            <svg width="11" height="11" fill="none" stroke="#f97316" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="8" x2="12" y2="12" />
                <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            <?= implode(' &bull; ', $pill_parts) ?>
            &nbsp;&mdash;&nbsp;
            <a href="index.php?page=dashboard" style="color:#f97316;text-decoration:none;font-weight:700">✕ Reset</a>
        </span>
        <?php endif; ?>
    </div>

    <!-- Panel filter (collapsible) -->
    <div id="panelFilterSiswa"
        style="overflow:hidden;transition:max-height .28s cubic-bezier(.4,0,.2,1),opacity .22s;max-height:0;opacity:0;margin-bottom:0">
        <form method="GET" action="index.php" id="filterSiswaDashboard"
            style="background:linear-gradient(135deg,#fff7ed 0%,#f8fafc 100%);border:1.5px solid #fed7aa;border-radius:14px;padding:16px 20px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">
            <input type="hidden" name="page" value="dashboard">

            <!-- Tahun Ajaran -->
            <div style="flex:1;min-width:160px">
                <label
                    style="font-size:.74rem;font-weight:700;color:#9a3412;display:flex;align-items:center;gap:4px;margin-bottom:5px">
                    <svg width="11" height="11" fill="none" stroke="#f97316" stroke-width="2.5" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                    Tahun Ajaran
                </label>
                <select name="f_ta" class="form-control" style="font-size:.85rem;border-color:#fed7aa"
                    onchange="this.form.submit()">
                    <?php foreach ($ta_list_siswa as $trow): ?>
                    <option value="<?= $trow['id'] ?>" <?= $trow['id'] == $filter_ta_id ? 'selected' : '' ?>>
                        <?= e($trow['nama']) ?> &mdash; Sem. <?= $trow['semester'] == 1 ? 'Ganjil' : 'Genap' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Jenis Raport -->
            <div style="flex:1;min-width:140px">
                <label
                    style="font-size:.74rem;font-weight:700;color:#9a3412;display:flex;align-items:center;gap:4px;margin-bottom:5px">
                    <svg width="11" height="11" fill="none" stroke="#f97316" stroke-width="2.5" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                    </svg>
                    Jenis Raport
                </label>
                <select name="f_jenis" class="form-control" style="font-size:.85rem;border-color:#fed7aa"
                    onchange="this.form.submit()">
                    <?php foreach ($filter_jenis_opts as $fk => $fv): ?>
                    <option value="<?= $fk ?>" <?= $fk === $filter_jenis ? 'selected' : '' ?>><?= $fv ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Bulan (hanya jika jenis relevan & ada data bulan) -->
            <?php if ($bulan_relevan && !empty($bulan_tersedia)): ?>
            <div style="flex:1;min-width:130px">
                <label
                    style="font-size:.74rem;font-weight:700;color:#9a3412;display:flex;align-items:center;gap:4px;margin-bottom:5px">
                    <svg width="11" height="11" fill="none" stroke="#f97316" stroke-width="2.5" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                    Bulan
                    <span
                        style="font-size:.65rem;font-weight:600;background:#fed7aa;color:#9a3412;border-radius:10px;padding:1px 6px;margin-left:2px">Auto</span>
                </label>
                <select name="f_bulan" class="form-control" style="font-size:.85rem;border-color:#fed7aa"
                    onchange="this.form.submit()">
                    <option value="0" <?= $filter_bulan === 0 ? 'selected' : '' ?>>Semua Bulan</option>
                    <?php foreach ($bulan_tersedia as $bv): ?>
                    <option value="<?= $bv ?>" <?= $filter_bulan === (int)$bv ? 'selected' : '' ?>>
                        <?= $bulan_id_arr_local[(int)$bv] ?? 'Bulan '.$bv ?>
                        <?= (int)$bv === $bulan_default ? ' (terbaru)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Info Kelas (read-only) -->
            <div style="flex:0 0 auto;min-width:110px">
                <label
                    style="font-size:.74rem;font-weight:700;color:#9a3412;display:flex;align-items:center;gap:4px;margin-bottom:5px">
                    <svg width="11" height="11" fill="none" stroke="#f97316" stroke-width="2.5" viewBox="0 0 24 24">
                        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                    </svg>
                    Kelas
                </label>
                <div
                    style="background:#fff3e8;border:1.5px solid #fed7aa;border-radius:8px;padding:7px 12px;font-size:.85rem;color:#9a3412;font-weight:600">
                    <?= $filter_kelas_nama ?>
                </div>
            </div>

            <!-- Semester (read-only) -->
            <div style="flex:0 0 auto;min-width:100px">
                <label
                    style="font-size:.74rem;font-weight:700;color:#9a3412;display:flex;align-items:center;gap:4px;margin-bottom:5px">
                    <svg width="11" height="11" fill="none" stroke="#f97316" stroke-width="2.5" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" />
                        <polyline points="12 6 12 12 16 14" />
                    </svg>
                    Semester
                </label>
                <div
                    style="background:#fff3e8;border:1.5px solid #fed7aa;border-radius:8px;padding:7px 12px;font-size:.85rem;color:#9a3412;font-weight:600">
                    <?= $filter_sem_label ?>
                </div>
            </div>

        </form>
    </div>

    <script>
    (function() {
        var panel = document.getElementById('panelFilterSiswa');
        var chevron = document.getElementById('filterChevron');
        var KEY = 'dashFilterOpen';
        var isOpen = sessionStorage.getItem(KEY) === '1'
        <?= $is_filter_active ? '|| true' : '' ?>; // paksa buka jika filter aktif

        function applyState(open) {
            if (open) {
                panel.style.maxHeight = panel.scrollHeight + 40 + 'px';
                panel.style.opacity = '1';
                chevron.style.transform = 'rotate(180deg)';
            } else {
                panel.style.maxHeight = '0';
                panel.style.opacity = '0';
                chevron.style.transform = 'rotate(0deg)';
            }
        }

        applyState(isOpen);

        window.toggleFilterSiswa = function() {
            isOpen = !isOpen;
            sessionStorage.setItem(KEY, isOpen ? '1' : '0');
            applyState(isOpen);
        };
    })();
    </script>
    <?php endif; ?>

    <!-- ── STAT CARDS ──────────────────────────────────────── -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">

        <?php if (in_array($role, ['admin', 'kepala_sekolah', 'guru', 'wali_kelas'])): ?>
        <!-- Siswa -->
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff7ed">
                <svg width="24" height="24" fill="none" stroke="#f97316" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 00-3-3.87" />
                    <path d="M16 3.13a4 4 0 010 7.75" />
                </svg>
            </div>
            <div>
                <div class="stat-value text-orange-600"><?= $total_siswa ?></div>
                <div class="stat-label">Total Siswa</div>
            </div>
        </div>

        <?php if (in_array($role, ['admin','super_admin'])): ?>
        <!-- Guru -->
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff">
                <svg width="24" height="24" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M20 21a8 8 0 10-16 0" />
                </svg>
            </div>
            <div>
                <div class="stat-value text-blue-600"><?= $total_guru ?></div>
                <div class="stat-label">Total Guru</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Kelas -->
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4">
                <svg width="24" height="24" fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                    <polyline points="9 22 9 12 15 12 15 22" />
                </svg>
            </div>
            <div>
                <div class="stat-value text-green-600"><?= $total_kelas ?></div>
                <div class="stat-label"><?= in_array($role, ['admin','super_admin']) ? 'Total Kelas' : 'Kelas Diampu' ?>
                </div>
            </div>
        </div>

        <!-- Mata Pelajaran / Nilai -->
        <div class="stat-card">
            <div class="stat-icon" style="background:#fdf4ff">
                <svg width="24" height="24" fill="none" stroke="#a855f7" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z" />
                    <path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z" />
                </svg>
            </div>
            <div>
                <div class="stat-value text-purple-600">
                    <?= in_array($role, ['admin','super_admin']) ? $total_mapel : $total_nilai ?></div>
                <div class="stat-label">
                    <?= in_array($role, ['admin','super_admin']) ? 'Mata Pelajaran' : 'Data Nilai' ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($role === 'siswa'): ?>

        <?php if ($ada_belum_rilis_dash ?? false): ?>
        <!-- Banner: ada nilai belum dirilis -->
        </div><!-- tutup grid stat sementara -->
        <div class="alert alert-info mb-4" style="margin-top:-8px">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            <span>Sebagian nilai Anda belum dirilis oleh sekolah. Statistik dan grafik hanya menampilkan nilai yang sudah resmi diterbitkan.</span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6"><!-- buka kembali grid stat -->
        <?php endif; ?>

        <!-- Nilai siswa -->
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff7ed">
                <svg width="24" height="24" fill="none" stroke="#f97316" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M9 11l3 3L22 4" />
                    <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" />
                </svg>
            </div>
            <div>
                <div class="stat-value text-orange-600"><?= $total_nilai ?></div>
                <div class="stat-label">Nilai Tercatat</div>
            </div>
        </div>

        <!-- Rata-rata -->
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff">
                <svg width="24" height="24" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                </svg>
            </div>
            <div>
                <div class="stat-value text-blue-600"><?= $avg_nilai ?? 0 ?></div>
                <div class="stat-label">Rata-rata Nilai</div>
            </div>
        </div>

        <!-- Peringkat -->
        <?php if (!empty($peringkat)): ?>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef9c3">
                <svg width="24" height="24" fill="none" stroke="#eab308" stroke-width="2" viewBox="0 0 24 24">
                    <polygon
                        points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                </svg>
            </div>
            <div>
                <div class="stat-value text-yellow-600"><?= $peringkat ?><span
                        style="font-size:.9rem">/<?= $total_siswa_kelas ?? '-' ?></span></div>
                <div class="stat-label">Peringkat Kelas</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Info Kelas -->
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4">
                <svg width="24" height="24" fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                </svg>
            </div>
            <div>
                <div class="stat-value text-green-600" style="font-size:1rem"><?= e($siswa_data['nama_kelas'] ?? '-') ?>
                </div>
                <div class="stat-label">Kelas Saya</div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ── GRAFIK NILAI SISWA ──────────────────────────────── -->
    <?php if ($role === 'siswa'): ?>
    <?php
    $bulan_id_arr = BULAN_ID;
    $pal = ['#f97316','#3b82f6','#22c55e','#a855f7','#eab308',
            '#ef4444','#06b6d4','#84cc16','#f43f5e','#8b5cf6',
            '#0ea5e9','#10b981','#fb923c','#6366f1','#14b8a6'];
    $pal_bg = ['#fff7ed','#eff6ff','#f0fdf4','#fdf4ff','#fefce8',
               '#fef2f2','#ecfeff','#f7fee7','#fff1f2','#f5f3ff',
               '#f0f9ff','#ecfdf5','#fff7ed','#eef2ff','#f0fdfa'];

    // Hitung KKM / KTP dari sekolah_info
    $krit_c_val = (float)(sekolah_info()['kriteria_c_min'] ?? 76);
  ?>

    <?php if (!empty($leger_rows)): ?>
    <?php
    // Bar chart SVG: rata-rata per mapel
    // Label X = nomor urut (1..n), legend di bawah = grid nama mapel
    $bar_data   = $leger_rows;
    $bar_count  = count($bar_data);
    $bar_max    = 100;
    $bar_w_per  = max(40, min(64, (int)floor(820 / max($bar_count,1))));
    $bar_gap    = 14;
    $svg_w      = max(560, $bar_count * ($bar_w_per + $bar_gap) + 80) + 76;
    $pad_left   = 52; $pad_top = 52;
    $chart_h    = 200;
    $svg_h      = $pad_top + $chart_h + 28; // 28 = ruang nomor X
  ?>
    <!-- Bar chart: Rata-rata nilai per mapel -->
    <div class="card mt-6" style="overflow:hidden">
        <div class="card-header" style="background:linear-gradient(135deg,#fff7ed 0%,#fff 60%)">
            <div style="display:flex;align-items:center;gap:10px">
                <span style="background:#f97316;border-radius:8px;padding:6px 8px;display:flex">
                    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24">
                        <rect x="3" y="12" width="4" height="9" />
                        <rect x="10" y="7" width="4" height="14" />
                        <rect x="17" y="3" width="4" height="18" />
                    </svg>
                </span>
                <div>
                    <div style="font-weight:700;font-size:.95rem;color:#1f2937">Rata-rata Nilai per Mata Pelajaran</div>
                    <div style="font-size:.75rem;color:#9ca3af">
                        <?= $filter_jenis_opts[$filter_jenis] ?>
                        <?php if ($bulan_relevan && $filter_bulan > 0): ?>&bull;
                        <?= $filter_bulan_nama ?><?php endif; ?>
                        &bull; TA <?= e($filter_ta['nama'] ?? '') ?> &mdash; Sem. <?= $filter_sem_label ?>
                        &bull; Kelas <?= $filter_kelas_nama ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body" style="padding:16px 16px 12px;overflow-x:auto;-webkit-overflow-scrolling:touch">
            <svg viewBox="0 0 <?= $svg_w ?> <?= $svg_h ?>" width="100%"
                style="min-width:<?= min($svg_w,420) ?>px;display:block">
                <!-- Grid lines & Y labels -->
                <?php for ($gi=0;$gi<=5;$gi++): $gy_val=$gi*20; $gy=$pad_top+($chart_h*(1-$gy_val/$bar_max)); ?>
                <line x1="<?= $pad_left ?>" y1="<?= $gy ?>" x2="<?= $svg_w-16 ?>" y2="<?= $gy ?>" stroke="#f3f4f6"
                    stroke-width="1" />
                <text x="<?= $pad_left-8 ?>" y="<?= $gy+4 ?>" text-anchor="end" font-size="11"
                    fill="#9ca3af"><?= $gy_val ?></text>
                <?php endfor; ?>
                <!-- KKM line -->
                <?php
                  $kkm_y   = $pad_top + $chart_h*(1-$krit_c_val/$bar_max);
                  $pill_w  = 66; $pill_h = 20;
                  // pill di kanan, di luar area chart
                  $pill_x  = $svg_w - $pill_w - 2;
                  $pill_y  = $kkm_y - $pill_h/2;
                  // garis dari pad_left sampai sebelum pill
                  $line_x1 = $pad_left;
                  $line_x2 = $pill_x - 4;
                ?>
                <!-- halo putih supaya garis kontras di atas bar -->
                <line x1="<?= $line_x1 ?>" y1="<?= $kkm_y ?>" x2="<?= $line_x2 ?>" y2="<?= $kkm_y ?>" stroke="#fff"
                    stroke-width="4" stroke-dasharray="8,5" />
                <!-- garis KKM merah tegas -->
                <line x1="<?= $line_x1 ?>" y1="<?= $kkm_y ?>" x2="<?= $line_x2 ?>" y2="<?= $kkm_y ?>" stroke="#ef4444"
                    stroke-width="2.5" stroke-dasharray="8,5" />
                <!-- pill label KKM di kanan -->
                <rect x="<?= $pill_x ?>" y="<?= $pill_y ?>" width="<?= $pill_w ?>" height="<?= $pill_h ?>" rx="10"
                    fill="#ef4444" />
                <text x="<?= $pill_x + $pill_w/2 ?>" y="<?= $kkm_y + 5 ?>" text-anchor="middle" font-size="10.5"
                    font-weight="700" fill="#fff" letter-spacing="0.4">KKM <?= (int)$krit_c_val ?></text>
                <!-- Bars -->
                <?php foreach ($bar_data as $bi => $br):
          $bval  = (float)$br['avg_nilai'];
          $bclr  = $pal[$bi % count($pal)];
          $bbg   = $pal_bg[$bi % count($pal_bg)];
          $bx    = $pad_left + $bi * ($bar_w_per + $bar_gap) + (int)($bar_gap/2);
          $bbarw = $bar_w_per;
          $bh    = max(6, $chart_h * ($bval / $bar_max));
          $by    = $pad_top + $chart_h - $bh;
          $lvy   = $by - 7;
          $num   = $bi + 1; // nomor urut 1..n
        ?>
                <!-- bar bg track -->
                <rect x="<?= $bx ?>" y="<?= $pad_top ?>" width="<?= $bbarw ?>" height="<?= $chart_h ?>"
                    fill="<?= $bbg ?>" rx="5" opacity="0.6" />
                <!-- bar fill -->
                <rect x="<?= $bx ?>" y="<?= $by ?>" width="<?= $bbarw ?>" height="<?= $bh ?>" fill="<?= $bclr ?>" rx="5"
                    opacity="0.88">
                    <title><?= htmlspecialchars($br['nama_mapel']) ?>: <?= $bval ?></title>
                </rect>
                <!-- nilai label di atas bar -->
                <text x="<?= $bx + $bbarw/2 ?>" y="<?= $lvy ?>" text-anchor="middle" font-size="11" font-weight="700"
                    fill="<?= $bclr ?>"><?= number_format($bval,1) ?></text>
                <!-- nomor urut di bawah bar -->
                <text x="<?= $bx + $bbarw/2 ?>" y="<?= $pad_top+$chart_h+17 ?>" text-anchor="middle" font-size="11"
                    font-weight="600" fill="<?= $bclr ?>"><?= $num ?></text>
                <?php endforeach; ?>
                <!-- X axis -->
                <line x1="<?= $pad_left ?>" y1="<?= $pad_top+$chart_h ?>" x2="<?= $svg_w-16 ?>"
                    y2="<?= $pad_top+$chart_h ?>" stroke="#e5e7eb" stroke-width="1.5" />
            </svg>
            <!-- Legend: grid 2 kolom, nomor + dot warna + nama + nilai -->
            <div class="bar-legend-grid" style="margin-top:14px;border-top:1px solid #f3f4f6;padding-top:12px">
                <?php foreach ($bar_data as $bi => $br): ?>
                <div class="bar-legend-item">
                    <span class="bar-legend-num" style="color:<?= $pal[$bi%count($pal)] ?>"><?= $bi+1 ?></span>
                    <span class="bar-legend-dot" style="background:<?= $pal[$bi%count($pal)] ?>"></span>
                    <span class="bar-legend-name"><?= htmlspecialchars($br['nama_mapel']) ?></span>
                    <span class="bar-legend-val"
                        style="color:<?= $pal[$bi%count($pal)] ?>"><?= number_format((float)$br['avg_nilai'],1) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($chart_bulan_list) && !empty($chart_mapel_list)): ?>
    <?php
    // ── Data untuk pie chart per mapel per bulan ──────────────
    // $chart_data[nama_mapel][bulan] = nilai
    // $chart_bulan_list = sorted array of int bulan
    // $chart_mapel_list = array of nama_mapel
    $pc_bulan = $chart_bulan_list;   // mis. [10] atau [10,11,12]
    $pc_mapel = $chart_mapel_list;   // array nama mapel
    $pc_data  = $chart_data;         // [mapel][bulan] = nilai
    $pc_n     = count($pc_bulan);
    $pc_kkm   = (float)(sekolah_info()['kriteria_c_min'] ?? 76);

    // Palet warna untuk pie (sama dengan bar chart atas)
    $pc_pal = $pal;

    // Buat map index mapel → warna
    $pc_mapel_color = [];
    foreach ($pc_mapel as $mi => $mn) {
      $pc_mapel_color[$mn] = $pc_pal[$mi % count($pc_pal)];
    }

    // Encode data ke JSON untuk JavaScript
    $pc_js_data   = json_encode($pc_data);
    $pc_js_bulan  = json_encode($pc_bulan);
    $pc_js_mapel  = json_encode($pc_mapel);
    $pc_js_colors = json_encode($pc_mapel_color);
    $pc_js_bnames = json_encode(array_combine(
      $pc_bulan,
      array_map(fn($b) => $bulan_id_arr[$b] ?? 'Bulan '.$b, $pc_bulan)
    ));
    $pc_js_kkm = $pc_kkm;
    // Mapel pertama sebagai default
    $pc_default_mapel = htmlspecialchars($pc_mapel[0] ?? '', ENT_QUOTES);
  ?>

    <!-- ── GRID: Line chart + Pie chart bersebelahan ──────── -->
    <div style="display:grid;gap:16px;margin-top:16px" class="dash-chart-grid">

    <!-- ── LINE CHART: Rata-rata Nilai per Bulan ────────────── -->
    <div class="card" style="overflow:hidden" id="card-line-chart">
        <div class="card-header" style="background:linear-gradient(135deg,#f0fdf4 0%,#fff 60%)">
            <div style="display:flex;align-items:center;gap:10px">
                <span style="background:#22c55e;border-radius:8px;padding:6px 8px;display:flex;flex-shrink:0">
                    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                    </svg>
                </span>
                <div>
                    <div style="font-weight:700;font-size:.95rem;color:#1f2937">Tren Nilai per Bulan</div>
                    <div style="font-size:.75rem;color:#9ca3af">
                        Raport Bulanan &bull;
                        TA <?= e($filter_ta['nama'] ?? '') ?> &mdash; Sem. <?= $filter_sem_label ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body" style="padding:16px 12px 12px;overflow-x:auto">
            <?php
            // ── Hitung rata-rata TOTAL semua mapel per bulan ─────
            $lc_bulan = $chart_bulan_list; // sorted [int]
            $lc_nb    = count($lc_bulan);
            $lc_avg   = []; // [bulan_int => avg_float]
            foreach ($lc_bulan as $bl) {
                $vals = [];
                foreach ($chart_mapel_list as $mn) {
                    if (isset($chart_data[$mn][$bl])) $vals[] = (float)$chart_data[$mn][$bl];
                }
                $lc_avg[$bl] = !empty($vals) ? round(array_sum($vals) / count($vals), 2) : null;
            }

            $lc_w  = 340; $lc_h = 220;
            $lc_pl = 36;  $lc_pt = 20; $lc_pr = 14; $lc_pb = 44;
            $lc_cw = $lc_w - $lc_pl - $lc_pr;
            $lc_ch = $lc_h - $lc_pt - $lc_pb;
            $lc_kkm = (float)(sekolah_info()['kriteria_c_min'] ?? 76);

            $lc_y = fn($v) => round($lc_pt + $lc_ch - ($v / 100) * $lc_ch, 1);
            $lc_x = fn($i) => $lc_nb <= 1
                ? round($lc_pl + $lc_cw / 2, 1)
                : round($lc_pl + $i * ($lc_cw / ($lc_nb - 1)), 1);

            // Kumpulkan titik yang valid
            $lc_pts = [];
            foreach ($lc_bulan as $bi => $bl) {
                if ($lc_avg[$bl] !== null) $lc_pts[] = [$bi, $lc_avg[$bl], $bl];
            }
            ?>
            <svg viewBox="0 0 <?= $lc_w ?> <?= $lc_h ?>" width="100%" style="display:block;min-width:240px">

                <!-- Grid + label Y -->
                <?php for ($gi = 0; $gi <= 5; $gi++):
                    $gv = $gi * 20; $gy = $lc_y($gv);
                ?>
                <line x1="<?= $lc_pl ?>" y1="<?= $gy ?>" x2="<?= $lc_w-$lc_pr ?>" y2="<?= $gy ?>"
                      stroke="#f3f4f6" stroke-width="1"/>
                <text x="<?= $lc_pl-4 ?>" y="<?= $gy+4 ?>" text-anchor="end" font-size="9" fill="#9ca3af"><?= $gv ?></text>
                <?php endfor; ?>

                <!-- KKM line -->
                <?php $lc_ykkm = $lc_y($lc_kkm); ?>
                <line x1="<?= $lc_pl ?>" y1="<?= $lc_ykkm ?>" x2="<?= $lc_w-$lc_pr ?>" y2="<?= $lc_ykkm ?>"
                      stroke="#ef4444" stroke-width="1" stroke-dasharray="4,3"/>
                <text x="<?= $lc_w-$lc_pr-2 ?>" y="<?= $lc_ykkm-3 ?>" text-anchor="end"
                      font-size="8" fill="#ef4444">KKM</text>

                <!-- X axis base + labels -->
                <line x1="<?= $lc_pl ?>" y1="<?= $lc_pt+$lc_ch ?>" x2="<?= $lc_w-$lc_pr ?>" y2="<?= $lc_pt+$lc_ch ?>"
                      stroke="#e5e7eb" stroke-width="1"/>
                <?php foreach ($lc_bulan as $bi => $bl): ?>
                <text x="<?= $lc_x($bi) ?>" y="<?= $lc_pt+$lc_ch+14 ?>" text-anchor="middle"
                      font-size="9" fill="#6b7280"><?= substr($bulan_id_arr[$bl] ?? ('Bln'.$bl), 0, 3) ?></text>
                <?php endforeach; ?>

                <?php if (count($lc_pts) >= 2):
                    // Area fill bawah garis
                    $firstPt = $lc_pts[0];
                    $lastPt  = $lc_pts[count($lc_pts)-1];
                    $areaStr = $lc_x($firstPt[0]).','.$lc_y(0).' '.
                               implode(' ', array_map(fn($p) => $lc_x($p[0]).','.$lc_y($p[1]), $lc_pts)).
                               ' '.$lc_x($lastPt[0]).','.$lc_y(0);
                    $lineStr = implode(' ', array_map(fn($p) => $lc_x($p[0]).','.$lc_y($p[1]), $lc_pts));
                ?>
                <!-- Area fill -->
                <polygon points="<?= $areaStr ?>" fill="#22c55e" fill-opacity="0.08"/>
                <!-- Main line -->
                <polyline points="<?= $lineStr ?>" fill="none" stroke="#22c55e"
                          stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                <?php endif; ?>

                <!-- Dots + nilai label -->
                <?php foreach ($lc_pts as [$bi, $avg, $bl]):
                    $dx = $lc_x($bi); $dy = $lc_y($avg);
                    $above = $avg >= $lc_kkm;
                    $dotClr = $above ? '#22c55e' : '#f97316';
                ?>
                <circle cx="<?= $dx ?>" cy="<?= $dy ?>" r="5" fill="<?= $dotClr ?>" stroke="#fff" stroke-width="2">
                    <title><?= $bulan_id_arr[$bl] ?? 'Bulan '.$bl ?>: <?= $avg ?></title>
                </circle>
                <text x="<?= $dx ?>" y="<?= $dy-10 ?>" text-anchor="middle"
                      font-size="10" font-weight="700" fill="<?= $dotClr ?>"><?= $avg ?></text>
                <?php endforeach; ?>

            </svg>

            <!-- Keterangan ringkas -->
            <div style="display:flex;align-items:center;gap:16px;margin-top:8px;padding-top:8px;border-top:1px solid #f3f4f6;font-size:.75rem;color:#6b7280">
                <div style="display:flex;align-items:center;gap:5px">
                    <span style="width:16px;height:3px;background:#22c55e;border-radius:2px;display:inline-block"></span>
                    Rata-rata semua mapel
                </div>
                <div style="display:flex;align-items:center;gap:5px">
                    <span style="width:14px;height:2px;background:#ef4444;display:inline-block;border-top:1px dashed #ef4444"></span>
                    KKM <?= $lc_kkm ?>
                </div>
            </div>
        </div>
    </div><!-- /#card-line-chart -->

    <!-- ── PIE CHART: Perbandingan Nilai Bulanan per Mapel ─── -->
    <div class="card" style="overflow:hidden" id="card-pie-chart">
        <div class="card-header" style="background:linear-gradient(135deg,#eff6ff 0%,#fff 60%)">
            <div style="display:flex;align-items:center;gap:10px;min-width:0">
                <span style="background:#3b82f6;border-radius:8px;padding:6px 8px;display:flex;flex-shrink:0">
                    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24">
                        <path d="M21.21 15.89A10 10 0 1 1 8 2.83" />
                        <path d="M22 12A10 10 0 0 0 12 2v10z" />
                    </svg>
                </span>
                <div style="min-width:0">
                    <div style="font-weight:700;font-size:.95rem;color:#1f2937">Perbandingan Nilai Bulanan per Mapel</div>
                    <div style="font-size:.75rem;color:#9ca3af;white-space:normal">
                        Raport Bulanan &bull;
                        TA <?= e($filter_ta['nama'] ?? '') ?> Sem. <?= $filter_sem_label ?>
                        &bull; Kelas <?= $filter_kelas_nama ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body" style="padding:16px 20px 20px">
            <!-- Combobox pilih mapel — di dalam body agar tidak overflow header -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f3f4f6">
                <label for="pie-mapel-select" style="font-size:.82rem;font-weight:600;color:#374151;white-space:nowrap;flex-shrink:0">Mata Pelajaran:</label>
                <div style="position:relative;flex:1;min-width:0">
                    <select id="pie-mapel-select" class="pie-select">
                        <?php foreach ($pc_mapel as $mn): ?>
                        <option value="<?= htmlspecialchars($mn, ENT_QUOTES) ?>"><?= htmlspecialchars($mn) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none"
                        width="12" height="12" fill="none" stroke="#6b7280" stroke-width="2.5" viewBox="0 0 24 24">
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </div>
            </div>
            <!-- Container pie charts — diisi JS -->
            <div id="pie-charts-container"
                style="display:flex;flex-wrap:wrap;gap:24px;justify-content:center;align-items:flex-start;min-height:200px">
            </div>
            <!-- Info di bawah -->
            <div id="pie-info-bar"
                style="margin-top:20px;padding-top:16px;border-top:1px solid #f3f4f6;display:flex;flex-wrap:wrap;gap:16px;justify-content:center">
            </div>
        </div>
    </div>

    <script>
    (function() {
        var chartData = <?= $pc_js_data ?>;
        var bulanList = <?= $pc_js_bulan ?>;
        var mapelList = <?= $pc_js_mapel ?>;
        var mapelColors = <?= $pc_js_colors ?>;
        var bulanNames = <?= $pc_js_bnames ?>;
        var kkm = <?= $pc_js_kkm ?>;

        var container = document.getElementById('pie-charts-container');
        var infoBar = document.getElementById('pie-info-bar');
        var selectEl = document.getElementById('pie-mapel-select');

        // ── Draw a single donut pie chart as inline SVG ──────────
        function makePie(mapel, bulan, nilai, color, bulanName) {
            var r = 70; // radius lingkaran luar
            var ri = 46; // radius inner (donut hole)
            var cx = 90,
                cy = 90;
            var size = 180;

            // nilai = 0..100, arc = proporsi dari lingkaran penuh
            var pct = Math.min(Math.max(nilai, 0), 100) / 100;
            var angle = pct * 2 * Math.PI;
            var startX = cx + r * Math.sin(0);
            var startY = cy - r * Math.cos(0);
            var endX = cx + r * Math.sin(angle);
            var endY = cy - r * Math.cos(angle);
            var large = angle > Math.PI ? 1 : 0;

            // Inner ring start/end
            var iStartX = cx + ri * Math.sin(0);
            var iStartY = cy - ri * Math.cos(0);
            var iEndX = cx + ri * Math.sin(angle);
            var iEndY = cy - ri * Math.cos(angle);

            var aboveKkm = nilai >= kkm;
            var grayClr = '#e5e7eb';

            // Path for filled arc (donut segment)
            var dFill = nilai >= 100 ? ['M', cx, cy - r,
                'A', r, r, 0, 1, 1, cx - 0.001, cy - r,
                'Z',
                'M', cx, cy - ri,
                'A', ri, ri, 0, 1, 0, cx + 0.001, cy - ri,
                'Z'
            ].join(' ') : ['M', startX.toFixed(2), startY.toFixed(2),
                'A', r, r, 0, large, 1, endX.toFixed(2), endY.toFixed(2),
                'L', iEndX.toFixed(2), iEndY.toFixed(2),
                'A', ri, ri, 0, large, 0, iStartX.toFixed(2), iStartY.toFixed(2),
                'Z'
            ].join(' ');

            // Path for remaining arc (background)
            var remAngle = (1 - pct) * 2 * Math.PI;
            var rEndX = cx + r * Math.sin(angle + remAngle);
            var rEndY = cy - r * Math.cos(angle + remAngle);
            var riEndX = cx + ri * Math.sin(angle + remAngle);
            var riEndY = cy - ri * Math.cos(angle + remAngle);
            var rLarge = remAngle > Math.PI ? 1 : 0;

            var dBg = nilai <= 0 ?
                '' : ['M', endX.toFixed(2), endY.toFixed(2),
                    'A', r, r, 0, rLarge, 1, rEndX.toFixed(2), rEndY.toFixed(2),
                    'L', riEndX.toFixed(2), riEndY.toFixed(2),
                    'A', ri, ri, 0, rLarge, 0, iEndX.toFixed(2), iEndY.toFixed(2),
                    'Z'
                ].join(' ');

            // Badge warna: hijau jika di atas KKM, merah jika di bawah
            var badgeClr = aboveKkm ? '#22c55e' : '#ef4444';
            var badgeTxt = aboveKkm ? '✓' : '✗';

            var ns = 'http://www.w3.org/2000/svg';
            var wrap = document.createElement('div');
            wrap.style.cssText = 'text-align:center;flex:0 0 auto;';

            var svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
            svg.setAttribute('width', size);
            svg.setAttribute('height', size);
            svg.style.overflow = 'visible';

            // Background full circle (sisa nilai)
            if (dBg) {
                var bgPath = document.createElementNS(ns, 'path');
                bgPath.setAttribute('d', dBg);
                bgPath.setAttribute('fill', grayClr);
                svg.appendChild(bgPath);
            }
            // Fill arc (nilai)
            var fillPath = document.createElementNS(ns, 'path');
            fillPath.setAttribute('d', dFill);
            fillPath.setAttribute('fill', color);
            svg.appendChild(fillPath);

            // Center text: nilai angka
            var tVal = document.createElementNS(ns, 'text');
            tVal.setAttribute('x', cx);
            tVal.setAttribute('y', cy + 7);
            tVal.setAttribute('text-anchor', 'middle');
            tVal.setAttribute('font-size', '22');
            tVal.setAttribute('font-weight', '800');
            tVal.setAttribute('fill', color);
            tVal.textContent = nilai.toFixed(1);
            svg.appendChild(tVal);

            // Center sub-text: /100
            var tSub = document.createElementNS(ns, 'text');
            tSub.setAttribute('x', cx);
            tSub.setAttribute('y', cy + 22);
            tSub.setAttribute('text-anchor', 'middle');
            tSub.setAttribute('font-size', '11');
            tSub.setAttribute('fill', '#9ca3af');
            tSub.textContent = '/ 100';
            svg.appendChild(tSub);

            // Badge atas kanan
            var badge = document.createElementNS(ns, 'circle');
            badge.setAttribute('cx', cx + r * 0.68);
            badge.setAttribute('cy', cy - r * 0.68);
            badge.setAttribute('r', '11');
            badge.setAttribute('fill', badgeClr);
            svg.appendChild(badge);
            var badgeT = document.createElementNS(ns, 'text');
            badgeT.setAttribute('x', cx + r * 0.68);
            badgeT.setAttribute('y', cy - r * 0.68 + 4);
            badgeT.setAttribute('text-anchor', 'middle');
            badgeT.setAttribute('font-size', '12');
            badgeT.setAttribute('font-weight', '700');
            badgeT.setAttribute('fill', '#fff');
            badgeT.textContent = badgeTxt;
            svg.appendChild(badgeT);

            wrap.appendChild(svg);

            // Nama bulan di bawah pie
            var lbl = document.createElement('div');
            lbl.style.cssText = 'font-size:.85rem;font-weight:700;color:#374151;margin-top:6px';
            lbl.textContent = bulanName;
            wrap.appendChild(lbl);

            // KKM info
            var kkmLbl = document.createElement('div');
            kkmLbl.style.cssText = 'font-size:.75rem;color:' + badgeClr + ';margin-top:2px;font-weight:600';
            kkmLbl.textContent = aboveKkm ? 'Di atas KKM (' + kkm + ')' : 'Di bawah KKM (' + kkm + ')';
            wrap.appendChild(kkmLbl);

            return wrap;
        }

        // ── Render semua pie untuk mapel yang dipilih ─────────────
        function renderPies(mapel) {
            container.innerHTML = '';
            infoBar.innerHTML = '';

            var color = mapelColors[mapel] || '#3b82f6';
            var hasAny = false;

            bulanList.forEach(function(bl) {
                var blStr = String(bl);
                var mapelData = chartData[mapel];
                if (!mapelData) return;
                var nilai = mapelData[blStr] !== undefined ? parseFloat(mapelData[blStr]) : null;
                if (nilai === null) return;
                hasAny = true;
                var bulanName = bulanNames[blStr] || ('Bulan ' + bl);
                container.appendChild(makePie(mapel, bl, nilai, color, bulanName));
            });

            if (!hasAny) {
                container.innerHTML =
                    '<div style="color:#9ca3af;font-size:.88rem;padding:40px 0;width:100%;text-align:center">Belum ada data nilai bulanan untuk mata pelajaran ini</div>';
            }

            // Info bar: ringkasan semua bulan
            bulanList.forEach(function(bl) {
                var blStr = String(bl);
                var mapelData = chartData[mapel];
                if (!mapelData || mapelData[blStr] === undefined) return;
                var nilai = parseFloat(mapelData[blStr]);
                var bulanName = bulanNames[blStr] || ('Bulan ' + bl);
                var above = nilai >= kkm;
                var chip = document.createElement('div');
                chip.style.cssText = 'display:flex;align-items:center;gap:6px;background:' + (above ?
                        '#f0fdf4' : '#fef2f2') + ';border:1px solid ' + (above ? '#bbf7d0' : '#fecaca') +
                    ';border-radius:20px;padding:4px 12px;font-size:.8rem';
                chip.innerHTML = '<span style="font-weight:700;color:' + color + '">' + bulanName +
                    '</span>' +
                    '<span style="color:#374151">' + nilai.toFixed(1) + '</span>' +
                    '<span style="color:' + (above ? '#22c55e' : '#ef4444') + ';font-weight:700">' + (
                        above ? '▲' : '▼') + '</span>';
                infoBar.appendChild(chip);
            });
        }

        // ── Event listener combobox ────────────────────────────────
        selectEl.addEventListener('change', function() {
            renderPies(this.value);
        });

        // Render default (mapel pertama)
        if (mapelList.length > 0) renderPies(mapelList[0]);
    })();
    </script>

    </div><!-- /.dash-chart-grid -->
    <?php endif; ?>

    <?php if (empty($leger_rows)): ?>
    <!-- State kosong jika belum ada nilai sama sekali -->
    <div class="card mt-6" style="text-align:center;padding:48px 24px">
        <svg width="64" height="64" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24"
            style="margin:0 auto 16px">
            <path
                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
        </svg>
        <p style="font-size:.95rem;font-weight:600;color:#6b7280;margin:0 0 6px">Belum ada data nilai</p>
        <p style="font-size:.82rem;color:#9ca3af;margin:0">Grafik akan muncul setelah guru menginput nilai raport</p>
    </div>
    <?php endif; ?>

    <!-- ── Responsive CSS untuk grafik siswa ──────────────── -->
    <style>
    /* Legend grid bar chart: 2 kolom default, 1 kolom di mobile */
    .bar-legend-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 6px 20px;
    }

    .bar-legend-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: .78rem;
        color: #374151;
        min-width: 0;
    }

    .bar-legend-num {
        font-size: .72rem;
        font-weight: 700;
        min-width: 18px;
        text-align: right;
        flex-shrink: 0;
    }

    .bar-legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        flex-shrink: 0;
    }

    .bar-legend-name {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: #374151;
    }

    .bar-legend-val {
        font-weight: 700;
        flex-shrink: 0;
        font-size: .78rem;
    }

    /* Pie card header: row di desktop, column di mobile */
    .pie-card-header-inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        width: 100%;
    }

    .pie-select-wrap {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
        min-width: 0;
        max-width: 420px;
    }

    .pie-select {
        width: 100%;
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        padding: 7px 32px 7px 12px;
        font-size: .85rem;
        color: #1f2937;
        background: #fff;
        cursor: pointer;
        outline: none;
        appearance: none;
        -webkit-appearance: none;
        min-width: 0;
    }

    .pie-select:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .1);
    }

    /* Side-by-side chart grid */
    .dash-chart-grid {
        grid-template-columns: 1fr 1fr;
    }

    /* Mobile <= 600px */
    @media (max-width: 600px) {
        .dash-chart-grid {
            grid-template-columns: 1fr;
        }

        .bar-legend-grid {
            grid-template-columns: 1fr;
        }

        .pie-card-header-inner {
            flex-direction: column;
            align-items: flex-start;
        }

        .pie-select-wrap {
            max-width: 100%;
            width: 100%;
        }

        #pie-charts-container {
            gap: 16px;
        }

        #pie-charts-container>div {
            flex: 0 0 auto;
        }

        .card-header {
            flex-wrap: wrap;
        }
    }

    /* Tablet <= 900px */
    @media (max-width: 900px) {
        .dash-chart-grid {
            grid-template-columns: 1fr;
        }

        .bar-legend-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .pie-card-header-inner {
            flex-direction: column;
            align-items: flex-start;
        }

        .pie-select-wrap {
            max-width: 100%;
            width: 100%;
        }

        .card-header {
            flex-wrap: wrap;
        }
    }
    </style>

    <?php endif; // role === 'siswa' ?>

    <!-- ── RECENT ACTIVITY ─────────────────────────────────── -->
    <?php if (!empty($recent_nilai)): ?>
    <div class="card">
        <div class="card-header">
            <span>Aktivitas Nilai Terbaru</span>
            <a href="index.php?page=nilai" class="btn btn-sm btn-secondary" style="border-radius: 30px">Lihat Semua</a>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Siswa</th>
                        <th>Kelas</th>
                        <th>Mata Pelajaran</th>
                        <th>Jenis</th>
                        <th>Nilai Akhir</th>
                        <th>Predikat</th>
                        <th>Diperbarui</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_nilai as $n): ?>
                    <tr>
                        <td class="font-semibold"><?= e($n['nama_siswa']) ?></td>
                        <td><?= e($n['nama_kelas']) ?></td>
                        <td><?= e($n['nama_mapel']) ?></td>
                        <td>
                            <span class="badge <?= $n['jenis_raport'] === 'bulanan' ? 'badge-blue' : 'badge-orange' ?>">
                                <?= ucfirst($n['jenis_raport']) ?>
                            </span>
                        </td>
                        <td class="font-bold"><?= number_format($n['nilai_akhir'], 2) ?></td>
                        <td>
                            <?php $pred = $n['predikat'] ?? get_predikat((float)$n['nilai_akhir']); ?>
                            <span
                                class="badge <?= $pred==='A'?'badge-green':($pred==='B'?'badge-blue':($pred==='C'?'badge-orange':'badge-red')) ?>">
                                <?= $pred ?>
                            </span>
                        </td>
                        <td class="text-gray-400 text-xs"><?= date('d M Y H:i', strtotime($n['updated_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── QUICK LINKS untuk Admin ────────────────────────── -->
    <?php if (in_array($role, ['admin','super_admin'])): ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
        <?php
    $quick = [
      ['page'=>'siswa.create','label'=>'Tambah Siswa','icon'=>'<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>','color'=>'bg-orange-50 text-orange-600'],
      ['page'=>'guru.create','label'=>'Tambah Guru','icon'=>'<circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 10-16 0"/>','color'=>'bg-blue-50 text-blue-600'],
      ['page'=>'kelas.create','label'=>'Tambah Kelas','icon'=>'<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>','color'=>'bg-green-50 text-green-600'],
      ['page'=>'mapel.create','label'=>'Tambah Mapel','icon'=>'<path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>','color'=>'bg-purple-50 text-purple-600'],
    ];
    ?>
        <?php foreach ($quick as $q): ?>
        <a href="index.php?page=<?= $q['page'] ?>"
            class="card flex flex-col items-center justify-center p-5 hover:shadow-md transition-all text-center"
            style="text-decoration:none">
            <div class="<?= $q['color'] ?> rounded-xl p-3 mb-3">
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <?= $q['icon'] ?>
                </svg>
            </div>
            <span class="text-sm font-semibold text-gray-700"><?= $q['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>