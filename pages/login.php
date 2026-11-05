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
$thumb_url  = UPLOAD_URL . 'photos/thumbnail.png';
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

    /* LEFT PANEL — thumbnail as full bg + orange gradient overlay */
    .login-left {
        flex: 1.1;
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background-image: var(--thumb-bg);
        background-size: cover;
        background-position: center bottom;
        background-repeat: no-repeat;
        background-color: #ea580c;
        min-height: 100vh;
        height: 100vh;
    }

    /* Orange gradient overlay — gelap di atas, transparan di bawah */
    .login-left::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg,
                rgba(194, 65, 12, 0.82) 0%,
                rgba(234, 88, 12, 0.68) 30%,
                rgba(249, 115, 22, 0.45) 60%,
                rgba(249, 115, 22, 0.10) 100%);
        pointer-events: none;
        z-index: 1;
    }

    /* Decorative dots pattern */
    .login-left-dots {
        position: absolute;
        inset: 0;
        z-index: 0;
        overflow: hidden;
        pointer-events: none;
    }

    .login-left-dots span {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
    }

    .login-left-content {
        position: relative;
        z-index: 3;
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 40px 32px;
        flex-shrink: 0;
        max-height: none;
        overflow: visible;
    }

    .login-left-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.18);
        border: 1px solid rgba(255, 255, 255, 0.4);
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        padding: 5px 14px;
        border-radius: 50px;
        margin-bottom: 20px;
        text-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
    }

    .login-left-title {
        font-size: clamp(1.6rem, 3vw, 2.4rem);
        font-weight: 800;
        color: #fff;
        text-align: center;
        line-height: 1.2;
        letter-spacing: -0.5px;
        margin-bottom: 10px;
    }

    .login-left-title span {
        color: #fde68a;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
    }

    .login-left-subtitle {
        font-size: 0.88rem;
        color: rgba(255, 255, 255, 0.88);
        text-align: center;
        max-width: 340px;
        line-height: 1.6;
        margin-bottom: 32px;
        text-shadow: 0 1px 6px rgba(0, 0, 0, 0.45);
    }

    /* Feature pills */
    .login-features {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: center;
        margin-bottom: 36px;
    }

    .login-feature-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: rgba(255, 255, 255, 0.95);
        font-size: 0.73rem;
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 50px;
        text-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
    }

    .login-feature-pill svg {
        opacity: 0.75;
    }

    /* Thumbnail wrap — hidden, gambar dipakai sebagai CSS bg via JS */
    .login-thumb-wrap {
        display: none;
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
        background: linear-gradient(135deg, #f97316, #c2410c);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(249, 115, 22, 0.35);
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
        border: 2px solid #fed7aa;
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
        border-color: #f97316;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.12);
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
        color: #f97316;
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

    /* Submit button */
    .lf-submit {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
        color: #fff;
        border: none;
        border-radius: 30px;
        font-size: 0.93rem;
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        cursor: pointer;
        letter-spacing: 0.2px;
        transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
        box-shadow: 0 4px 14px rgba(249, 115, 22, 0.35);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 6px;
    }

    .lf-submit:hover:not(:disabled) {
        opacity: 0.92;
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
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

    /* Divider strip on top of right panel — softer */
    .login-right::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent 0%, #f97316 30%, #fb923c 50%, #f97316 70%, transparent 100%);
        opacity: 0.55;
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

        <!-- ══ LEFT PANEL ══════════════════════════════════════════ -->
        <div class="login-left" style="--thumb-bg: url('<?= $thumb_url ?>')">

            <!-- Decorative blobs -->
            <div class="login-left-dots" aria-hidden="true">
                <span style="width:260px;height:260px;top:-80px;left:-80px;"></span>
                <span style="width:160px;height:160px;bottom:200px;right:-40px;"></span>
                <span style="width:80px;height:80px;top:40%;left:10%;"></span>
            </div>

            <!-- Branding content -->
            <div class="login-left-content">

                <div class="login-left-badge">
                    <svg width="10" height="10" viewBox="0 0 10 10" fill="currentColor">
                        <circle cx="5" cy="5" r="5" />
                    </svg>
                    Sistem Raport Digital
                </div>

                <h1 class="login-left-title">
                    Kelola Raport<br><span>Lebih Mudah</span> &amp; Efisien
                </h1>

                <p class="login-left-subtitle">
                    Manajemen nilai, data siswa, dan cetak raport secara digital.
                </p>

                <div class="login-features">
                    <span class="login-feature-pill">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"
                            viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        Input Nilai Mudah
                    </span>
                    <span class="login-feature-pill">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"
                            viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        Cetak Raport PDF
                    </span>
                    <span class="login-feature-pill">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"
                            viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        Multi Pengguna
                    </span>
                    <span class="login-feature-pill">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"
                            viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        Data Aman &amp; Terenkripsi
                    </span>
                </div>

            </div><!-- /login-left-content -->

            <!-- Thumbnail — bottom-flush -->
            <div class="login-thumb-wrap">
                <img src="<?= $thumb_url ?>" alt="Ilustrasi Raport Digital">
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
                    &copy; <?= date('Y') ?> <?= APP_NAME ?> v<?= APP_VERSION ?>
                </div>

            </div><!-- /login-right-inner -->
        </div><!-- /login-right -->

    </div><!-- /login-split -->

    <script src="<?= BASE_URL ?>/script.js"></script>
    <script>
    (function() {
        // Toggle show/hide password
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

        // Disable submit button while submitting to prevent double-click
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