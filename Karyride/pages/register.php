<?php
declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| KaryRide V1 — Client Registration
|--------------------------------------------------------------------------
| Account type: CLIENT ONLY
| Languages   : English / Kirundi
| Theme       : Light / Dark
| CSS / JS    : Embedded
|--------------------------------------------------------------------------
*/

// ============================================================
// LANGUAGE
// ============================================================

$supportedLanguages = ['en', 'rn'];

if (
    isset($_GET['lang']) &&
    in_array($_GET['lang'], $supportedLanguages, true)
) {
    $_SESSION['lang'] = $_GET['lang'];
}

$lang = $_SESSION['lang'] ?? 'en';

$translationFile = __DIR__ . '/../translations/' . $lang . '.php';

if (!is_file($translationFile)) {
    $lang = 'en';
    $translationFile = __DIR__ . '/../translations/en.php';
}

$translations = require $translationFile;

if (!is_array($translations)) {
    $translations = [];
}

function t(string $key, string $fallback = ''): string
{
    global $translations;
    return htmlspecialchars(
        (string)($translations[$key] ?? $fallback),
        ENT_QUOTES,
        'UTF-8'
    );
}

// ============================================================
// REDIRECT IF ALREADY LOGGED IN
// ============================================================

if (isset($_SESSION['user_id'])) {
    $role = (string) $_SESSION['role'];
    $dashboardMap = [
        'superadmin' => 'admin.php',
        'company_owner' => 'company.php',
        'company_manager' => 'company.php',
        'company_employee' => 'company.php',
        'driver' => 'driver.php',
        'client' => 'client.php'
    ];
    $destination = $dashboardMap[$role] ?? 'client.php';
    header('Location: ' . $destination);
    exit;
}

// ============================================================
// DATABASE
// ============================================================

$db = null;
$dbError = '';

$databaseFile = __DIR__ . '/../config/database.php';

if (is_file($databaseFile)) {
    try {
        $databaseResult = require $databaseFile;

        if ($databaseResult instanceof mysqli) {
            $db = $databaseResult;
        } elseif ($databaseResult instanceof PDO) {
            $db = $databaseResult;
        }
    } catch (Throwable $e) {
        $dbError = 'Database connection failed.';
        error_log(
            '[' . date('Y-m-d H:i:s') . '] Register DB error: ' .
            $e->getMessage() .
            PHP_EOL,
            3,
            __DIR__ . '/../logs/errors.log'
        );
    }
}

// ============================================================
// FORM STATE
// ============================================================

$errors = [];
$success = '';

$old = [
    'first_name' => '',
    'last_name'  => '',
    'phone'      => '',
    'email'      => '',
];

// ============================================================
// FORM SUBMISSION — CLIENT ONLY
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $old['last_name']  = trim((string)($_POST['last_name'] ?? ''));
    $old['phone']      = trim((string)($_POST['phone'] ?? ''));
    $old['email']      = trim((string)($_POST['email'] ?? ''));

    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $terms = isset($_POST['terms']);

    // --------------------------------------------------------
    // VALIDATION
    // --------------------------------------------------------

    if (
        $old['first_name'] === '' ||
        $old['last_name'] === '' ||
        $old['phone'] === '' ||
        $old['email'] === '' ||
        $password === '' ||
        $confirmPassword === ''
    ) {
        $errors[] = t('register_required_fields', 'Please fill in all required fields.');
    }

    if (
        $old['first_name'] !== '' &&
        !preg_match("/^[\p{L}\s'-]{2,60}$/u", $old['first_name'])
    ) {
        $errors[] = t('register_invalid_first_name', 'Please enter a valid first name.');
    }

    if (
        $old['last_name'] !== '' &&
        !preg_match("/^[\p{L}\s'-]{2,60}$/u", $old['last_name'])
    ) {
        $errors[] = t('register_invalid_last_name', 'Please enter a valid last name.');
    }

    if (
        $old['phone'] !== '' &&
        !preg_match('/^\+?[0-9\s\-()]{8,20}$/', $old['phone'])
    ) {
        $errors[] = t('register_invalid_phone', 'Please enter a valid phone number.');
    }

    if (
        $old['email'] !== '' &&
        !filter_var($old['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = t('register_invalid_email', 'Please enter a valid email address.');
    }

    if ($password !== '' && strlen($password) < 8) {
        $errors[] = t('register_password_short', 'Password must be at least 8 characters.');
    }

    if (
        $password !== '' &&
        $confirmPassword !== '' &&
        !hash_equals($password, $confirmPassword)
    ) {
        $errors[] = t('register_password_mismatch', 'Passwords do not match.');
    }

    if (!$terms) {
        $errors[] = t('register_terms_required', 'You must agree to the terms and conditions.');
    }

    // --------------------------------------------------------
    // DATABASE INSERT — CLIENT ONLY
    // --------------------------------------------------------

    if (!$errors) {

        try {

            if (!$db) {
                $errors[] = t('register_database_error', 'Unable to connect to the database. Please try again later.');
            } else {

                // Check for existing user (email OR phone)
                if ($db instanceof mysqli) {

                    $checkStmt = $db->prepare("
                        SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1
                    ");
                    $checkStmt->bind_param('ss', $old['email'], $old['phone']);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $existing = $checkResult->fetch_assoc();
                    $checkStmt->close();

                } else {

                    $checkStmt = $db->prepare("
                        SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1
                    ");
                    $checkStmt->execute([
                        ':email' => $old['email'],
                        ':phone' => $old['phone']
                    ]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($existing) {
                    $errors[] = t('register_email_exists', 'A user with this email or phone already exists.');
                } else {

                    // Hash password
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'client';
                    $preferredLanguage = $lang;

                    // Insert client user
                    if ($db instanceof mysqli) {

                        $insertStmt = $db->prepare("
                            INSERT INTO users (
                                role,
                                first_name,
                                last_name,
                                email,
                                phone,
                                password_hash,
                                preferred_language,
                                is_active
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                        ");

                        $insertStmt->bind_param(
                            'sssssss',
                            $role,
                            $old['first_name'],
                            $old['last_name'],
                            $old['email'],
                            $old['phone'],
                            $passwordHash,
                            $preferredLanguage
                        );

                        $insertStmt->execute();
                        $userId = $insertStmt->insert_id;
                        $insertStmt->close();

                    } else {

                        $insertStmt = $db->prepare("
                            INSERT INTO users (
                                role,
                                first_name,
                                last_name,
                                email,
                                phone,
                                password_hash,
                                preferred_language,
                                is_active
                            ) VALUES (
                                :role,
                                :first_name,
                                :last_name,
                                :email,
                                :phone,
                                :password_hash,
                                :preferred_language,
                                1
                            )
                        ");

                        $insertStmt->execute([
                            ':role' => $role,
                            ':first_name' => $old['first_name'],
                            ':last_name' => $old['last_name'],
                            ':email' => $old['email'],
                            ':phone' => $old['phone'],
                            ':password_hash' => $passwordHash,
                            ':preferred_language' => $preferredLanguage
                        ]);

                        $userId = $db->lastInsertId();
                    }

                    if ($userId) {

                        // Auto-login
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = (int) $userId;
                        $_SESSION['first_name'] = $old['first_name'];
                        $_SESSION['last_name'] = $old['last_name'];
                        $_SESSION['email'] = $old['email'];
                        $_SESSION['phone'] = $old['phone'];
                        $_SESSION['role'] = 'client';
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        $_SESSION['lang'] = $preferredLanguage;

                        $success = t('register_success', 'Your account has been created successfully.');

                        // Redirect to client dashboard
                        header('Location: client.php');
                        exit;

                    } else {
                        $errors[] = t('register_insert_error', 'Failed to create account. Please try again.');
                    }
                }
            }

        } catch (Throwable $e) {

            $errors[] = t('register_general_error', 'Something went wrong. Please try again later.');

            error_log(
                '[' . date('Y-m-d H:i:s') . '] Register error: ' .
                $e->getMessage() .
                PHP_EOL,
                3,
                __DIR__ . '/../logs/errors.log'
            );
        }
    }
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= t('register_subtitle', 'Create your KaryRide client account.') ?>">
    <meta name="theme-color" content="#f7f9fc" id="themeColorMeta">
    <title><?= t('register_title', 'Create your account') ?> — KaryRide</title>

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>

        /* =====================================================
           DESIGN TOKENS
        ===================================================== */

        :root {
            --bg: #f7f9fc;
            --surface: #ffffff;
            --surface-soft: #f0f4f8;
            --surface-glass: rgba(255,255,255,.78);
            --text: #101827;
            --text-soft: #667085;
            --border: #e3e8ef;
            --primary: #101827;
            --primary-text: #ffffff;
            --accent: #2563eb;
            --accent-soft: rgba(37,99,235,.10);
            --danger: #dc2626;
            --danger-soft: rgba(220,38,38,.09);
            --success: #16a34a;
            --success-soft: rgba(22,163,74,.10);
            --shadow-sm: 0 8px 25px rgba(15,23,42,.06);
            --shadow-lg: 0 30px 80px rgba(15,23,42,.14);
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
        }

        body.dark {
            --bg: #050a11;
            --surface: #0c1420;
            --surface-soft: #111c2a;
            --surface-glass: rgba(12,20,32,.82);
            --text: #f5f7fa;
            --text-soft: #9aa8b9;
            --border: #1c2a3a;
            --primary: #f5f7fa;
            --primary-text: #07111c;
            --accent: #60a5fa;
            --accent-soft: rgba(96,165,250,.10);
            --danger: #f87171;
            --danger-soft: rgba(248,113,113,.10);
            --success: #4ade80;
            --success-soft: rgba(74,222,128,.10);
            --shadow-sm: 0 8px 30px rgba(0,0,0,.20);
            --shadow-lg: 0 30px 90px rgba(0,0,0,.45);
        }

        /* =====================================================
           RESET
        ===================================================== */

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
        }

        body {
            min-height: 100vh;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            overflow-x: hidden;
            transition: background .25s ease, color .25s ease;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        /* =====================================================
           BACKGROUND
        ===================================================== */

        .background {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }

        #backgroundCanvas {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(75px);
            opacity: .45;
            animation: floatOrb 15s ease-in-out infinite alternate;
        }

        .orb.one {
            width: 420px;
            height: 420px;
            top: -170px;
            right: -100px;
            background: radial-gradient(circle, rgba(37,99,235,.25), transparent 70%);
        }

        .orb.two {
            width: 360px;
            height: 360px;
            bottom: -150px;
            left: -130px;
            background: radial-gradient(circle, rgba(37,99,235,.18), transparent 70%);
            animation-delay: -6s;
        }

        @keyframes floatOrb {
            from {
                transform: translate3d(0,0,0) scale(1);
            }
            to {
                transform: translate3d(35px,-30px,0) scale(1.1);
            }
        }

        /* =====================================================
           HEADER
        ===================================================== */

        .header {
            position: relative;
            z-index: 10;
            width: 100%;
            border-bottom: 1px solid var(--border);
            background: var(--surface-glass);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .nav {
            width: min(1180px, calc(100% - 40px));
            height: 72px;
            margin: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
            font-weight: 850;
            letter-spacing: -.6px;
        }

        .logo-mark {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--primary);
            color: var(--primary-text);
            box-shadow: var(--shadow-sm);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .icon-btn {
            width: 39px;
            height: 39px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text);
            cursor: pointer;
        }

        .icon-btn:hover {
            background: var(--surface-soft);
        }

        /* =====================================================
           LANGUAGE
        ===================================================== */

        .language-wrapper {
            position: relative;
        }

        .language-menu {
            display: none;
            position: absolute;
            top: 48px;
            right: 0;
            width: 145px;
            padding: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow-lg);
        }

        .language-menu.open {
            display: block;
        }

        .language-menu a {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px;
            border-radius: 9px;
            color: var(--text);
            font-size: 13px;
            font-weight: 650;
        }

        .language-menu a:hover {
            background: var(--surface-soft);
        }

        /* =====================================================
           MAIN
        ===================================================== */

        .page {
            position: relative;
            z-index: 2;
            min-height: calc(100vh - 72px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 55px 20px;
        }

        .register-layout {
            width: min(1050px, 100%);
            display: grid;
            grid-template-columns: .85fr 1.15fr;
            gap: 45px;
            align-items: center;
        }

        /* =====================================================
           LEFT SIDE
        ===================================================== */

        .intro {
            padding: 20px;
        }

        .intro-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            border-radius: 100px;
            background: var(--surface-glass);
            color: var(--text-soft);
            font-size: 11px;
            font-weight: 750;
        }

        .intro-badge i {
            color: var(--accent);
        }

        .intro h1 {
            font-size: clamp(38px, 5vw, 62px);
            line-height: 1;
            letter-spacing: -3px;
            margin-bottom: 20px;
        }

        .intro h1 span {
            color: var(--accent);
        }

        .intro p {
            max-width: 480px;
            color: var(--text-soft);
            font-size: 15px;
            line-height: 1.8;
        }

        .benefits {
            margin-top: 30px;
            display: grid;
            gap: 12px;
        }

        .benefit {
            display: flex;
            align-items: center;
            gap: 11px;
            color: var(--text-soft);
            font-size: 13px;
            font-weight: 600;
        }

        .benefit i {
            width: 31px;
            height: 31px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: var(--accent-soft);
            color: var(--accent);
            flex-shrink: 0;
        }

        /* =====================================================
           REGISTER CARD
        ===================================================== */

        .register-card {
            width: 100%;
            padding: 34px;
            background: var(--surface-glass);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .card-heading {
            margin-bottom: 25px;
        }

        .card-heading h2 {
            font-size: 27px;
            letter-spacing: -1px;
            margin-bottom: 7px;
        }

        .card-heading p {
            color: var(--text-soft);
            font-size: 13px;
        }

        /* =====================================================
           ALERTS
        ===================================================== */

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 13px;
            margin-bottom: 16px;
            border-radius: 11px;
            font-size: 12px;
            line-height: 1.55;
        }

        .alert.error {
            background: var(--danger-soft);
            color: var(--danger);
            border: 1px solid rgba(220,38,38,.15);
        }

        .alert.success {
            background: var(--success-soft);
            color: var(--success);
            border: 1px solid rgba(22,163,74,.15);
        }

        .error-list {
            display: grid;
            gap: 4px;
            padding-left: 16px;
        }

        /* =====================================================
           FORM
        ===================================================== */

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 7px;
            font-size: 12px;
            font-weight: 750;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-soft);
            font-size: 15px;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            min-height: 48px;
            padding: 0 42px 0 42px;
            border: 1px solid var(--border);
            border-radius: 11px;
            outline: none;
            background: var(--surface);
            color: var(--text);
            font-size: 13px;
            transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
        }

        .form-input::placeholder {
            color: var(--text-soft);
            opacity: .75;
        }

        .form-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 30px;
            height: 30px;
            border: 0;
            background: transparent;
            color: var(--text-soft);
            cursor: pointer;
            border-radius: 7px;
        }

        .password-toggle:hover {
            background: var(--surface-soft);
            color: var(--text);
        }

        /* =====================================================
           PASSWORD STRENGTH
        ===================================================== */

        .password-strength {
            margin-top: 8px;
        }

        .strength-track {
            width: 100%;
            height: 4px;
            overflow: hidden;
            border-radius: 10px;
            background: var(--surface-soft);
        }

        .strength-bar {
            width: 0;
            height: 100%;
            border-radius: inherit;
            transition: width .25s ease;
        }

        .strength-label {
            margin-top: 5px;
            font-size: 10px;
            color: var(--text-soft);
        }

        /* =====================================================
           TERMS
        ===================================================== */

        .terms {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin: 4px 0 20px;
            color: var(--text-soft);
            font-size: 11px;
            line-height: 1.5;
            cursor: pointer;
        }

        .terms input {
            margin-top: 2px;
            accent-color: var(--accent);
        }

        /* =====================================================
           BUTTON
        ===================================================== */

        .submit-btn {
            width: 100%;
            min-height: 49px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border: 0;
            border-radius: 11px;
            background: var(--primary);
            color: var(--primary-text);
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: transform .2s ease, opacity .2s ease;
        }

        .submit-btn:hover {
            opacity: .88;
            transform: translateY(-1px);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* =====================================================
           FOOTER LINK
        ===================================================== */

        .account-link {
            text-align: center;
            margin-top: 20px;
            color: var(--text-soft);
            font-size: 12px;
        }

        .account-link a {
            color: var(--accent);
            font-weight: 750;
        }

        .account-link a:hover {
            text-decoration: underline;
        }

        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 18px;
            color: var(--text-soft);
            font-size: 11px;
            font-weight: 650;
        }

        .back-home:hover {
            color: var(--text);
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 850px) {

            .register-layout {
                grid-template-columns: 1fr;
                max-width: 570px;
                gap: 25px;
            }

            .intro {
                text-align: center;
                padding: 0;
            }

            .intro p {
                margin: auto;
            }

            .benefits {
                display: none;
            }

            .intro h1 {
                font-size: 42px;
            }
        }

        @media (max-width: 560px) {

            .nav {
                width: calc(100% - 28px);
                height: 65px;
            }

            .page {
                min-height: calc(100vh - 65px);
                padding: 35px 14px;
            }

            .register-card {
                padding: 23px 18px;
                border-radius: 19px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

            .intro h1 {
                font-size: 37px;
                letter-spacing: -2px;
            }

            .intro p {
                font-size: 13px;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }

    </style>

</head>

<body>

    <!-- ========================================================
         ANIMATED BACKGROUND
    ========================================================= -->

    <div class="background" aria-hidden="true">

        <canvas id="backgroundCanvas"></canvas>

        <div class="orb one"></div>
        <div class="orb two"></div>

    </div>


    <!-- ========================================================
         HEADER
    ========================================================= -->

    <header class="header">

        <nav class="nav">

            <a href="../index.php" class="logo">
                <span class="logo-mark">
                    <i class="bi bi-sign-turn-right-fill"></i>
                </span>
                <span>KaryRide</span>
            </a>


            <div class="nav-actions">

                <!-- LANGUAGE -->

                <div class="language-wrapper">

                    <button
                        type="button"
                        class="icon-btn"
                        id="languageButton"
                        aria-label="<?= t('language', 'Language') ?>"
                        aria-expanded="false"
                    >
                        <i class="bi bi-translate"></i>
                    </button>


                    <div class="language-menu" id="languageMenu">

                        <a href="?lang=en">
                            <i class="bi bi-translate"></i>
                            <?= t('english', 'English') ?>
                        </a>

                        <a href="?lang=rn">
                            <i class="bi bi-translate"></i>
                            <?= t('kirundi', 'Kirundi') ?>
                        </a>

                    </div>

                </div>


                <!-- THEME -->

                <button
                    type="button"
                    class="icon-btn"
                    id="themeButton"
                    aria-label="<?= t('dark_mode', 'Dark mode') ?>"
                    aria-pressed="false"
                >
                    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
                </button>

            </div>

        </nav>

    </header>


    <!-- ========================================================
         PAGE
    ========================================================= -->

    <main class="page">

        <div class="register-layout">


            <!-- ==================================================
                 INTRO
            ================================================== -->

            <section class="intro">

                <div class="intro-badge">

                    <i class="bi bi-person-plus-fill"></i>

                    <?= t('client_account_only', 'Public signup is for clients only.') ?>

                </div>


                <h1>

                    <?= t('register_title', 'Create your account') ?>

                    <br>

                    <span>KaryRide.</span>

                </h1>


                <p>

                    <?= t('register_subtitle', 'Join KaryRide and make your everyday journeys easier.') ?>

                </p>


                <div class="benefits">

                    <div class="benefit">

                        <i class="bi bi-lightning-charge-fill"></i>

                        <span>
                            <?= t('register_benefit_booking', 'Book rides quickly and easily.') ?>
                        </span>

                    </div>


                    <div class="benefit">

                        <i class="bi bi-geo-alt-fill"></i>

                        <span>
                            <?= t('register_benefit_tracking', 'Track your ride and driver.') ?>
                        </span>

                    </div>


                    <div class="benefit">

                        <i class="bi bi-shield-check"></i>

                        <span>
                            <?= t('register_benefit_security', 'Your account information stays protected.') ?>
                        </span>

                    </div>

                </div>

            </section>


            <!-- ==================================================
                 REGISTER FORM
            ================================================== -->

            <section class="register-card">

                <div class="card-heading">

                    <h2>
                        <?= t('register_title', 'Create your account') ?>
                    </h2>

                    <p>
                        <?= t('register_subtitle', 'Join KaryRide and make your everyday journeys easier.') ?>
                    </p>

                </div>


                <!-- ERRORS -->

                <?php if ($errors): ?>

                    <div class="alert error">

                        <i class="bi bi-exclamation-circle-fill"></i>

                        <ul class="error-list">

                            <?php foreach ($errors as $error): ?>

                                <li>
                                    <?= $error ?>
                                </li>

                            <?php endforeach; ?>

                        </ul>

                    </div>

                <?php endif; ?>


                <!-- SUCCESS -->

                <?php if ($success): ?>

                    <div class="alert success">

                        <i class="bi bi-check-circle-fill"></i>

                        <span>
                            <?= $success ?>
                        </span>

                    </div>

                <?php endif; ?>


                <form
                    method="POST"
                    action=""
                    id="registerForm"
                    autocomplete="on"
                    novalidate
                >

                    <div class="form-grid">


                        <!-- FIRST NAME -->

                        <div class="form-group">

                            <label class="form-label" for="first_name">
                                <?= t('register_first_name_label', 'First name') ?>
                            </label>

                            <div class="input-wrap">

                                <i class="bi bi-person input-icon"></i>

                                <input
                                    type="text"
                                    id="first_name"
                                    name="first_name"
                                    class="form-input"
                                    placeholder="<?= t('register_first_name_placeholder', 'Enter your first name') ?>"
                                    value="<?= htmlspecialchars($old['first_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    maxlength="60"
                                    autocomplete="given-name"
                                    required
                                >

                            </div>

                        </div>


                        <!-- LAST NAME -->

                        <div class="form-group">

                            <label class="form-label" for="last_name">
                                <?= t('register_last_name_label', 'Last name') ?>
                            </label>

                            <div class="input-wrap">

                                <i class="bi bi-person input-icon"></i>

                                <input
                                    type="text"
                                    id="last_name"
                                    name="last_name"
                                    class="form-input"
                                    placeholder="<?= t('register_last_name_placeholder', 'Enter your last name') ?>"
                                    value="<?= htmlspecialchars($old['last_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    maxlength="60"
                                    autocomplete="family-name"
                                    required
                                >

                            </div>

                        </div>


                        <!-- PHONE -->

                        <div class="form-group">

                            <label class="form-label" for="phone">
                                <?= t('register_phone_label', 'Phone number') ?>
                            </label>

                            <div class="input-wrap">

                                <i class="bi bi-telephone input-icon"></i>

                                <input
                                    type="tel"
                                    id="phone"
                                    name="phone"
                                    class="form-input"
                                    placeholder="<?= t('register_phone_placeholder', 'Enter your phone number') ?>"
                                    value="<?= htmlspecialchars($old['phone'], ENT_QUOTES, 'UTF-8') ?>"
                                    maxlength="20"
                                    autocomplete="tel"
                                    required
                                >

                            </div>

                        </div>


                        <!-- EMAIL -->

                        <div class="form-group">

                            <label class="form-label" for="email">
                                <?= t('register_email_label', 'Email address') ?>
                            </label>

                            <div class="input-wrap">

                                <i class="bi bi-envelope input-icon"></i>

                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    class="form-input"
                                    placeholder="<?= t('register_email_placeholder', 'Enter your email address') ?>"
                                    value="<?= htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8') ?>"
                                    maxlength="150"
                                    autocomplete="email"
                                    required
                                >

                            </div>

                        </div>


                        <!-- PASSWORD -->

                        <div class="form-group">

                            <label class="form-label" for="password">
                                <?= t('register_password_label', 'Password') ?>
                            </label>

                            <div class="input-wrap">

                                <i class="bi bi-lock input-icon"></i>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="form-input"
                                    placeholder="<?= t('register_password_placeholder', 'Create a password') ?>"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-target="password"
                                    aria-label="<?= t('show_password', 'Show password') ?>"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>


                            <div class="password-strength">

                                <div class="strength-track">

                                    <div class="strength-bar" id="strengthBar"></div>

                                </div>

                                <div class="strength-label" id="strengthLabel">
                                    <?= t('register_password_strength', 'Password strength') ?>
                                </div>

                            </div>

                        </div>


                        <!-- CONFIRM PASSWORD -->

                        <div class="form-group">

                            <label class="form-label" for="confirm_password">
                                <?= t('register_confirm_password_label', 'Confirm password') ?>
                            </label>

                            <div class="input-wrap">

                                <i class="bi bi-lock-fill input-icon"></i>

                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    class="form-input"
                                    placeholder="<?= t('register_confirm_password_placeholder', 'Confirm your password') ?>"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-target="confirm_password"
                                    aria-label="<?= t('show_password', 'Show password') ?>"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>

                    </div>


                    <!-- TERMS -->

                    <label class="terms">

                        <input type="checkbox" name="terms" value="1" required>

                        <span>
                            <?= t('register_terms', 'I agree to the KaryRide terms and conditions.') ?>
                        </span>

                    </label>


                    <!-- SUBMIT -->

                    <button type="submit" class="submit-btn">

                        <i class="bi bi-person-plus-fill"></i>

                        <?= t('register_button', 'Create account') ?>

                    </button>

                </form>


                <!-- LOGIN -->

                <div class="account-link">

                    <?= t('register_have_account', 'Already have an account?') ?>

                    <a href="login.php">
                        <?= t('register_login', 'Sign in') ?>
                    </a>

                </div>


                <!-- HOME -->

                <div style="text-align:center">

                    <a href="../index.php" class="back-home">

                        <i class="bi bi-arrow-left"></i>

                        <?= t('register_back_home', 'Back to home') ?>

                    </a>

                </div>

            </section>

        </div>

    </main>


    <script>

        /* =====================================================
           THEME
        ===================================================== */

        const themeButton = document.getElementById('themeButton');
        const themeIcon = document.getElementById('themeIcon');
        const themeMeta = document.getElementById('themeColorMeta');

        function applyTheme(theme) {

            const dark = theme === 'dark';

            document.body.classList.toggle('dark', dark);

            themeIcon.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';

            themeButton.setAttribute('aria-pressed', String(dark));

            themeButton.setAttribute('aria-label',
                dark ? '<?= t('light_mode', 'Light mode') ?>' : '<?= t('dark_mode', 'Dark mode') ?>'
            );

            if (themeMeta) {
                themeMeta.setAttribute('content', dark ? '#050a11' : '#f7f9fc');
            }
        }

        const savedTheme = localStorage.getItem('karyride_theme');
        const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

        applyTheme(savedTheme || (systemDark ? 'dark' : 'light'));

        themeButton.addEventListener('click', function() {

            const nextTheme = document.body.classList.contains('dark') ? 'light' : 'dark';

            localStorage.setItem('karyride_theme', nextTheme);

            applyTheme(nextTheme);

        });


        /* =====================================================
           LANGUAGE MENU
        ===================================================== */

        const languageButton = document.getElementById('languageButton');
        const languageMenu = document.getElementById('languageMenu');

        languageButton.addEventListener('click', function(event) {

            event.stopPropagation();

            const open = languageMenu.classList.toggle('open');

            languageButton.setAttribute('aria-expanded', String(open));

        });

        document.addEventListener('click', function() {

            languageMenu.classList.remove('open');

            languageButton.setAttribute('aria-expanded', 'false');

        });


        /* =====================================================
           PASSWORD SHOW / HIDE
        ===================================================== */

        document.querySelectorAll('.password-toggle').forEach(function(button) {

            button.addEventListener('click', function() {

                const target = document.getElementById(this.dataset.target);

                const icon = this.querySelector('i');

                if (!target) return;

                if (target.type === 'password') {

                    target.type = 'text';

                    icon.className = 'bi bi-eye-slash';

                    this.setAttribute('aria-label', '<?= t('hide_password', 'Hide password') ?>');

                } else {

                    target.type = 'password';

                    icon.className = 'bi bi-eye';

                    this.setAttribute('aria-label', '<?= t('show_password', 'Show password') ?>');

                }

            });

        });


        /* =====================================================
           PASSWORD STRENGTH
        ===================================================== */

        const password = document.getElementById('password');
        const strengthBar = document.getElementById('strengthBar');
        const strengthLabel = document.getElementById('strengthLabel');

        password.addEventListener('input', function() {

            const value = this.value;

            let score = 0;

            if (value.length >= 8) score++;
            if (/[a-z]/.test(value)) score++;
            if (/[A-Z]/.test(value)) score++;
            if (/[0-9]/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;

            const widths = ['0%', '20%', '40%', '60%', '80%', '100%'];

            strengthBar.style.width = widths[score];

            if (score <= 1) {
                strengthLabel.textContent = '<?= t('register_password_weak', 'Weak') ?>';
            } else if (score <= 3) {
                strengthLabel.textContent = '<?= t('register_password_medium', 'Medium') ?>';
            } else {
                strengthLabel.textContent = '<?= t('register_password_strong', 'Strong') ?>';
            }

        });


        /* =====================================================
           PASSWORD MATCH
        ===================================================== */

        const confirmPassword = document.getElementById('confirm_password');

        confirmPassword.addEventListener('input', function() {

            if (this.value && this.value !== password.value) {

                this.style.borderColor = 'var(--danger)';

            } else {

                this.style.borderColor = 'var(--border)';

            }

        });


        /* =====================================================
           ANIMATED BACKGROUND
        ===================================================== */

        (function() {

            const canvas = document.getElementById('backgroundCanvas');

            if (!canvas) return;

            const ctx = canvas.getContext('2d');

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

            let width = 0;
            let height = 0;

            let dpr = Math.min(window.devicePixelRatio || 1, 2);

            const points = [];

            function resize() {

                width = window.innerWidth;
                height = window.innerHeight;

                dpr = Math.min(window.devicePixelRatio || 1, 2);

                canvas.width = width * dpr;
                canvas.height = height * dpr;

                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';

                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                points.length = 0;

                const count = Math.min(30, Math.max(14, Math.floor(width / 45)));

                for (let i = 0; i < count; i++) {

                    points.push({

                        x: Math.random() * width,
                        y: Math.random() * height,
                        vx: (Math.random() - .5) * .15,
                        vy: (Math.random() - .5) * .15

                    });

                }

            }

            function getAccent() {

                return getComputedStyle(document.body)
                    .getPropertyValue('--accent')
                    .trim() || '#2563eb';

            }

            function render() {

                ctx.clearRect(0, 0, width, height);

                const accent = getAccent();

                points.forEach(function(point) {

                    point.x += point.vx;
                    point.y += point.vy;

                    if (point.x < -20 || point.x > width + 20) {
                        point.vx *= -1;
                    }

                    if (point.y < -20 || point.y > height + 20) {
                        point.vy *= -1;
                    }

                });

                for (let i = 0; i < points.length; i++) {

                    for (let j = i + 1; j < points.length; j++) {

                        const a = points[i];
                        const b = points[j];

                        const dx = a.x - b.x;
                        const dy = a.y - b.y;

                        const distance = Math.sqrt(dx * dx + dy * dy);

                        if (distance < 150) {

                            const opacity = (1 - distance / 150) * .12;

                            ctx.beginPath();

                            ctx.moveTo(a.x, a.y);

                            ctx.lineTo(b.x, b.y);

                            ctx.strokeStyle = accent;

                            ctx.globalAlpha = opacity;

                            ctx.lineWidth = 1;

                            ctx.stroke();

                            ctx.globalAlpha = 1;

                        }

                    }

                }

                points.forEach(function(point) {

                    ctx.beginPath();

                    ctx.arc(point.x, point.y, 1.5, 0, Math.PI * 2);

                    ctx.fillStyle = accent;

                    ctx.globalAlpha = .18;

                    ctx.fill();

                    ctx.globalAlpha = 1;

                });

            }

            let animationId;

            function animate() {

                render();

                animationId = requestAnimationFrame(animate);

            }

            resize();

            window.addEventListener('resize', resize);

            if (!reduceMotion.matches) {

                animate();

            } else {

                render();

            }

        })();

    </script>

</body>

</html>