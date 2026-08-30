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

// ============================================================
// TRANSLATIONS
// ============================================================

$translationFile = __DIR__ . '/translations/' . $lang . '.php';

if (!is_file($translationFile)) {
    $lang = 'en';
    $translationFile = __DIR__ . '/translations/en.php';
}

$translations = require $translationFile;

if (!is_array($translations)) {
    $translations = [];
}

function t(string $key, string $fallback = ''): string
{
    global $translations;
    $value = $translations[$key] ?? $fallback;
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// SESSION STATE
// ============================================================

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;
$userName = $_SESSION['first_name'] ?? '';

// Dashboard link based on role
$dashboardLink = 'pages/client.php';

switch ($userRole) {
    case 'superadmin':
        $dashboardLink = 'pages/admin.php';
        break;
    case 'company_owner':
    case 'company_manager':
    case 'company_employee':
        $dashboardLink = 'pages/company.php';
        break;
    case 'driver':
        $dashboardLink = 'pages/driver.php';
        break;
    case 'client':
        $dashboardLink = 'pages/client.php';
        break;
}

// ============================================================
// STATS
// ============================================================

$stats = [
    'rides_today'    => 0,
    'active_drivers' => 0,
    'companies'      => 0,
    'happy_clients'  => 0
];

try {
    $databaseFile = __DIR__ . '/config/database.php';
    if (is_file($databaseFile)) {
        require_once $databaseFile;
        // Will connect to DB once schema is finalized
    }
} catch (Throwable $e) {
    error_log('[INDEX STATS] ' . $e->getMessage(), 3, __DIR__ . '/logs/errors.log');
}

// ============================================================
// WELCOME MESSAGE
// ============================================================

$welcomeMessage = '';
if ($isLoggedIn && $userName) {
    $welcomeMessage = t('welcome_back', 'Welcome back') . ', ' . htmlspecialchars($userName) . '!';
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= t('meta_description', 'KaryRide - Smart taxi and motorcycle transportation in Burundi.') ?>">
    <meta name="theme-color" content="#f7f9fc" id="themeColorMeta">
    <title>KaryRide</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        /* ====================================================
           KARYRIDE DESIGN TOKENS
        ==================================================== */

        :root {
            --bg: #f7f9fc;
            --surface: #ffffff;
            --surface-soft: #f0f4f8;
            --surface-glass: rgba(255, 255, 255, 0.78);
            --text: #101827;
            --text-soft: #667085;
            --border: #e3e8ef;
            --primary: #101827;
            --primary-text: #ffffff;
            --accent: #2563eb;
            --accent-soft: rgba(37, 99, 235, 0.10);
            --success: #16a34a;
            --warning: #f59e0b;
            --hero-grid: rgba(15, 23, 42, 0.055);
            --shadow-sm: 0 8px 25px rgba(15, 23, 42, 0.06);
            --shadow-lg: 0 30px 80px rgba(15, 23, 42, 0.13);
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
        }

        body.dark {
            --bg: #050a11;
            --surface: #0c1420;
            --surface-soft: #111c2a;
            --surface-glass: rgba(12, 20, 32, 0.82);
            --text: #f5f7fa;
            --text-soft: #9aa8b9;
            --border: #1c2a3a;
            --primary: #f5f7fa;
            --primary-text: #07111c;
            --accent: #60a5fa;
            --accent-soft: rgba(96, 165, 250, 0.10);
            --hero-grid: rgba(255, 255, 255, 0.035);
            --shadow-sm: 0 8px 30px rgba(0, 0, 0, 0.20);
            --shadow-lg: 0 30px 90px rgba(0, 0, 0, 0.42);
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 80px;
        }

        body {
            min-height: 100vh;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            overflow-x: hidden;
            transition: background 0.25s ease, color 0.25s ease;
        }

        body,
        button,
        a,
        .card,
        .header {
            transition: background-color 0.25s ease, color 0.25s ease, border-color 0.25s ease;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        .container {
            width: min(1240px, calc(100% - 48px));
            margin: 0 auto;
        }

        /* Header */
        .header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            background: var(--surface-glass);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-bottom: 1px solid var(--border);
        }

        .nav {
            height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 21px;
            font-weight: 850;
            letter-spacing: -0.6px;
            flex-shrink: 0;
        }

        .logo-mark {
            width: 41px;
            height: 41px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--primary);
            color: var(--primary-text);
            font-size: 18px;
            box-shadow: var(--shadow-sm);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-left: auto;
        }

        .nav-links a {
            color: var(--text-soft);
            font-size: 13px;
            font-weight: 700;
            position: relative;
        }

        .nav-links a::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: -7px;
            width: 0;
            height: 2px;
            background: var(--accent);
            border-radius: 10px;
            transition: width 0.2s ease;
        }

        .nav-links a:hover {
            color: var(--text);
        }

        .nav-links a:hover::after {
            width: 100%;
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
            font-size: 16px;
        }

        .icon-btn:hover {
            background: var(--surface-soft);
        }

        /* Language Menu */
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
            font-weight: 600;
        }

        .language-menu a:hover {
            background: var(--surface-soft);
        }

        /* Buttons */
        .btn {
            min-height: 43px;
            padding: 0 17px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 750;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary);
            color: var(--primary-text);
        }

        .btn-primary:hover {
            opacity: 0.88;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--surface);
            color: var(--text);
            border-color: var(--border);
        }

        .btn-secondary:hover {
            background: var(--surface-soft);
            transform: translateY(-1px);
        }

        .btn-accent {
            background: var(--accent);
            color: white;
        }

        .btn-accent:hover {
            opacity: 0.88;
            transform: translateY(-1px);
        }

        /* Hero */
        .hero {
            min-height: 100vh;
            padding-top: 76px;
            position: relative;
            display: flex;
            align-items: center;
            overflow: hidden;
            background: var(--bg);
            padding-left: 100px;
        }

        .hero-background {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }

        .hero-canvas {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            display: block;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.6;
            will-change: transform;
            animation: orbFloat 14s ease-in-out infinite alternate;
        }

        .orb-one {
            width: 460px;
            height: 460px;
            top: 6%;
            right: -140px;
            background: radial-gradient(circle, color-mix(in srgb, var(--accent) 32%, transparent), transparent 70%);
        }

        .orb-two {
            width: 360px;
            height: 360px;
            left: -150px;
            bottom: -40px;
            background: radial-gradient(circle, color-mix(in srgb, var(--accent) 20%, transparent), transparent 70%);
            animation-delay: -5s;
        }

        @keyframes orbFloat {
            0% {
                transform: translate3d(0, 0, 0) scale(1);
            }
            100% {
                transform: translate3d(30px, -35px, 0) scale(1.1);
            }
        }

        .hero-background::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, transparent 0%, var(--bg) 96%);
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            width: 100%;
            padding: 85px 0 70px;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(380px, 0.82fr);
            gap: 70px;
            align-items: center;
        }

        .hero-copy {
            max-width: 650px;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border: 1px solid var(--border);
            border-radius: 100px;
            background: var(--surface-glass);
            color: var(--text-soft);
            font-size: 11px;
            font-weight: 750;
            margin-bottom: 22px;
        }

        .hero-badge i {
            color: var(--accent);
            font-size: 8px;
        }

        .hero h1 {
            font-size: clamp(48px, 6.3vw, 78px);
            line-height: 0.98;
            letter-spacing: -4px;
            margin-bottom: 25px;
        }

        .hero h1 span {
            color: var(--accent);
        }

        .hero-description {
            max-width: 580px;
            color: var(--text-soft);
            font-size: 17px;
            line-height: 1.75;
            margin-bottom: 30px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 30px;
        }

        /* Stats Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid var(--border);
        }

        .stat-item {
            text-align: left;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 850;
            letter-spacing: -1px;
            color: var(--accent);
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-soft);
            font-weight: 600;
        }

        /* Search Box */
        .ride-search {
            width: min(100%, 610px);
            padding: 9px;
            background: var(--surface-glass);
            border: 1px solid var(--border);
            border-radius: 15px;
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(15px);
        }

        .search-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 8px;
        }

        .search-field {
            min-height: 53px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 13px;
            border-radius: 10px;
            background: var(--surface-soft);
            cursor: pointer;
        }

        .search-field i {
            color: var(--accent);
            font-size: 17px;
        }

        .search-field small {
            display: block;
            color: var(--text-soft);
            font-size: 9px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .search-field strong {
            display: block;
            max-width: 170px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-size: 11px;
        }

        .search-button {
            min-width: 50px;
        }

        /* Phone Preview */
        .hero-visual {
            min-height: 560px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .phone {
            width: 285px;
            height: 555px;
            padding: 8px;
            border: 6px solid #263443;
            border-radius: 40px;
            background: #0b121b;
            box-shadow: 0 40px 100px rgba(0, 0, 0, 0.30);
            transform: rotate(3deg);
            position: relative;
            z-index: 3;
        }

        .phone-notch {
            position: absolute;
            top: 7px;
            left: 50%;
            transform: translateX(-50%);
            width: 92px;
            height: 20px;
            background: #05080c;
            border-radius: 0 0 14px 14px;
            z-index: 5;
        }

        .phone-screen {
            width: 100%;
            height: 100%;
            overflow: hidden;
            border-radius: 30px;
            background: #e9eef3;
            position: relative;
        }

        .phone-map {
            height: 67%;
            position: relative;
            overflow: hidden;
            background:
                linear-gradient(35deg, transparent 46%, #c7d1db 47%, #c7d1db 49%, transparent 50%),
                linear-gradient(125deg, transparent 44%, #d2dae2 45%, #d2dae2 48%, transparent 49%),
                #e9eef3;
        }

        .phone-map::before {
            content: "";
            position: absolute;
            width: 170%;
            height: 8px;
            top: 46%;
            left: -30%;
            background: white;
            transform: rotate(-17deg);
            box-shadow: 0 0 0 2px #c5d0da;
        }

        .phone-map::after {
            content: "";
            position: absolute;
            width: 160%;
            height: 4px;
            top: 20%;
            left: -20%;
            background: rgba(255, 255, 255, 0.9);
            transform: rotate(22deg);
        }

        .phone-pin {
            position: absolute;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: var(--accent);
            border: 4px solid white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.22);
            z-index: 3;
        }

        .phone-pin-a {
            left: 25%;
            top: 30%;
        }

        .phone-pin-b {
            right: 22%;
            bottom: 22%;
        }

        .phone-route {
            position: absolute;
            width: 125px;
            height: 105px;
            left: 27%;
            top: 31%;
            border-right: 3px dashed rgba(37, 99, 235, 0.65);
            border-bottom: 3px dashed rgba(37, 99, 235, 0.65);
            border-radius: 0 0 80px 0;
            transform: rotate(-10deg);
            z-index: 2;
        }

        .phone-card {
            position: absolute;
            left: 12px;
            right: 12px;
            bottom: 12px;
            padding: 15px;
            border-radius: 17px;
            background: white;
            color: #101827;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.16);
            z-index: 4;
        }

        .driver-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 13px;
        }

        .driver-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #dce5ed;
            color: #566779;
        }

        .driver-info {
            flex: 1;
        }

        .driver-info strong {
            display: block;
            font-size: 12px;
        }

        .driver-info small {
            display: block;
            color: #758396;
            font-size: 10px;
        }

        .driver-info small i {
            color: #16a34a;
            font-size: 6px;
            vertical-align: middle;
            margin-right: 3px;
        }

        .driver-rating {
            font-size: 10px;
            font-weight: 750;
        }

        .driver-rating i {
            color: #f59e0b;
        }

        .ride-status {
            padding-top: 11px;
            border-top: 1px solid #e7ebef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 10px;
        }

        .ride-status i {
            color: #16a34a;
        }

        /* Sections */
        .section {
            padding: 100px 0;
        }

        .section-heading {
            max-width: 680px;
            margin: 0 auto 55px;
            text-align: center;
        }

        .section-heading h2 {
            font-size: clamp(32px, 4vw, 46px);
            letter-spacing: -2px;
            line-height: 1.1;
            margin-bottom: 14px;
        }

        .section-heading p {
            color: var(--text-soft);
            font-size: 15px;
            line-height: 1.7;
        }

        /* Features */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .feature-card {
            padding: 28px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            transition: transform 0.25s ease, box-shadow 0.25s ease, background-color 0.25s ease, border-color 0.25s ease;
        }

        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            border-radius: 13px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 20px;
        }

        .feature-card h3 {
            font-size: 17px;
            margin-bottom: 9px;
        }

        .feature-card p {
            color: var(--text-soft);
            font-size: 14px;
            line-height: 1.7;
        }

        /* Steps */
        .steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .step {
            padding: 30px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }

        .step:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .step-number {
            color: var(--accent);
            font-size: 11px;
            font-weight: 850;
            margin-bottom: 18px;
        }

        .step-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            border-radius: 13px;
            background: var(--surface-soft);
            font-size: 19px;
            color: var(--accent);
        }

        .step h3 {
            font-size: 18px;
            margin-bottom: 9px;
        }

        .step p {
            color: var(--text-soft);
            font-size: 14px;
            line-height: 1.7;
        }

        /* CTA */
        .cta {
            padding: 0 0 100px;
        }

        .cta-box {
            padding: 70px 30px;
            text-align: center;
            border-radius: var(--radius-lg);
            background: var(--primary);
            color: var(--primary-text);
            position: relative;
            overflow: hidden;
        }

        .cta-box::before {
            content: "";
            position: absolute;
            width: 400px;
            height: 400px;
            right: -150px;
            top: -250px;
            border-radius: 50%;
            border: 1px solid rgba(128, 128, 128, 0.25);
        }

        .cta-box h2 {
            position: relative;
            z-index: 2;
            font-size: clamp(32px, 4vw, 48px);
            letter-spacing: -2px;
            margin-bottom: 13px;
        }

        .cta-box p {
            position: relative;
            z-index: 2;
            opacity: 0.7;
            margin-bottom: 27px;
        }

        .cta-box .btn {
            position: relative;
            z-index: 2;
        }

        .cta-box .btn-secondary {
            background: var(--surface);
            color: var(--text);
            border-color: var(--border);
        }

        /* Footer */
        .footer {
            border-top: 1px solid var(--border);
            padding: 28px 0;
            background: var(--surface);
        }

        .footer-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .footer-brand {
            font-size: 16px;
            font-weight: 850;
        }

        .footer-text {
            color: var(--text-soft);
            font-size: 12px;
        }

        .footer-links {
            display: flex;
            gap: 20px;
        }

        .footer-links a {
            color: var(--text-soft);
            font-size: 12px;
        }

        .footer-links a:hover {
            color: var(--text);
        }

        .welcome-banner {
            display: inline-block;
            padding: 8px 16px;
            background: var(--accent-soft);
            color: var(--accent);
            border-radius: 100px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 1000px) {
            .nav-links {
                display: none;
            }
            .hero-grid {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 30px;
            }
            .hero-copy {
                margin: 0 auto;
            }
            .hero-description {
                margin-left: auto;
                margin-right: auto;
            }
            .hero-actions {
                justify-content: center;
            }
            .ride-search {
                margin: 0 auto;
                text-align: left;
            }
            .hero-visual {
                min-height: 590px;
            }
            .feature-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
                text-align: center;
            }
            .stat-item {
                text-align: center;
            }
        }

        @media (max-width: 650px) {
            .container {
                width: min(100% - 32px, 1240px);
            }
            .nav {
                height: 68px;
            }
            .header {
                position: fixed;
            }
            .nav-actions .btn-secondary {
                display: none;
            }
            .hero {
                padding-top: 68px;
            }
            .hero-content {
                padding: 65px 0 45px;
            }
            .hero h1 {
                font-size: 48px;
                letter-spacing: -2.7px;
            }
            .hero-description {
                font-size: 15px;
            }
            .search-row {
                grid-template-columns: 1fr;
            }
            .search-button {
                width: 100%;
            }
            .hero-visual {
                min-height: 500px;
            }
            .phone {
                width: 250px;
                height: 490px;
            }
            .feature-grid {
                grid-template-columns: 1fr;
            }
            .steps {
                grid-template-columns: 1fr;
            }
            .section {
                padding: 75px 0;
            }
            .cta {
                padding: 0 0 75px;
            }
            .cta-box {
                padding: 55px 20px;
            }
            .footer-container {
                flex-direction: column;
                text-align: center;
            }
            .footer-links {
                justify-content: center;
            }
            .stats-bar {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .stat-number {
                font-size: 22px;
            }
        }

        @media (max-width: 400px) {
            .phone {
                width: 200px;
                height: 400px;
                padding: 5px;
                border-width: 4px;
                border-radius: 30px;
            }
            .phone-notch {
                width: 60px;
                height: 15px;
                top: 5px;
            }
            .phone-screen {
                border-radius: 22px;
            }
            .phone-card {
                padding: 10px;
                left: 8px;
                right: 8px;
                bottom: 8px;
            }
            .driver-avatar {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }
            .driver-info strong {
                font-size: 10px;
            }
            .driver-info small {
                font-size: 8px;
            }
            .driver-rating {
                font-size: 8px;
            }
            .ride-status {
                font-size: 8px;
                padding-top: 8px;
            }
            .hero-visual {
                min-height: 380px;
            }
            .hero h1 {
                font-size: 36px;
                letter-spacing: -2px;
            }
            .hero-badge {
                font-size: 9px;
                padding: 5px 10px;
            }
            .stats-bar {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .stat-number {
                font-size: 18px;
            }
            .stat-label {
                font-size: 10px;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>

</head>

<body>

    <!-- HEADER -->
    <header class="header">
        <nav class="nav container">

            <a href="index.php" class="logo">
                <span class="logo-mark"><i class="bi bi-sign-turn-right-fill"></i></span>
                <span>KaryRide</span>
            </a>

            <div class="nav-links">
                <a href="#home"><?= t('nav_home', 'Home') ?></a>
                <a href="#how"><?= t('nav_how', 'How it works') ?></a>
                <a href="#services"><?= t('nav_services', 'Services') ?></a>
                <a href="#about"><?= t('nav_about', 'About') ?></a>
            </div>

            <div class="nav-actions">

                <!-- Language -->
                <div class="language-wrapper">
                    <button type="button" class="icon-btn" id="languageButton" aria-label="<?= t('nav_language', 'Language') ?>" aria-haspopup="true" aria-expanded="false" aria-controls="languageMenu">
                        <i class="bi bi-translate"></i>
                    </button>
                    <div class="language-menu" id="languageMenu" role="menu">
                        <a href="?<?= http_build_query(['lang' => 'en']) ?>">
                            <i class="bi bi-translate"></i> English
                        </a>
                        <a href="?<?= http_build_query(['lang' => 'rn']) ?>">
                            <i class="bi bi-translate"></i> Kirundi
                        </a>
                    </div>
                </div>

                <!-- Theme -->
                <button type="button" class="icon-btn" id="themeButton" aria-label="<?= t('nav_theme', 'Theme') ?>" aria-pressed="false">
                    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
                </button>

                <?php if ($isLoggedIn): ?>
                    <a href="<?= $dashboardLink ?>" class="btn btn-primary">
                        <i class="bi bi-speedometer2"></i>
                        <?= t('nav_dashboard', 'Dashboard') ?>
                    </a>
                <?php else: ?>
                    <a href="pages/login.php" class="btn btn-secondary">
                        <?= t('nav_login', 'Login') ?>
                    </a>
                    <a href="pages/register.php" class="btn btn-primary">
                        <?= t('nav_register', 'Get started') ?>
                    </a>
                <?php endif; ?>

            </div>

        </nav>
    </header>

    <!-- MAIN -->
    <main>

        <!-- HERO -->
        <section class="hero" id="home">

            <div class="hero-background" aria-hidden="true">
                <canvas class="hero-canvas" id="heroCanvas"></canvas>
                <div class="orb orb-one"></div>
                <div class="orb orb-two"></div>
            </div>

            <div class="hero-content container">
                <div class="hero-grid">

                    <div class="hero-copy">

                        <?php if ($welcomeMessage): ?>
                            <div class="welcome-banner">
                                <i class="bi bi-hand-thumbs-up-fill"></i>
                                <?= $welcomeMessage ?>
                            </div>
                        <?php endif; ?>

                        <div class="hero-badge">
                            <i class="bi bi-circle-fill"></i>
                            <?= t('hero_badge', 'Smart mobility for everyday journeys') ?>
                        </div>

                        <h1>
                            <?= t('hero_title_1', 'Your journey.') ?><br>
                            <span><?= t('hero_title_2', 'Your way.') ?></span>
                        </h1>

                        <p class="hero-description">
                            <?= t('hero_description', 'Book a taxi or motorcycle, connect with a nearby driver and get where you need to go safely and conveniently.') ?>
                        </p>

                        <div class="hero-actions">

                            <?php if ($isLoggedIn): ?>
                                <a href="<?= $dashboardLink ?>" class="btn btn-primary">
                                    <i class="bi bi-car-front-fill"></i>
                                    <?= t('hero_book', 'Book a ride') ?>
                                </a>
                            <?php else: ?>
                                <a href="pages/register.php" class="btn btn-primary">
                                    <i class="bi bi-car-front-fill"></i>
                                    <?= t('hero_book', 'Book a ride') ?>
                                </a>
                            <?php endif; ?>

                            <a href="pages/login.php" class="btn btn-secondary">
                                <i class="bi bi-building"></i>
                                <?= t('hero_company', 'Company login') ?>
                            </a>

                        </div>

                        <!-- ============================================
                             FIX 1: SEARCH BOX - NOW RENDERED
                        ============================================ -->

                        <div class="ride-search" aria-label="<?= t('quick_ride', 'Quick ride') ?>">
                            <div class="search-row">

                                <div class="search-field" id="pickupField" role="button" tabindex="0">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <div>
                                        <small><?= t('pickup_label', 'PICKUP') ?></small>
                                        <strong id="pickupLocation">
                                            <?= t('detecting_location', 'Detecting location...') ?>
                                        </strong>
                                    </div>
                                </div>

                                <div class="search-field" id="destinationField" role="button" tabindex="0">
                                    <i class="bi bi-flag-fill"></i>
                                    <div>
                                        <small><?= t('destination_label', 'DESTINATION') ?></small>
                                        <strong>
                                            <?= t('choose_destination', 'Choose destination') ?>
                                        </strong>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-accent search-button" id="findRideButton" aria-label="<?= t('find_ride', 'Find a ride') ?>">
                                    <i class="bi bi-arrow-right"></i>
                                </button>

                            </div>
                        </div>

                        <!-- Stats Bar -->
                        <div class="stats-bar">
                            <div class="stat-item">
                                <div class="stat-number" id="ridesToday"><?= number_format($stats['rides_today']) ?></div>
                                <div class="stat-label"><?= t('stats_rides_today', 'Rides today') ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number" id="activeDrivers"><?= number_format($stats['active_drivers']) ?></div>
                                <div class="stat-label"><?= t('stats_active_drivers', 'Active drivers') ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number" id="companies"><?= number_format($stats['companies']) ?></div>
                                <div class="stat-label"><?= t('stats_companies', 'Companies') ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number" id="happyClients"><?= number_format($stats['happy_clients']) ?></div>
                                <div class="stat-label"><?= t('stats_happy_clients', 'Happy clients') ?></div>
                            </div>
                        </div>

                    </div>

                    <!-- Phone Preview -->
                    <div class="hero-visual">

                        <div class="phone">
                            <div class="phone-notch"></div>

                            <div class="phone-screen">

                                <div class="phone-map">
                                    <div class="phone-route"></div>
                                    <div class="phone-pin phone-pin-a"></div>
                                    <div class="phone-pin phone-pin-b"></div>
                                </div>

                                <div class="phone-card">

                                    <div class="driver-row">
                                        <div class="driver-avatar">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                        <div class="driver-info">
                                            <strong>KaryRide Driver</strong>
                                            <small>
                                                <i class="bi bi-circle-fill"></i>
                                                <?= t('driver_nearby', 'Driver nearby') ?>
                                            </small>
                                        </div>
                                        <div class="driver-rating">
                                            <i class="bi bi-star-fill"></i>
                                            4.9
                                        </div>
                                    </div>

                                    <div class="ride-status">
                                        <span>
                                            <i class="bi bi-clock"></i>
                                            <?= t('driver_minutes', '4 min') ?>
                                        </span>
                                        <strong><?= t('driver_status', 'On the way') ?></strong>
                                    </div>

                                </div>

                            </div>
                        </div>

                    </div>

                </div>
            </div>

        </section>

        <!-- SERVICES -->
        <section class="section" id="services">
            <div class="container">

                <div class="section-heading">
                    <h2><?= t('features_title', 'Everything you need for a better ride') ?></h2>
                    <p><?= t('features_description', 'KaryRide brings passengers, drivers and transport companies together in one simple platform.') ?></p>
                </div>

                <div class="feature-grid">

                    <article class="feature-card">
                        <div class="feature-icon"><i class="bi bi-lightning-charge-fill"></i></div>
                        <h3><?= t('feature_booking_title', 'Quick booking') ?></h3>
                        <p><?= t('feature_booking_text', 'Choose your pickup point, destination and preferred vehicle in just a few steps.') ?></p>
                    </article>

                    <article class="feature-card">
                        <div class="feature-icon"><i class="bi bi-car-front-fill"></i></div>
                        <h3><?= t('feature_vehicle_title', 'Taxi & Moto') ?></h3>
                        <p><?= t('feature_vehicle_text', 'Choose the vehicle that fits your journey, from motorcycles to taxis.') ?></p>
                    </article>

                    <article class="feature-card">
                        <div class="feature-icon"><i class="bi bi-geo-alt-fill"></i></div>
                        <h3><?= t('feature_tracking_title', 'Track your ride') ?></h3>
                        <p><?= t('feature_tracking_text', 'See your driver and follow the progress of your trip.') ?></p>
                    </article>

                    <article class="feature-card">
                        <div class="feature-icon"><i class="bi bi-cash-stack"></i></div>
                        <h3><?= t('feature_pricing_title', 'Clear pricing') ?></h3>
                        <p><?= t('feature_pricing_text', 'Know the applicable fare before confirming your ride.') ?></p>
                    </article>

                    <article class="feature-card">
                        <div class="feature-icon"><i class="bi bi-shield-check"></i></div>
                        <h3><?= t('feature_driver_title', 'Trusted drivers') ?></h3>
                        <p><?= t('feature_driver_text', 'Connect with drivers managed through registered transport companies.') ?></p>
                    </article>

                    <article class="feature-card">
                        <div class="feature-icon"><i class="bi bi-phone"></i></div>
                        <h3><?= t('feature_payment_title', 'Easy payments') ?></h3>
                        <p><?= t('feature_payment_text', 'Support for cash and mobile payment options as they become available.') ?></p>
                    </article>

                </div>

            </div>
        </section>

        <!-- HOW IT WORKS -->
        <section class="section" id="how">
            <div class="container">

                <div class="section-heading">
                    <h2><?= t('how_title', 'How KaryRide works') ?></h2>
                    <p><?= t('how_description', 'Getting where you need to go takes only a few simple steps.') ?></p>
                </div>

                <div class="steps">

                    <article class="step">
                        <div class="step-number">01</div>
                        <div class="step-icon"><i class="bi bi-search"></i></div>
                        <h3><?= t('step_one_title', 'Choose your ride') ?></h3>
                        <p><?= t('step_one_text', 'Enter where you are and where you want to go.') ?></p>
                    </article>

                    <article class="step">
                        <div class="step-number">02</div>
                        <div class="step-icon"><i class="bi bi-person-check-fill"></i></div>
                        <h3><?= t('step_two_title', 'Request a driver') ?></h3>
                        <p><?= t('step_two_text', 'KaryRide finds an available driver for your journey.') ?></p>
                    </article>

                    <article class="step">
                        <div class="step-number">03</div>
                        <div class="step-icon"><i class="bi bi-sign-turn-right-fill"></i></div>
                        <h3><?= t('step_three_title', 'Enjoy your trip') ?></h3>
                        <p><?= t('step_three_text', 'Follow your ride and arrive at your destination.') ?></p>
                    </article>

                </div>

            </div>
        </section>

        <!-- CTA -->
        <section class="cta" id="about">
            <div class="container">

                <div class="cta-box">
                    <h2><?= t('cta_title', 'Ready to move?') ?></h2>
                    <p><?= t('cta_text', 'Create your KaryRide account and start your journey.') ?></p>
                    <?php if ($isLoggedIn): ?>
                        <a href="<?= $dashboardLink ?>" class="btn btn-secondary">
                            <i class="bi bi-speedometer2"></i>
                            <?= t('nav_dashboard', 'Dashboard') ?>
                        </a>
                    <?php else: ?>
                        <a href="pages/register.php" class="btn btn-secondary">
                            <i class="bi bi-person-plus-fill"></i>
                            <?= t('cta_button', 'Create an account') ?>
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </section>

    </main>

    <!-- FOOTER -->
    <footer class="footer">
        <div class="footer-container container">
            <div class="footer-brand">KaryRide</div>
            <p class="footer-text"><?= t('footer_text', 'Smart transportation for everyday journeys.') ?></p>
            <div class="footer-links">
                <a href="pages/login.php"><?= t('nav_login', 'Login') ?></a>
                <a href="pages/register.php"><?= t('nav_register', 'Get started') ?></a>
                <a href="#about"><?= t('nav_about', 'About') ?></a>
            </div>
            <p class="footer-text">© <?= date('Y') ?> KaryRide</p>
        </div>
    </footer>

    <script>
        /* ========================================================
           KARYRIDE V1 - LANDING PAGE JS
        ======================================================== */

        // ========================================================
        // THEME
        // ========================================================

        const themeButton = document.getElementById('themeButton');
        const themeIcon = document.getElementById('themeIcon');
        const themeColorMeta = document.getElementById('themeColorMeta');
        const themeChangeEvent = new Event('karyride:themechange');

        function applyTheme(theme) {
            const dark = theme === 'dark';
            document.body.classList.toggle('dark', dark);
            themeIcon.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
            themeButton.setAttribute('aria-pressed', String(dark));
            if (themeColorMeta) {
                themeColorMeta.setAttribute('content', dark ? '#050a11' : '#f7f9fc');
            }
            document.dispatchEvent(themeChangeEvent);
        }

        const prefersDarkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        const savedTheme = localStorage.getItem('karyride_theme');

        if (savedTheme) {
            applyTheme(savedTheme);
        } else if (prefersDarkQuery && prefersDarkQuery.matches) {
            applyTheme('dark');
        } else {
            applyTheme('light');
        }

        if (prefersDarkQuery) {
            prefersDarkQuery.addEventListener('change', function(event) {
                if (localStorage.getItem('karyride_theme')) return;
                applyTheme(event.matches ? 'dark' : 'light');
            });
        }

        themeButton.addEventListener('click', function() {
            const dark = !document.body.classList.contains('dark');
            const theme = dark ? 'dark' : 'light';
            localStorage.setItem('karyride_theme', theme);
            applyTheme(theme);
        });

        // ========================================================
        // LANGUAGE MENU
        // ========================================================

        const languageButton = document.getElementById('languageButton');
        const languageMenu = document.getElementById('languageMenu');

        function setLanguageMenuOpen(open) {
            languageMenu.classList.toggle('open', open);
            languageButton.setAttribute('aria-expanded', String(open));
        }

        languageButton.addEventListener('click', function(event) {
            event.stopPropagation();
            setLanguageMenuOpen(!languageMenu.classList.contains('open'));
        });

        document.addEventListener('click', function() {
            setLanguageMenuOpen(false);
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                setLanguageMenuOpen(false);
            }
        });

        // ========================================================
        // HERO BACKGROUND — ANIMATED ROUTE MAP
        // ========================================================

        (function() {

            const canvas = document.getElementById('heroCanvas');
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            const heroBackground = canvas.parentElement;
            const reduceMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

            let width = 0;
            let height = 0;
            const dpr = Math.min(window.devicePixelRatio || 1, 2);

            let colors = {
                accent: '#2563eb',
                grid: 'rgba(15, 23, 42, 0.08)'
            };

            function readColors() {
                const styles = getComputedStyle(document.body);
                colors.accent = styles.getPropertyValue('--accent').trim() || colors.accent;
                colors.grid = styles.getPropertyValue('--hero-grid').trim() || colors.grid;
            }

            let routes = [];

            function buildRoutes() {
                routes = [{
                    points: [
                        { x: 0.00, y: 0.32 },
                        { x: 0.28, y: 0.16 },
                        { x: 0.55, y: 0.40 },
                        { x: 0.88, y: 0.20 }
                    ],
                    progress: 0.10,
                    speed: 0.00028
                }, {
                    points: [
                        { x: 0.98, y: 0.66 },
                        { x: 0.70, y: 0.80 },
                        { x: 0.46, y: 0.54 },
                        { x: 0.08, y: 0.74 }
                    ],
                    progress: 0.55,
                    speed: 0.00022
                }, {
                    points: [
                        { x: 0.14, y: 0.94 },
                        { x: 0.40, y: 0.70 },
                        { x: 0.64, y: 0.88 },
                        { x: 0.92, y: 0.62 }
                    ],
                    progress: 0.82,
                    speed: 0.00019
                }];
            }

            function pointOnRoute(points, t) {
                const segments = points.length - 1;
                const scaled = t * segments;
                let index = Math.floor(scaled);
                if (index >= segments) index = segments - 1;
                if (index < 0) index = 0;
                const localT = scaled - index;
                const p0 = points[index];
                const p1 = points[index + 1];
                return {
                    x: p0.x + (p1.x - p0.x) * localT,
                    y: p0.y + (p1.y - p0.y) * localT
                };
            }

            function pathThroughRoute(points) {
                ctx.beginPath();
                points.forEach(function(point, index) {
                    const x = point.x * width;
                    const y = point.y * height;
                    if (index === 0) ctx.moveTo(x, y);
                    else ctx.lineTo(x, y);
                });
            }

            function drawRoute(route) {
                pathThroughRoute(route.points);
                ctx.setLineDash([]);
                ctx.lineWidth = 1;
                ctx.strokeStyle = colors.grid;
                ctx.stroke();

                pathThroughRoute(route.points);
                ctx.setLineDash([6, 10]);
                ctx.lineWidth = 1.5;
                ctx.strokeStyle = colors.accent;
                ctx.globalAlpha = 0.32;
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.globalAlpha = 1;

                [route.points[0], route.points[route.points.length - 1]].forEach(function(point) {
                    const x = point.x * width;
                    const y = point.y * height;
                    ctx.beginPath();
                    ctx.arc(x, y, 4, 0, Math.PI * 2);
                    ctx.fillStyle = colors.accent;
                    ctx.globalAlpha = 0.45;
                    ctx.fill();
                    ctx.globalAlpha = 1;
                });

                const position = pointOnRoute(route.points, route.progress);
                const x = position.x * width;
                const y = position.y * height;

                const glow = ctx.createRadialGradient(x, y, 0, x, y, 16);
                glow.addColorStop(0, colors.accent);
                glow.addColorStop(1, 'transparent');
                ctx.beginPath();
                ctx.arc(x, y, 16, 0, Math.PI * 2);
                ctx.fillStyle = glow;
                ctx.fill();

                ctx.beginPath();
                ctx.arc(x, y, 4, 0, Math.PI * 2);
                ctx.fillStyle = colors.accent;
                ctx.fill();
            }

            function renderFrame() {
                ctx.clearRect(0, 0, width, height);
                routes.forEach(drawRoute);
            }

            let rafId = null;
            let lastTime = 0;

            function tick(time) {
                if (!lastTime) lastTime = time;
                const delta = time - lastTime;
                lastTime = time;
                routes.forEach(function(route) {
                    route.progress += route.speed * delta;
                    if (route.progress > 1) route.progress -= 1;
                });
                renderFrame();
                rafId = requestAnimationFrame(tick);
            }

            function stopAnimation() {
                if (rafId) {
                    cancelAnimationFrame(rafId);
                    rafId = null;
                }
            }

            function startAnimation() {
                stopAnimation();
                lastTime = 0;
                rafId = requestAnimationFrame(tick);
            }

            function applyMotionPreference() {
                if (reduceMotionQuery.matches) {
                    stopAnimation();
                    renderFrame();
                } else {
                    startAnimation();
                }
            }

            function resize() {
                const rect = heroBackground.getBoundingClientRect();
                width = rect.width;
                height = rect.height;
                canvas.width = Math.round(width * dpr);
                canvas.height = Math.round(height * dpr);
                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                buildRoutes();
                renderFrame();
            }

            window.addEventListener('resize', resize);
            document.addEventListener('karyride:themechange', function() {
                readColors();
                if (reduceMotionQuery.matches) renderFrame();
            });

            if (reduceMotionQuery.addEventListener) {
                reduceMotionQuery.addEventListener('change', applyMotionPreference);
            }

            readColors();
            resize();
            applyMotionPreference();

        })();

        // ========================================================
        // FIX 2: GEOLOCATION - SAFE
        // ========================================================

        const pickupLocation = document.getElementById('pickupLocation');

        function setLocationText(text) {
            if (!pickupLocation) return;
            pickupLocation.textContent = text;
        }

        if (pickupLocation && 'geolocation' in navigator) {

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    setLocationText(
                        position.coords.latitude.toFixed(4) + ', ' +
                        position.coords.longitude.toFixed(4)
                    );
                },
                function() {
                    setLocationText('<?= t('location_unavailable', 'Location unavailable') ?>');
                }, {
                    enableHighAccuracy: true,
                    timeout: 8000,
                    maximumAge: 60000
                }
            );

        } else if (pickupLocation) {

            setLocationText('<?= t('gps_unavailable', 'GPS unavailable') ?>');
        }

        // ========================================================
        // FIX 3: FIND RIDE - SAFE
        // ========================================================

        const findRideButton = document.getElementById('findRideButton');

        function goToBooking() {

            <?php if ($isLoggedIn): ?>
                window.location.href = <?= json_encode($dashboardLink) ?>;
            <?php else: ?>
                window.location.href =
                    'pages/login.php?redirect=' +
                    encodeURIComponent('pages/client.php');
            <?php endif; ?>
        }

        if (findRideButton) {
            findRideButton.addEventListener('click', goToBooking);
        }

        // ========================================================
        // FIX 4: SEARCH FIELDS - SAFE + KEYBOARD
        // ========================================================

        document.querySelectorAll('.search-field').forEach(function(field) {

            field.addEventListener('click', goToBooking);

            field.addEventListener('keydown', function(event) {

                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    goToBooking();
                }

            });

        });

        // ========================================================
        // SMOOTH SCROLL
        // ========================================================

        document.querySelectorAll('.nav-links a').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                const target = document.querySelector(targetId);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // ========================================================
        // STATS ANIMATION
        // ========================================================

        (function() {
            const statElements = document.querySelectorAll('.stat-number');
            const hasRealData = <?= $stats['rides_today'] > 0 ? 'true' : 'false' ?>;

            if (!hasRealData) return;

            const duration = 800;
            const stepTime = 16;

            statElements.forEach(function(el) {
                const target = parseInt(el.textContent.replace(/,/g, '')) || 0;
                const start = 0;
                const steps = duration / stepTime;
                let currentStep = 0;

                const interval = setInterval(function() {
                    currentStep++;
                    const progress = currentStep / steps;
                    const value = Math.round(start + (target - start) * progress);
                    el.textContent = value.toLocaleString();

                    if (currentStep >= steps) {
                        el.textContent = target.toLocaleString();
                        clearInterval(interval);
                    }
                }, stepTime);
            });
        })();
    </script>

</body>

</html>