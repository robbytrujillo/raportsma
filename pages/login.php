<?php
/**
 * pages/login.php
 * Halaman login
 */
require_once __DIR__ . '/../auth.php';

// Jika sudah login, redirect ke dashboard
if (is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$error = '';

// Proses form login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $error = $result['message'];
        }
    }
}
?>
<?php $sekolah_l = sekolah_info(); ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &mdash; <?= APP_NAME ?></title>
    <!-- Favicon -->
    <?php if (!empty($sekolah_l['logo'])): ?>
    <link rel="icon" type="image/png" href="<?= UPLOAD_URL . e($sekolah_l['logo']) ?>">
    <link rel="shortcut icon" href="<?= UPLOAD_URL . e($sekolah_l['logo']) ?>">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='5' fill='%23f97316'/><path d='M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z' fill='none' stroke='white' stroke-width='2'/></svg>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/style.css">
</head>

<body>
    <div class="login-bg">
        <div class="login-card">

            <!-- Logo Sekolah / App -->
            <div class="text-center mb-8">
                <div class="flex items-center justify-center gap-3 mb-3">
                    <?php if (!empty($sekolah_l['logo'])): ?>
                    <!-- Logo sekolah dari pengaturan &mdash; circle -->
                    <img src="<?= UPLOAD_URL . e($sekolah_l['logo']) ?>" alt="Logo <?= e($sekolah_l['nama_sekolah']) ?>"
                        style="width:56px;height:56px;object-fit:cover;border-radius:50%;
                                box-shadow:0 2px 8px rgba(0,0,0,0.15);border:2px solid #fed7aa">
                    <?php else: ?>
                    <!-- Fallback: ikon orange &mdash; circle -->
                    <div style="width:52px;height:52px;background:linear-gradient(135deg,#f97316,#c2410c);
                                border-radius:50%;display:flex;align-items:center;justify-content:center;
                                flex-shrink:0;box-shadow:0 2px 8px rgba(249,115,22,0.35)">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5">
                            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                            <polyline points="14 2 14 8 20 8" />
                            <line x1="16" y1="13" x2="8" y2="13" />
                            <line x1="16" y1="17" x2="8" y2="17" />
                        </svg>
                    </div>
                    <?php endif; ?>
                    <span
                        style="font-size:2rem;font-weight:800;color:#1f2937;letter-spacing:-1px"><?= APP_NAME ?></span>
                </div>
                <!-- Nama sekolah jika sudah diset -->
                <?php if (!empty($sekolah_l['nama_sekolah']) && $sekolah_l['nama_sekolah'] !== 'NAMA SEKOLAH'): ?>
                <p style="color:#374151;font-size:.9rem;font-weight:600;margin-bottom:2px">
                    <?= e($sekolah_l['nama_sekolah']) ?>
                </p>
                <?php endif; ?>
                <p style="color:#6b7280;font-size:.8rem">Sistem Manajemen Raport Digital</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger mb-5">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                    style="flex-shrink:0">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
                <?= e($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="index.php?page=login" id="loginForm">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <div style="position:relative">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                        </span>
                        <input type="text" id="username" name="username" class="form-control" style="padding-left:38px"
                            placeholder="Masukkan username" required value="<?= e($_POST['username'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div style="position:relative">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                <path d="M7 11V7a5 5 0 0110 0v4" />
                            </svg>
                        </span>
                        <input type="password" id="password" name="password" class="form-control"
                            style="padding-left:38px;padding-right:40px" placeholder="Masukkan password" required>
                        <button type="button" id="togglePwd"
                            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af">
                            <svg id="eyeIcon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full mt-2"
                    style="width:100%;justify-content:center;padding:11px;border-radius:30px">
                    <!-- <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4" />
                        <polyline points="10 17 15 12 10 7" />
                        <line x1="15" y1="12" x2="3" y2="12" />
                    </svg> -->
                    Login
                </button>
            </form>

            <p class="text-center text-xs text-gray-400 mt-6">
                &copy; <?= date('Y') ?> <?= APP_NAME ?> v<?= APP_VERSION ?>
            </p>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/script.js"></script>
    <script>
    // Toggle show/hide password
    document.getElementById('togglePwd').addEventListener('click', function() {
        const pwd = document.getElementById('password');
        pwd.type = pwd.type === 'password' ? 'text' : 'password';
    });
    </script>
</body>

</html>