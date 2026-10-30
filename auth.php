<?php
/**
 * auth.php
 * Helper fungsi autentikasi & otorisasi
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Mulai session jika belum
if (session_status() === PHP_SESSION_NONE) {
    // Deteksi HTTPS secara otomatis
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $is_https,  // true otomatis di HTTPS/hosting
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Regenerasi session ID tiap 30 menit untuk cegah session fixation
    if (!isset($_SESSION['_last_regen'])) {
        $_SESSION['_last_regen'] = time();
    } elseif (time() - $_SESSION['_last_regen'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }
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
 * Cek apakah user adalah super_admin
 */
function is_super_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'super_admin';
}

/**
 * Cek apakah user adalah super_admin atau admin
 */
function is_admin_or_super(): bool {
    return in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'], true);
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
 * Cek apakah IP saat ini sedang dalam periode lockout (brute force protection).
 * Menyimpan hitungan percobaan di session — tidak butuh tabel DB tambahan.
 * @return array ['locked' => bool, 'wait' => int (detik tersisa)]
 */
function check_login_lockout(): array {
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key      = 'login_fails_' . md5($ip);
    $ts_key   = 'login_lockout_until_' . md5($ip);
    $max      = 5;      // maks percobaan gagal
    $window   = 300;    // 5 menit lockout setelah $max gagal

    $until = $_SESSION[$ts_key] ?? 0;
    if ($until > time()) {
        return ['locked' => true, 'wait' => (int)($until - time())];
    }
    return ['locked' => false, 'wait' => 0];
}

/**
 * Catat percobaan login gagal untuk IP ini.
 */
function record_login_fail(): void {
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key   = 'login_fails_' . md5($ip);
    $ts_key = 'login_lockout_until_' . md5($ip);
    $max   = 5;
    $window = 300;

    $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
    if ($_SESSION[$key] >= $max) {
        $_SESSION[$ts_key] = time() + $window;
        $_SESSION[$key]    = 0; // reset hitung
    }
}

/**
 * Reset hitungan gagal setelah login berhasil.
 */
function reset_login_fail(): void {
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key   = 'login_fails_' . md5($ip);
    $ts_key = 'login_lockout_until_' . md5($ip);
    unset($_SESSION[$key], $_SESSION[$ts_key]);
}

/**
 * Proses login
 * @return array ['success' => bool, 'message' => string, 'locked' => bool]
 */
function do_login(string $username, string $password): array {
    // Cek lockout sebelum proses apapun
    $lockout = check_login_lockout();
    if ($lockout['locked']) {
        $menit = ceil($lockout['wait'] / 60);
        return [
            'success' => false,
            'locked'  => true,
            'message' => "Terlalu banyak percobaan login. Coba lagi dalam {$menit} menit.",
        ];
    }

    $user = db_row(
        'SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1',
        [':u' => $username]
    );

    if (!$user) {
        record_login_fail();
        return ['success' => false, 'locked' => false, 'message' => 'Username atau password salah.'];
    }

    // Dukung MD5 (hash lama) maupun bcrypt (hash baru)
    $hash  = $user['password'];
    $valid = false;

    if (strlen($hash) === 32 && ctype_xdigit($hash)) {
        $valid = ($hash === md5($password));
    } else {
        $valid = password_verify($password, $hash);
    }

    if (!$valid) {
        record_login_fail();
        return ['success' => false, 'locked' => false, 'message' => 'Username atau password salah.'];
    }

    // Login berhasil — reset fail counter, regenerasi session
    reset_login_fail();
    session_regenerate_id(true);
    $_SESSION['_last_regen'] = time();

    $_SESSION['user_id']      = $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
    $_SESSION['role']         = $user['role'];
    $_SESSION['tanda_tangan'] = $user['tanda_tangan'];
    $_SESSION['foto']         = $user['foto'];

    return ['success' => true, 'locked' => false, 'message' => 'Login berhasil.'];
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
 * Hitung nilai akhir BULANAN &mdash; fleksibel.
 * Rata-rata dihitung hanya dari komponen yang terisi (nilai > 0).
 * Contoh: NH1=90 saja &rarr; NA=90 | NH1=90,NH2=80 &rarr; NA=85 | semua 4 &rarr; NA=(sum/4)
 */
function hitung_nilai_bulanan(float $h1, float $h2, float $h3, float $tugas): float {
    $komponen = array_filter([$h1, $h2, $h3, $tugas], fn($v) => $v > 0);
    if (count($komponen) === 0) return 0;
    return round(array_sum($komponen) / count($komponen), 2);
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
