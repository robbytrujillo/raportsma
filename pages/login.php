<?php
/**
 * pages/login.php
 * Halaman login — tampilan baru dengan split layout & thumbnail
 */
require_once __DIR__ . '/../auth.php';

// Jika sudah login, redirect ke dashboard
if (is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$error   = '';
$locked  = false;
$lockout = check_login_lockout();

if ($lockout['locked']) {
    $menit  = ceil($lockout['wait'] / 60);
    $error  = "Terlalu banyak percobaan login. Coba lagi dalam {$menit} menit.";
    $locked = true;
}

// Proses form login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    if (!verify_csrf()) {
        $error = 'Sesi tidak valid. Silakan muat ulang halaman.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username dan password wajib diisi.';
        } else {
            $result = do_login($username, $password);
            if ($result['success']) {
                header('Location: index.php?page=dashboard');
                exit;
            } else {
                $error  = $result['message'];
                $locked = $result['locked'] ?? false;
            }
        }
    }
}

$sekolah_l = sekolah_info();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= APP_NAME ?></title>

    <!-- Favicon -->
    <?php if (!empty($sekolah_l['logo'])): ?>
    <link rel="icon" type="image/png" href="<?= UPLOAD_URL . e($sekolah_l['logo']) ?>">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='5' fill='%23f97316'/><path d='M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z' fill='none' stroke='white' stroke-width='2'/></svg>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/style.css">

    <style>
    /* ── Login Split Layout ──────────────────────────────── */
    .login-split {
        min-height: 100vh;
        display: flex;
        font-family: 'Poppins', sans-serif;
        background: #f8f4ef;
    }

    /* LEFT PANEL — thumbnail bg + gradient overlay */
    .login-left {
        flex: 1.1;
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background-color: var(--theme-dark, #ea580c);
        min-height: 100vh;
        height: 100vh;
    }

    /* Background thumbnail, penuh panel */
    .ll-bg {
        position: absolute;
        inset: 0;
        background-image: var(--ll-thumb-url);
        background-size: cover;
        background-position: center top;
        z-index: 0;
    }

    /* Overlay warna tema — lebih transparan agar gambar lebih terlihat */
    .ll-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg,
                var(--theme-dark, #ea580c) 0%,
                var(--theme, #f97316) 55%,
                var(--theme-dark, #ea580c) 100%);
        opacity: 0.35;
        z-index: 1;
    }

    /* Bayangan gelap halus di bawah — fade panjang agar tidak terlihat blok */
    .ll-overlay::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to top,
            rgba(0, 0, 0, 0.60) 0%,
            rgba(0, 0, 0, 0.20) 40%,
            transparent 70%);
        opacity: 1;
    }

    /* Caption di bawah layar */
    .ll-caption {
        position: absolute;
        bottom: 56px;
        left: 0;
        right: 0;
        z-index: 3;
        text-align: center;
        padding: 0 36px;
    }

    .ll-caption h2 {
        font-size: clamp(1.4rem, 2.5vw, 2rem);
        font-weight: 800;
        color: #fff;
        line-height: 1.25;
        letter-spacing: -0.4px;
        margin-bottom: 10px;
        text-shadow:
            0 1px 3px rgba(0,0,0,0.95),
            0 3px 12px rgba(0,0,0,0.75);
    }

    .ll-caption h2 span {
        color: #fde68a;
        text-shadow:
            0 1px 3px rgba(0,0,0,0.95),
            0 3px 12px rgba(0,0,0,0.75);
    }

    .ll-caption p {
        font-size: 0.88rem;
        color: rgba(255, 255, 255, 0.95);
        line-height: 1.6;
        max-width: 320px;
        margin: 0 auto;
        text-shadow:
            0 1px 3px rgba(0,0,0,0.95),
            0 2px 8px rgba(0,0,0,0.65);
    }

    /* RIGHT PANEL — form */
    .login-right {
        width: 440px;
        min-width: 360px;
        background: #fff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 48px 44px;
        position: relative;
        box-shadow: -8px 0 40px rgba(0, 0, 0, 0.08);
        overflow-y: auto;
    }

    .login-right-inner {
        width: 100%;
        max-width: 360px;
    }

    /* Top branding mark */
    .login-brand-mark {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 32px;
    }

    .login-brand-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--theme, #f97316), var(--theme-dark, #ea580c));
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .login-brand-text {
        display: flex;
        flex-direction: column;
    }

    .login-brand-name {
        font-size: 1.25rem;
        font-weight: 800;
        color: #1f2937;
        letter-spacing: -0.5px;
        line-height: 1.1;
    }

    .login-brand-school {
        font-size: 0.75rem;
        color: #9ca3af;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
    }

    /* School logo override */
    .login-school-logo {
        width: 44px;
        height: 44px;
        object-fit: cover;
        border-radius: 12px;
        border: 2px solid var(--theme-light, #fed7aa);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    }

    /* Headings */
    .login-heading {
        font-size: 1.55rem;
        font-weight: 800;
        color: #111827;
        letter-spacing: -0.5px;
        margin-bottom: 4px;
    }

    .login-subheading {
        font-size: 0.85rem;
        color: #6b7280;
        margin-bottom: 28px;
        line-height: 1.5;
    }

    /* Input group */
    .lf-group {
        margin-bottom: 18px;
    }

    .lf-label {
        display: block;
        font-size: 0.82rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 7px;
    }

    .lf-input-wrap {
        position: relative;
    }

    .lf-input-icon {
        position: absolute;
        left: 13px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        pointer-events: none;
        display: flex;
    }

    .lf-input {
        width: 100%;
        padding: 11px 42px;
        border: 1.5px solid #e5e7eb;
        border-radius: 10px;
        font-size: 0.875rem;
        font-family: 'Poppins', sans-serif;
        color: #1f2937;
        background: #fafafa;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    }

    .lf-input:focus {
        border-color: var(--theme, #f97316);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.07);
    }

    .lf-input::placeholder {
        color: #c4c9d4;
    }

    .lf-eye-btn {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        color: #9ca3af;
        display: flex;
        align-items: center;
        padding: 2px;
        transition: color 0.15s;
    }

    .lf-eye-btn:hover {
        color: var(--theme, #f97316);
    }

    /* Error alert */
    .lf-alert {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 11px 14px;
        border-radius: 10px;
        font-size: 0.83rem;
        margin-bottom: 20px;
        line-height: 1.5;
    }

    .lf-alert-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
    }

    .lf-alert svg {
        flex-shrink: 0;
        margin-top: 1px;
    }

    /* Submit button — uses CSS theme vars */
    .lf-submit {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, var(--theme, #f97316) 0%, var(--theme-dark, #ea580c) 100%);
        color: #fff;
        border: none;
        border-radius: 30px;
        font-size: 0.93rem;
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        cursor: pointer;
        letter-spacing: 0.2px;
        transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 6px;
    }

    .lf-submit:hover:not(:disabled) {
        opacity: 0.92;
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
    }

    .lf-submit:active:not(:disabled) {
        transform: translateY(0);
    }

    .lf-submit:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    /* Footer note */
    .lf-footer {
        text-align: center;
        font-size: 0.75rem;
        color: #c4c9d4;
        margin-top: 28px;
        padding-top: 20px;
        border-top: 1px solid #f3f4f6;
    }

    /* Divider strip on top of right panel — follows theme */
    .login-right::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg,
                transparent 0%,
                var(--theme, #f97316) 30%,
                var(--theme-light, #fed7aa) 50%,
                var(--theme, #f97316) 70%,
                transparent 100%);
        opacity: 0.55;
    }

    /* ── Color picker (pojok kanan bawah panel kiri) ─── */
    .ll-theme-picker {
        position: absolute;
        bottom: 24px;
        right: 20px;
        z-index: 10;
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .ll-theme-btn {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        border: 3px solid transparent;
        cursor: pointer;
        transition: transform 0.18s, border-color 0.18s;
        padding: 0;
        outline: none;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
    }

    .ll-theme-btn:hover {
        transform: scale(1.2);
    }

    .ll-theme-btn.active {
        border-color: #fff;
        transform: scale(1.18);
        box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.5), 0 2px 8px rgba(0, 0, 0, 0.3);
    }

    .ll-theme-btn[data-theme="orange"] {
        background: #f97316;
    }

    .ll-theme-btn[data-theme="green"] {
        background: #95c134;
    }

    .ll-theme-btn[data-theme="purple"] {
        background: #9C27B0;
    }

    /* ── Responsive ──────────────────────────────────────── */
    @media (max-width: 900px) {
        .login-left {
            display: none;
        }

        .login-right {
            width: 100%;
            min-width: unset;
            padding: 40px 28px;
            box-shadow: none;
        }

        .login-split {
            background: #fff;
        }
    }

    @media (max-width: 480px) {
        .login-right {
            padding: 32px 20px;
        }
    }
    </style>
</head>

<body>

    <div class="login-split">

        <!-- ══ LEFT PANEL — Thumbnail + Gradient Overlay ════════════ -->
        <div class="login-left" style="--ll-thumb-url: url('<?= UPLOAD_URL ?>photos/thumbnail.png')">

            <!-- Background foto -->
            <div class="ll-bg"></div>

            <!-- Gradient overlay warna tema -->
            <div class="ll-overlay"></div>

            <!-- Caption teks (di atas overlay) -->
            <div class="ll-caption">
                <h2>Kelola Raport<br><span>Lebih Mudah</span> &amp; Efisien</h2>
                <p>Manajemen nilai, data siswa, dan cetak raport secara digital dari mana saja.</p>
            </div>

            <!-- Color picker -->
            <div class="ll-theme-picker" aria-label="Pilih tema warna">
                <button class="ll-theme-btn" data-theme="orange" aria-label="Tema Orange" title="Orange"></button>
                <button class="ll-theme-btn" data-theme="green" aria-label="Tema Hijau" title="Hijau"></button>
                <button class="ll-theme-btn" data-theme="purple" aria-label="Tema Ungu" title="Ungu"></button>
            </div>

        </div><!-- /login-left -->

        <!-- ══ RIGHT PANEL ═════════════════════════════════════════ -->
        <div class="login-right">
            <div class="login-right-inner">

                <!-- Brand mark -->
                <div class="login-brand-mark">
                    <?php if (!empty($sekolah_l['logo'])): ?>
                    <img src="<?= UPLOAD_URL . e($sekolah_l['logo']) ?>" alt="Logo <?= e($sekolah_l['nama_sekolah']) ?>"
                        class="login-school-logo" style="border-radius: 50%">
                    <?php else: ?>
                    <div class="login-brand-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5">
                            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                            <polyline points="14 2 14 8 20 8" />
                            <line x1="16" y1="13" x2="8" y2="13" />
                            <line x1="16" y1="17" x2="8" y2="17" />
                        </svg>
                    </div>
                    <?php endif; ?>

                    <div class="login-brand-text">
                        <span class="login-brand-name"><?= APP_NAME ?></span>
                        <?php if (!empty($sekolah_l['nama_sekolah']) && $sekolah_l['nama_sekolah'] !== 'NAMA SEKOLAH'): ?>
                        <span class="login-brand-school"><?= e($sekolah_l['nama_sekolah']) ?></span>
                        <?php else: ?>
                        <span class="login-brand-school">Sistem Manajemen Raport Digital</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Heading -->
                <h2 class="login-heading">Selamat Datang!</h2>
                <p class="login-subheading">Masuk ke akun Anda untuk melanjutkan.</p>

                <!-- Error alert -->
                <?php if ($error): ?>
                <div class="lf-alert lf-alert-error" role="alert">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <?= e($error) ?>
                </div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST" action="index.php?page=login" id="loginForm" autocomplete="on">
                    <?= csrf_field() ?>

                    <!-- Username -->
                    <div class="lf-group">
                        <label class="lf-label" for="username">Username</label>
                        <div class="lf-input-wrap">
                            <span class="lf-input-icon">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24">
                                    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2" />
                                    <circle cx="12" cy="7" r="4" />
                                </svg>
                            </span>
                            <input type="text" id="username" name="username" class="lf-input"
                                placeholder="Masukkan username Anda" autocomplete="username" required
                                <?= $locked ? 'disabled' : '' ?> value="<?= e($_POST['username'] ?? '') ?>"
                                style="border-radius: 30px">
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="lf-group">
                        <label class="lf-label" for="password">Password</label>
                        <div class="lf-input-wrap">
                            <span class="lf-input-icon">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                    <path d="M7 11V7a5 5 0 0110 0v4" />
                                </svg>
                            </span>
                            <input type="password" id="password" name="password" class="lf-input"
                                placeholder="Masukkan password Anda" autocomplete="current-password" required
                                <?= $locked ? 'disabled' : '' ?> style="border-radius: 30px">
                            <button type="button" id="togglePwd" class="lf-eye-btn" aria-label="Tampilkan password">
                                <svg id="eyeIconShow" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="2" viewBox="0 0 24 24">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                <svg id="eyeIconHide" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="2" viewBox="0 0 24 24" style="display:none">
                                    <path
                                        d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94" />
                                    <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19" />
                                    <line x1="1" y1="1" x2="23" y2="23" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="lf-submit" id="btnSubmit" <?= $locked ? 'disabled' : '' ?>>
                        <!-- <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.5"
                            viewBox="0 0 24 24">
                            <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4" />
                            <polyline points="10 17 15 12 10 7" />
                            <line x1="15" y1="12" x2="3" y2="12" />
                        </svg> -->
                        Masuk
                    </button>

                </form>

                <!-- Footer -->
                <div class="lf-footer">
                    <!--  &copy; <?= date('Y') ?> <?= APP_NAME ?> v<?= APP_VERSION ?> -->

                    &copy; <?= date('Y') ?> <?= APP_NAME ?>
                </div>

            </div><!-- /login-right-inner -->
        </div><!-- /login-right -->

    </div><!-- /login-split -->

    <script src="<?= BASE_URL ?>/script.js"></script>
    <script>
    (function() {
        var validThemes = ['orange', 'green', 'purple'];

        // ── Apply saved theme ──────────────────────────────────────
        var savedTheme = (typeof localStorage !== 'undefined' && localStorage.getItem('raportku_theme')) ||
        'orange';
        if (validThemes.indexOf(savedTheme) === -1) savedTheme = 'orange';
        document.documentElement.classList.add('theme-' + savedTheme);

        // ── Color picker ───────────────────────────────────────────
        function applyTheme(theme) {
            validThemes.forEach(function(t) {
                document.documentElement.classList.remove('theme-' + t);
            });
            document.documentElement.classList.add('theme-' + theme);
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem('raportku_theme', theme);
            }
            document.querySelectorAll('.ll-theme-btn').forEach(function(b) {
                b.classList.toggle('active', b.getAttribute('data-theme') === theme);
            });
        }

        // Mark active button on load
        document.querySelectorAll('.ll-theme-btn').forEach(function(btn) {
            if (btn.getAttribute('data-theme') === savedTheme) btn.classList.add('active');
            btn.addEventListener('click', function() {
                applyTheme(btn.getAttribute('data-theme'));
            });
        });

        // ── Toggle show/hide password ──────────────────────────────
        var pwd = document.getElementById('password');
        var btn = document.getElementById('togglePwd');
        var iconOn = document.getElementById('eyeIconShow');
        var iconOff = document.getElementById('eyeIconHide');

        if (btn) {
            btn.addEventListener('click', function() {
                var show = pwd.type === 'password';
                pwd.type = show ? 'text' : 'password';
                iconOn.style.display = show ? 'none' : '';
                iconOff.style.display = show ? '' : 'none';
                btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
            });
        }

        // ── Disable submit while submitting ───────────────────────
        var form = document.getElementById('loginForm');
        var submitBtn = document.getElementById('btnSubmit');
        if (form && submitBtn) {
            form.addEventListener('submit', function() {
                submitBtn.disabled = true;
                submitBtn.innerHTML =
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .7s linear infinite"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg> Memproses...';
            });
        }
    })();
    </script>
</body>

</html>