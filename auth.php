<?php
/**
 * auth.php
 * Helper fungsi autentikasi & otorisasi
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Mulai session jika belum
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Cek apakah user sudah login
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Ambil data user yang sedang login dari session
 */
function current_user(): ?array {
    if (!is_logged_in()) return null;
    return [
        'id'           => $_SESSION['user_id'],
        'username'     => $_SESSION['username'],
        'nama_lengkap' => $_SESSION['nama_lengkap'],
        'role'         => $_SESSION['role'],
        'tanda_tangan' => $_SESSION['tanda_tangan'] ?? null,
        'foto'         => $_SESSION['foto'] ?? null,
    ];
}

/**
 * Cek apakah user memiliki role tertentu
 * @param string|array $roles
 */
function has_role($roles): bool {
    if (!is_logged_in()) return false;
    $roles = (array) $roles;
    return in_array($_SESSION['role'], $roles, true);
}

/**
 * Redirect ke login jika belum login
 */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: index.php?page=login');
        exit;
    }
}

/**
 * Require role tertentu, redirect 403 jika tidak sesuai
 */
function require_role($roles): void {
    require_login();
    if (!has_role($roles)) {
        http_response_code(403);
        include __DIR__ . '/pages/403.php';
        exit;
    }
}

/**
 * Proses login
 * @return array ['success' => bool, 'message' => string]
 */
function do_login(string $username, string $password): array {
    $user = db_row(
        'SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1',
        [':u' => $username]
    );

    if (!$user) {
        return ['success' => false, 'message' => 'Username atau password salah.'];
    }

    // Dukung MD5 (hash lama) maupun bcrypt (hash baru)
    $hash = $user['password'];
    $valid = false;

    if (strlen($hash) === 32 && ctype_xdigit($hash)) {
        // Hash MD5 — bandingkan langsung
        $valid = ($hash === md5($password));
    } else {
        // Hash bcrypt (password_hash)
        $valid = password_verify($password, $hash);
    }

    if (!$valid) {
        return ['success' => false, 'message' => 'Username atau password salah.'];
    }

    // Set session
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
    $_SESSION['role']         = $user['role'];
    $_SESSION['tanda_tangan'] = $user['tanda_tangan'];
    $_SESSION['foto']         = $user['foto'];

    return ['success' => true, 'message' => 'Login berhasil.'];
}

/**
 * Proses logout
 */
function do_logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

/**
 * Generate CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifikasi CSRF token
 */
function verify_csrf(): bool {
    $token = $_POST['_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Render hidden input CSRF
 */
function csrf_field(): string {
    return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Escape output
 */
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Flash message: set
 */
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

/**
 * Flash message: get & clear
 */
function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/**
 * Hitung predikat nilai berdasarkan kriteria sekolah.
 * Jika kriteria tidak diberikan, ambil dari tabel pengaturan (sekolah_info).
 * Parameter $krit_a, $krit_b, $krit_c bisa diisi manual atau dibiarkan null
 * agar fungsi membacanya sendiri dari DB.
 */
function get_predikat(float $nilai, float $krit_a = null, float $krit_b = null, float $krit_c = null): string {
    if ($krit_a === null || $krit_b === null || $krit_c === null) {
        $s = sekolah_info();
        $krit_a = (float)($s['kriteria_a_min'] ?? 92);
        $krit_b = (float)($s['kriteria_b_min'] ?? 83);
        $krit_c = (float)($s['kriteria_c_min'] ?? 76);
    }
    if ($nilai >= $krit_a) return 'A';
    if ($nilai >= $krit_b) return 'B';
    if ($nilai >= $krit_c) return 'C';
    return 'D';
}

/**
 * Hitung nilai akhir BULANAN
 * Komponen: Nilai Harian (NH1+NH2+NH3) dan Nilai Tugas
 * Bobot: rata-rata harian = 70%, tugas = 30%
 */
function hitung_nilai_bulanan(float $h1, float $h2, float $h3, float $tugas): float {
    $rata_harian = ($h1 + $h2 + $h3) / 3;
    return round(($rata_harian * 0.7) + ($tugas * 0.3), 2);
}

/**
 * Hitung nilai akhir STS (Sumatif Tengah Semester)
 * Komponen: Nilai STS saja
 */
function hitung_nilai_sts(float $sts): float {
    return round($sts, 2);
}

/**
 * Hitung nilai akhir SEMESTER (SAS saja)
 */
function hitung_nilai_semester(float $sas): float {
    return round($sas, 2);
}
