<?php
declare(strict_types=1);

session_start();

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

$translationFile = dirname(__DIR__) . '/translations/' . $lang . '.php';

if (!is_file($translationFile)) {
    $lang = 'en';
    $translationFile = dirname(__DIR__) . '/translations/en.php';
}

$translations = require $translationFile;

if (!is_array($translations)) {
    $translations = [];
}

function t(string $key, string $fallback = ''): string
{
    global $translations;
    return htmlspecialchars(
        (string) ($translations[$key] ?? $fallback),
        ENT_QUOTES,
        'UTF-8'
    );
}

// ============================================================
// DATABASE
// ============================================================

$db = null;
$dbError = '';

$databaseFile = dirname(__DIR__) . '/config/database.php';

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
            '[' . date('Y-m-d H:i:s') . '] Login DB error: ' .
            $e->getMessage() .
            PHP_EOL,
            3,
            dirname(__DIR__) . '/logs/errors.log'
        );
    }
}

// ============================================================
// SESSION / ALREADY LOGGED IN
// ============================================================

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $role = (string) $_SESSION['role'];

    $dashboardMap = [
        'superadmin'       => 'admin.php',
        'company_owner'    => 'company.php',
        'company_manager'  => 'company.php',
        'company_employee' => 'company.php',
        'driver'           => 'driver.php',
        'client'           => 'client.php'
    ];

    $destination = $dashboardMap[$role] ?? 'client.php';
    header('Location: ' . $destination);
    exit;
}

// ============================================================
// REDIRECT
// ============================================================

$redirect = $_GET['redirect'] ?? '';

$allowedRedirects = [
    'pages/client.php',
    'pages/company.php',
    'pages/driver.php',
    'pages/admin.php',
    'client.php',
    'company.php',
    'driver.php',
    'admin.php'
];

if (!in_array($redirect, $allowedRedirects, true)) {
    $redirect = '';
}

// ============================================================
// FORM STATE
// ============================================================

$email = '';
$error = '';
$success = '';

// ============================================================
// LOGIN PROCESS
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    if ($email === '') {
        $error = t('login_email_required', 'Please enter your email address.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t('login_email_invalid', 'Please enter a valid email address.');
    } elseif ($password === '') {
        $error = t('login_password_required', 'Please enter your password.');
    } elseif (!$db) {
        $error = t('login_database_error', 'Unable to connect to the database. Please try again later.');
    } else {

        try {

            // ============================================================
            // QUERY — MATCHES ACTUAL SCHEMA
            // ============================================================

            if ($db instanceof mysqli) {

                $sql = "
                    SELECT
                        id,
                        first_name,
                        last_name,
                        email,
                        phone,
                        password_hash,
                        role,
                        is_active,
                        is_banned_globally,
                        ban_reason,
                        preferred_language
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                ";

                $stmt = $db->prepare($sql);

                if (!$stmt) {
                    throw new RuntimeException('Failed to prepare login query.');
                }

                $stmt->bind_param('s', $email);
                $stmt->execute();

                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                $stmt->close();

            } else {

                $sql = "
                    SELECT
                        id,
                        first_name,
                        last_name,
                        email,
                        phone,
                        password_hash,
                        role,
                        is_active,
                        is_banned_globally,
                        ban_reason,
                        preferred_language
                    FROM users
                    WHERE email = :email
                    LIMIT 1
                ";

                $stmt = $db->prepare($sql);
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // ============================================================
            // VALIDATE
            // ============================================================

            if (!$user) {
                $error = t('login_invalid', 'Invalid email or password.');

            } elseif (!password_verify($password, (string) $user['password_hash'])) {
                $error = t('login_invalid', 'Invalid email or password.');

            } elseif ((int) $user['is_active'] === 0) {
                $error = t('login_inactive', 'Your account is inactive. Please contact support.');

            } elseif ((int) $user['is_banned_globally'] === 1) {
                $reason = $user['ban_reason'] ?? t('login_banned_default', 'No reason provided.');
                $error = t('login_banned', 'Your account has been banned.') . ' ' . $reason;

            } else {

                // ============================================================
                // LOGIN SUCCESS
                // ============================================================

                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['first_name'] = (string) ($user['first_name'] ?? '');
                $_SESSION['last_name'] = (string) ($user['last_name'] ?? '');
                $_SESSION['email'] = (string) $user['email'];
                $_SESSION['phone'] = (string) ($user['phone'] ?? '');
                $_SESSION['role'] = (string) $user['role'];
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();

                if (!empty($user['preferred_language'])) {
                    $_SESSION['lang'] = $user['preferred_language'];
                }

                // ============================================================
                // GET COMPANY ID IF APPLICABLE
                // ============================================================

                $role = (string) $user['role'];
                $companyId = null;

                if (in_array($role, ['company_owner', 'company_manager', 'company_employee'], true)) {
                    // Get from company_staff
                    if ($db instanceof mysqli) {
                        $stmt2 = $db->prepare("
                            SELECT company_id FROM company_staff
                            WHERE user_id = ? AND is_active = 1
                            LIMIT 1
                        ");
                        $stmt2->bind_param('i', $user['id']);
                        $stmt2->execute();
                        $result2 = $stmt2->get_result();
                        $staff = $result2->fetch_assoc();
                        $stmt2->close();
                    } else {
                        $stmt2 = $db->prepare("
                            SELECT company_id FROM company_staff
                            WHERE user_id = :user_id AND is_active = 1
                            LIMIT 1
                        ");
                        $stmt2->execute([':user_id' => $user['id']]);
                        $staff = $stmt2->fetch(PDO::FETCH_ASSOC);
                    }
                    if ($staff) {
                        $companyId = (int) $staff['company_id'];
                    }

                } elseif ($role === 'driver') {
                    // Get from drivers table
                    if ($db instanceof mysqli) {
                        $stmt2 = $db->prepare("
                            SELECT company_id FROM drivers
                            WHERE user_id = ? AND is_suspended = 0
                            LIMIT 1
                        ");
                        $stmt2->bind_param('i', $user['id']);
                        $stmt2->execute();
                        $result2 = $stmt2->get_result();
                        $driver = $result2->fetch_assoc();
                        $stmt2->close();
                    } else {
                        $stmt2 = $db->prepare("
                            SELECT company_id FROM drivers
                            WHERE user_id = :user_id AND is_suspended = 0
                            LIMIT 1
                        ");
                        $stmt2->execute([':user_id' => $user['id']]);
                        $driver = $stmt2->fetch(PDO::FETCH_ASSOC);
                    }
                    if ($driver) {
                        $companyId = (int) $driver['company_id'];
                    }
                }

                if ($companyId) {
                    $_SESSION['company_id'] = $companyId;
                }

                // ============================================================
                // DESTINATION
                // ============================================================

                $dashboardMap = [
                    'superadmin'       => 'admin.php',
                    'company_owner'    => 'company.php',
                    'company_manager'  => 'company.php',
                    'company_employee' => 'company.php',
                    'driver'           => 'driver.php',
                    'client'           => 'client.php'
                ];

                $destination = $dashboardMap[$role] ?? 'client.php';

                // Check redirect
                if ($redirect !== '') {
                    $cleanRedirect = ltrim($redirect, '/');
                    if (str_starts_with($cleanRedirect, 'pages/')) {
                        $cleanRedirect = substr($cleanRedirect, strlen('pages/'));
                    }

                    $redirectMap = [
                        'client.php' => 'client.php',
                        'company.php' => 'company.php',
                        'driver.php' => 'driver.php',
                        'admin.php' => 'admin.php'
                    ];

                    $roleRedirectMap = [
                        'client' => 'client.php',
                        'company_owner' => 'company.php',
                        'company_manager' => 'company.php',
                        'company_employee' => 'company.php',
                        'driver' => 'driver.php',
                        'superadmin' => 'admin.php'
                    ];

                    if (
                        isset($redirectMap[$cleanRedirect]) &&
                        $redirectMap[$cleanRedirect] === ($roleRedirectMap[$role] ?? 'client.php')
                    ) {
                        $destination = $redirectMap[$cleanRedirect];
                    }
                }

                // Remember me (V1: session-based only)
                if ($remember) {
                    $_SESSION['remember_requested'] = true;
                }

                header('Location: ' . $destination);
                exit;
            }

        } catch (Throwable $e) {

            $error = t('login_general_error', 'Something went wrong while signing you in.');

            error_log(
                '[' . date('Y-m-d H:i:s') . '] Login error: ' .
                $e->getMessage() .
                PHP_EOL,
                3,
                dirname(__DIR__) . '/logs/errors.log'
            );
        }
    }
}

// ============================================================
// HTML OUTPUT
// ============================================================

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f7f9fc" id="themeColorMeta">
    <meta name="description" content="<?= t('login_meta', 'Sign in to your KaryRide account.') ?>">
    <title><?= t('login_page_title', 'Login') ?> — KaryRide</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        /* ========================================================
           KARYRIDE LOGIN
        ======================================================== */

        :root {
            --bg: #f7f9fc;
            --surface: #ffffff;
            --surface-soft: #f0f4f8;
            --surface-glass: rgba(255, 255, 255, .78);
            --text: #101827;
            --text-soft: #667085;
            --border: #e3e8ef;
            --primary: #101827;
            --primary-text: #ffffff;
            --accent: #2563eb;
            --accent-soft: rgba(37, 99, 235, .10);
            --danger: #dc2626;
            --danger-soft: rgba(220, 38, 38, .09);
            --shadow: 0 30px 80px rgba(15, 23, 42, .13);
            --radius: 18px;
        }

        body.dark {
            --bg: #050a11;
            --surface: #0c1420;
            --surface-soft: #111c2a;
            --surface-glass: rgba(12, 20, 32, .82);
            --text: #f5f7fa;
            --text-soft: #9aa8b9;
            --border: #1c2a3a;
            --primary: #f5f7fa;
            --primary-text: #07111c;
            --accent: #60a5fa;
            --accent-soft: rgba(96, 165, 250, .10);
            --danger: #f87171;
            --danger-soft: rgba(248, 113, 113, .09);
            --shadow: 0 30px 90px rgba(0, 0, 0, .42);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            min-height: 100vh;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
            transition: background-color .25s ease, color .25s ease;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        /* ========================================================
           BACKGROUND
        ======================================================== */

        .background {
            position: fixed;
            inset: 0;
            z-index: -1;
            overflow: hidden;
            pointer-events: none;
        }

        .background-canvas {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(75px);
            opacity: .5;
            animation: floatOrb 14s ease-in-out infinite alternate;
        }

        .orb-one {
            width: 420px;
            height: 420px;
            top: -180px;
            right: -100px;
            background: radial-gradient(circle, rgba(37, 99, 235, .22), transparent 70%);
        }

        .orb-two {
            width: 360px;
            height: 360px;
            left: -170px;
            bottom: -150px;
            background: radial-gradient(circle, rgba(37, 99, 235, .16), transparent 70%);
            animation-delay: -5s;
        }

        @keyframes floatOrb {
            from {
                transform: translate3d(0, 0, 0) scale(1);
            }
            to {
                transform: translate3d(35px, -30px, 0) scale(1.08);
            }
        }

        /* ========================================================
           TOP BAR
        ======================================================== */

        .topbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 100;
            height: 72px;
            background: var(--surface-glass);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .topbar-inner {
            width: min(1180px, calc(100% - 32px));
            height: 100%;
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
            letter-spacing: -.7px;
        }

        .logo-mark {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 11px;
            background: var(--primary);
            color: var(--primary-text);
            box-shadow: 0 8px 25px rgba(0, 0, 0, .08);
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .icon-btn {
            width: 39px;
            height: 39px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: .2s ease;
        }

        .icon-btn:hover {
            background: var(--surface-soft);
            transform: translateY(-1px);
        }

        /* ========================================================
           LANGUAGE MENU
        ======================================================== */

        .language {
            position: relative;
        }

        .language-menu {
            position: absolute;
            top: 47px;
            right: 0;
            width: 145px;
            padding: 6px;
            display: none;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow);
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

        /* ========================================================
           MAIN
        ======================================================== */

        .page {
            min-height: 100vh;
            padding: 112px 20px 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-layout {
            width: min(100%, 950px);
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 26px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        /* ========================================================
           LEFT PANEL
        ======================================================== */

        .login-intro {
            position: relative;
            padding: 55px 45px;
            background: linear-gradient(145deg, var(--primary), #18263a);
            color: var(--primary-text);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        .login-intro::before {
            content: "";
            position: absolute;
            width: 380px;
            height: 380px;
            right: -210px;
            top: -160px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .10);
        }

        .login-intro::after {
            content: "";
            position: absolute;
            width: 250px;
            height: 250px;
            left: -160px;
            bottom: -130px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .07);
        }

        .intro-content {
            position: relative;
            z-index: 2;
        }

        .intro-icon {
            width: 55px;
            height: 55px;
            margin-bottom: 28px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .10);
            font-size: 23px;
        }

        .intro-title {
            font-size: clamp(32px, 4vw, 48px);
            line-height: 1.05;
            letter-spacing: -2.5px;
            margin-bottom: 18px;
        }

        .intro-text {
            max-width: 390px;
            color: rgba(255, 255, 255, .68);
            font-size: 14px;
            line-height: 1.75;
        }

        .intro-features {
            position: relative;
            z-index: 2;
            display: grid;
            gap: 13px;
            margin-top: 40px;
        }

        .intro-feature {
            display: flex;
            align-items: center;
            gap: 11px;
            font-size: 12px;
            color: rgba(255, 255, 255, .76);
        }

        .intro-feature i {
            color: #60a5fa;
        }

        /* ========================================================
           LOGIN CARD
        ======================================================== */

        .login-panel {
            padding: 55px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-heading {
            margin-bottom: 28px;
        }

        .login-heading h1 {
            font-size: 30px;
            letter-spacing: -1.4px;
            margin-bottom: 7px;
        }

        .login-heading p {
            color: var(--text-soft);
            font-size: 13px;
        }

        /* ========================================================
           ERROR
        ======================================================== */

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 13px;
            margin-bottom: 18px;
            border-radius: 11px;
            font-size: 12px;
            line-height: 1.5;
        }

        .alert-danger {
            color: var(--danger);
            background: var(--danger-soft);
            border: 1px solid rgba(220, 38, 38, .12);
        }

        .alert i {
            margin-top: 1px;
        }

        /* ========================================================
           FORM
        ======================================================== */

        .form-group {
            margin-bottom: 17px;
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
            padding: 0 43px 0 42px;
            border: 1px solid var(--border);
            border-radius: 11px;
            outline: none;
            background: var(--surface-soft);
            color: var(--text);
            font-size: 13px;
            transition: .2s ease;
        }

        .form-input::placeholder {
            color: var(--text-soft);
            opacity: .8;
        }

        .form-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }

        .password-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--text-soft);
            cursor: pointer;
        }

        .password-toggle:hover {
            background: var(--surface);
            color: var(--text);
        }

        /* ========================================================
           FORM OPTIONS
        ======================================================== */

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin: 3px 0 22px;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--text-soft);
            font-size: 11px;
            cursor: pointer;
        }

        .remember input {
            width: 14px;
            height: 14px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .forgot {
            color: var(--accent);
            font-size: 11px;
            font-weight: 700;
        }

        .forgot:hover {
            text-decoration: underline;
        }

        /* ========================================================
           SUBMIT
        ======================================================== */

        .submit-btn {
            width: 100%;
            min-height: 49px;
            border: 0;
            border-radius: 11px;
            background: var(--primary);
            color: var(--primary-text);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: .2s ease;
        }

        .submit-btn:hover {
            opacity: .88;
            transform: translateY(-1px);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn.loading {
            pointer-events: none;
            opacity: .7;
        }

        /* ========================================================
           REGISTER
        ======================================================== */

        .register-link {
            margin-top: 23px;
            text-align: center;
            color: var(--text-soft);
            font-size: 12px;
        }

        .register-link a {
            color: var(--accent);
            font-weight: 750;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        /* ========================================================
           BACK HOME
        ======================================================== */

        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 25px;
            color: var(--text-soft);
            font-size: 11px;
            font-weight: 650;
        }

        .back-home:hover {
            color: var(--text);
        }

        /* ========================================================
           RESPONSIVE
        ======================================================== */

        @media (max-width: 800px) {
            .login-layout {
                grid-template-columns: 1fr;
                max-width: 520px;
            }
            .login-intro {
                display: none;
            }
            .login-panel {
                padding: 42px 32px;
            }
        }

        @media (max-width: 500px) {
            .topbar {
                height: 65px;
            }
            .topbar-inner {
                width: min(100% - 24px, 1180px);
            }
            .logo {
                font-size: 18px;
            }
            .logo-mark {
                width: 37px;
                height: 37px;
            }
            .page {
                padding: 95px 12px 30px;
            }
            .login-layout {
                border-radius: 20px;
            }
            .login-panel {
                padding: 35px 22px;
            }
            .login-heading h1 {
                font-size: 27px;
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

    <!-- BACKGROUND -->
    <div class="background" aria-hidden="true">
        <canvas class="background-canvas" id="backgroundCanvas"></canvas>
        <div class="orb orb-one"></div>
        <div class="orb orb-two"></div>
    </div>

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-inner">

            <a href="../index.php" class="logo">
                <span class="logo-mark"><i class="bi bi-sign-turn-right-fill"></i></span>
                <span>KaryRide</span>
            </a>

            <div class="top-actions">
                <div class="language">
                    <button type="button" class="icon-btn" id="languageButton" aria-label="<?= t('nav_language', 'Language') ?>" aria-expanded="false">
                        <i class="bi bi-translate"></i>
                    </button>
                    <div class="language-menu" id="languageMenu">
                        <a href="?lang=en"><i class="bi bi-translate"></i> English</a>
                        <a href="?lang=rn"><i class="bi bi-translate"></i> Kirundi</a>
                    </div>
                </div>

                <button type="button" class="icon-btn" id="themeButton" aria-label="<?= t('nav_theme', 'Theme') ?>" aria-pressed="false">
                    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
                </button>
            </div>

        </div>
    </header>

    <!-- MAIN -->
    <main class="page">
        <div class="login-layout">

            <!-- INTRO -->
            <section class="login-intro">
                <div class="intro-content">
                    <div class="intro-icon"><i class="bi bi-car-front-fill"></i></div>
                    <h2 class="intro-title"><?= t('login_intro_title', 'Move smarter with KaryRide.') ?></h2>
                    <p class="intro-text"><?= t('login_intro_text', 'Connect with drivers, book your ride and manage your journeys from one simple platform.') ?></p>
                    <div class="intro-features">
                        <div class="intro-feature"><i class="bi bi-check-circle-fill"></i> <span><?= t('login_feature_one', 'Taxi and motorcycle rides') ?></span></div>
                        <div class="intro-feature"><i class="bi bi-check-circle-fill"></i> <span><?= t('login_feature_two', 'Track your journey') ?></span></div>
                        <div class="intro-feature"><i class="bi bi-check-circle-fill"></i> <span><?= t('login_feature_three', 'Simple and convenient booking') ?></span></div>
                    </div>
                </div>
            </section>

            <!-- LOGIN FORM -->
            <section class="login-panel">

                <div class="login-heading">
                    <h1><?= t('login_title', 'Welcome back') ?></h1>
                    <p><?= t('login_subtitle', 'Sign in to continue to your KaryRide account.') ?></p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span><?= $error ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" novalidate>

                    <div class="form-group">
                        <label for="email" class="form-label"><?= t('login_email', 'Email address') ?></label>
                        <div class="input-wrap">
                            <i class="bi bi-envelope input-icon"></i>
                            <input type="email" id="email" name="email" class="form-input" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= t('login_email_placeholder', 'you@example.com') ?>" autocomplete="email" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label"><?= t('login_password', 'Password') ?></label>
                        <div class="input-wrap">
                            <i class="bi bi-lock input-icon"></i>
                            <input type="password" id="password" name="password" class="form-input" placeholder="<?= t('login_password_placeholder', 'Enter your password') ?>" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" id="passwordToggle" aria-label="<?= t('login_show_password', 'Show password') ?>">
                                <i class="bi bi-eye" id="passwordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="remember">
                            <input type="checkbox" name="remember" value="1">
                            <span><?= t('login_remember', 'Remember me') ?></span>
                        </label>
                        <a href="forgot-password.php" class="forgot"><?= t('login_forgot', 'Forgot password?') ?></a>
                    </div>

                    <button type="submit" class="submit-btn" id="submitButton">
                        <i class="bi bi-box-arrow-in-right" id="submitIcon"></i>
                        <span id="submitText"><?= t('login_button', 'Sign in') ?></span>
                    </button>

                </form>

                <div class="register-link">
                    <?= t('login_no_account', "Don't have an account?") ?>
                    <a href="register.php"><?= t('login_create_account', 'Create one') ?></a>
                </div>

                <a href="../index.php" class="back-home">
                    <i class="bi bi-arrow-left"></i>
                    <?= t('login_back_home', 'Back to KaryRide') ?>
                </a>

            </section>

        </div>
    </main>

    <script>
        /* ========================================================
           THEME
        ======================================================== */

        const themeButton = document.getElementById('themeButton');
        const themeIcon = document.getElementById('themeIcon');
        const themeMeta = document.getElementById('themeColorMeta');

        function applyTheme(theme) {
            const dark = theme === 'dark';
            document.body.classList.toggle('dark', dark);
            themeIcon.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
            themeButton.setAttribute('aria-pressed', String(dark));
            if (themeMeta) {
                themeMeta.setAttribute('content', dark ? '#050a11' : '#f7f9fc');
            }
        }

        const savedTheme = localStorage.getItem('karyride_theme');
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'dark' || savedTheme === 'light') {
            applyTheme(savedTheme);
        } else {
            applyTheme(prefersDark ? 'dark' : 'light');
        }

        themeButton.addEventListener('click', function() {
            const dark = !document.body.classList.contains('dark');
            const theme = dark ? 'dark' : 'light';
            localStorage.setItem('karyride_theme', theme);
            applyTheme(theme);
        });

        /* ========================================================
           LANGUAGE MENU
        ======================================================== */

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

        /* ========================================================
           PASSWORD VISIBILITY
        ======================================================== */

        const password = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordIcon = document.getElementById('passwordIcon');

        passwordToggle.addEventListener('click', function() {
            const visible = password.type === 'text';
            password.type = visible ? 'password' : 'text';
            passwordIcon.className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
            passwordToggle.setAttribute('aria-label',
                visible ?
                '<?= t('login_show_password', 'Show password') ?>' :
                '<?= t('login_hide_password', 'Hide password') ?>'
            );
        });

        /* ========================================================
           FORM LOADING STATE
        ======================================================== */

        const loginForm = document.getElementById('loginForm');
        const submitButton = document.getElementById('submitButton');
        const submitText = document.getElementById('submitText');
        const submitIcon = document.getElementById('submitIcon');

        loginForm.addEventListener('submit', function(event) {
            const email = document.getElementById('email').value.trim();
            const passwordValue = password.value;

            if (email === '' || passwordValue === '') {
                return;
            }

            submitButton.classList.add('loading');
            submitIcon.className = 'bi bi-arrow-repeat';
            submitIcon.style.animation = 'spin .8s linear infinite';
            submitText.textContent = '<?= t('login_signing_in', 'Signing in...') ?>';
        });

        /* ========================================================
           ESCAPE KEY
        ======================================================== */

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                languageMenu.classList.remove('open');
                languageButton.setAttribute('aria-expanded', 'false');
            }
        });

        /* ========================================================
           ANIMATED BACKGROUND
        ======================================================== */

        (function() {

            const canvas = document.getElementById('backgroundCanvas');
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

            let width = 0;
            let height = 0;
            let animationId = null;

            const particles = [];
            const particleCount = 38;

            function getColors() {
                const styles = getComputedStyle(document.body);
                return {
                    accent: styles.getPropertyValue('--accent').trim() || '#2563eb',
                    border: styles.getPropertyValue('--border').trim() || '#e3e8ef'
                };
            }

            function resize() {
                width = window.innerWidth;
                height = window.innerHeight;

                const dpr = Math.min(window.devicePixelRatio || 1, 2);

                canvas.width = Math.round(width * dpr);
                canvas.height = Math.round(height * dpr);
                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';

                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                createParticles();
                draw();
            }

            function createParticles() {
                particles.length = 0;
                for (let i = 0; i < particleCount; i++) {
                    particles.push({
                        x: Math.random() * width,
                        y: Math.random() * height,
                        radius: Math.random() * 1.8 + .4,
                        vx: (Math.random() - .5) * .16,
                        vy: (Math.random() - .5) * .16,
                        alpha: Math.random() * .25 + .05
                    });
                }
            }

            function draw() {
                ctx.clearRect(0, 0, width, height);
                const colors = getColors();

                // Grid
                const gridSize = 70;
                ctx.lineWidth = 1;
                ctx.strokeStyle = colors.border;
                ctx.globalAlpha = .22;

                for (let x = 0; x < width; x += gridSize) {
                    ctx.beginPath();
                    ctx.moveTo(x, 0);
                    ctx.lineTo(x, height);
                    ctx.stroke();
                }

                for (let y = 0; y < height; y += gridSize) {
                    ctx.beginPath();
                    ctx.moveTo(0, y);
                    ctx.lineTo(width, y);
                    ctx.stroke();
                }

                ctx.globalAlpha = 1;

                // Particles
                particles.forEach(function(particle) {
                    ctx.beginPath();
                    ctx.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
                    ctx.fillStyle = colors.accent;
                    ctx.globalAlpha = particle.alpha;
                    ctx.fill();
                });

                ctx.globalAlpha = 1;

                // Connections
                for (let i = 0; i < particles.length; i++) {
                    for (let j = i + 1; j < particles.length; j++) {
                        const a = particles[i];
                        const b = particles[j];
                        const dx = a.x - b.x;
                        const dy = a.y - b.y;
                        const distance = Math.sqrt(dx * dx + dy * dy);

                        if (distance < 125) {
                            ctx.beginPath();
                            ctx.moveTo(a.x, a.y);
                            ctx.lineTo(b.x, b.y);
                            ctx.strokeStyle = colors.accent;
                            ctx.globalAlpha = .055 * (1 - distance / 125);
                            ctx.stroke();
                        }
                    }
                }

                ctx.globalAlpha = 1;
            }

            function animate() {
                particles.forEach(function(particle) {
                    particle.x += particle.vx;
                    particle.y += particle.vy;

                    if (particle.x < -20) particle.x = width + 20;
                    if (particle.x > width + 20) particle.x = -20;
                    if (particle.y < -20) particle.y = height + 20;
                    if (particle.y > height + 20) particle.y = -20;
                });

                draw();
                animationId = requestAnimationFrame(animate);
            }

            function start() {
                if (reduceMotion.matches) {
                    if (animationId) {
                        cancelAnimationFrame(animationId);
                        animationId = null;
                    }
                    draw();
                    return;
                }

                if (!animationId) {
                    animate();
                }
            }

            window.addEventListener('resize', resize);

            document.addEventListener('click', function(event) {
                if (event.target.closest('#themeButton')) {
                    setTimeout(draw, 50);
                }
            });

            if (reduceMotion.addEventListener) {
                reduceMotion.addEventListener('change', function() {
                    start();
                });
            }

            resize();
            start();

        })();
    </script>

    <style>
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>

</body>

</html>