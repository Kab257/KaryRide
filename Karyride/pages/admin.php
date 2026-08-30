<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KaryRide V1 — Super Admin
|--------------------------------------------------------------------------
| Self-contained admin interface.
|
| Page responsibilities:
| - Authentication / authorization
| - Dashboard UI
| - Navigation
| - Tables / filters / forms / modals
| - Embedded CSS
| - Embedded JavaScript
|
| Database mutations are delegated to domain APIs:
| - company.php
| - driver.php
| - vehicle.php
| - user.php
| - ride.php
| - payment.php
| - category.php
| - promotion.php
| - review.php
| - report.php
| - settings.php
|--------------------------------------------------------------------------
*/

$session = require_once __DIR__ . '/auth.php';

if (($session['role'] ?? '') !== 'superadmin') {
    header('Location: login.php');
    exit;
}

$adminId    = (int)($session['user_id'] ?? 0);
$firstName  = (string)($session['first_name'] ?? '');
$lastName   = (string)($session['last_name'] ?? '');
$email      = (string)($session['email'] ?? '');
$phone      = (string)($session['phone'] ?? '');

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

function adminT(string $key, string $fallback = ''): string
{
    global $translations;

    return htmlspecialchars(
        (string)($translations[$key] ?? $fallback),
        ENT_QUOTES,
        'UTF-8'
    );
}

$initials = strtoupper(
    substr($firstName ?: 'A', 0, 1) .
    substr($lastName ?: '', 0, 1)
);

$db = null;

try {
    $databaseFile = __DIR__ . '/../config/database.php';

    if (is_file($databaseFile)) {
        $db = require $databaseFile;
    }
} catch (Throwable $e) {
    error_log(
        '[' . date('Y-m-d H:i:s') . '] Admin DB error: ' .
        $e->getMessage() . PHP_EOL,
        3,
        __DIR__ . '/../logs/errors.log'
    );
}

/*
|--------------------------------------------------------------------------
| Initial dashboard statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'users'       => 0,
    'clients'     => 0,
    'companies'   => 0,
    'pending_companies' => 0,
    'drivers'     => 0,
    'vehicles'    => 0,
    'rides'       => 0,
    'active_rides'=> 0,
    'completed_rides' => 0,
    'revenue'     => 0,
    'reviews'     => 0,
    'pending_docs'=> 0,
];

if ($db instanceof mysqli) {
    $queries = [
        'users' => "SELECT COUNT(*) AS total FROM users WHERE role <> 'superadmin'",
        'clients' => "SELECT COUNT(*) AS total FROM users WHERE role = 'client'",
        'companies' => "SELECT COUNT(*) AS total FROM companies",
        'pending_companies' => "SELECT COUNT(*) AS total FROM companies WHERE status = 'pending'",
        'drivers' => "SELECT COUNT(*) AS total FROM drivers",
        'vehicles' => "SELECT COUNT(*) AS total FROM vehicles",
        'rides' => "SELECT COUNT(*) AS total FROM rides",
        'active_rides' => "
            SELECT COUNT(*) AS total
            FROM rides
            WHERE status IN (
                'pending',
                'accepted',
                'driver_arrived',
                'in_progress',
                'destination_reached'
            )
        ",
        'completed_rides' => "
            SELECT COUNT(*) AS total
            FROM rides
            WHERE status IN ('completed','paid')
        ",
        'revenue' => "
            SELECT COALESCE(SUM(total_amount),0) AS total
            FROM rides
            WHERE status IN ('completed','paid')
        ",
        'reviews' => "SELECT COUNT(*) AS total FROM reviews",
        'pending_docs' => "
            SELECT COUNT(*) AS total
            FROM uploaded_documents
            WHERE verified_status = 'pending'
        ",
    ];

    foreach ($queries as $key => $sql) {
        try {
            $result = $db->query($sql);

            if ($result) {
                $row = $result->fetch_assoc();
                $stats[$key] = $key === 'revenue'
                    ? (float)($row['total'] ?? 0)
                    : (int)($row['total'] ?? 0);
            }
        } catch (Throwable $e) {
            error_log(
                '[' . date('Y-m-d H:i:s') . '] Admin stat error: ' .
                $e->getMessage() . PHP_EOL,
                3,
                __DIR__ . '/../logs/errors.log'
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Recent companies
|--------------------------------------------------------------------------
*/

$recentCompanies = [];

if ($db instanceof mysqli) {
    try {
        $sql = "
            SELECT
                c.id,
                c.company_name,
                c.company_code,
                c.phone,
                c.email,
                c.city,
                c.status,
                c.created_at,
                u.first_name,
                u.last_name
            FROM companies c
            LEFT JOIN users u
                ON u.id = c.owner_user_id
            ORDER BY c.created_at DESC
            LIMIT 8
        ";

        $result = $db->query($sql);

        if ($result) {
            $recentCompanies = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Throwable $e) {
        error_log(
            '[' . date('Y-m-d H:i:s') . '] Recent company error: ' .
            $e->getMessage() . PHP_EOL,
            3,
            __DIR__ . '/../logs/errors.log'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Recent rides
|--------------------------------------------------------------------------
*/

$recentRides = [];

if ($db instanceof mysqli) {
    try {
        $sql = "
            SELECT
                r.id,
                r.ride_reference,
                r.status,
                r.total_amount,
                r.created_at,
                r.pickup_address,
                r.destination_address,
                u.first_name AS client_first_name,
                u.last_name AS client_last_name,
                c.company_name
            FROM rides r
            LEFT JOIN users u
                ON u.id = r.client_id
            LEFT JOIN companies c
                ON c.id = r.company_id
            ORDER BY r.created_at DESC
            LIMIT 8
        ";

        $result = $db->query($sql);

        if ($result) {
            $recentRides = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Throwable $e) {
        error_log(
            '[' . date('Y-m-d H:i:s') . '] Recent ride error: ' .
            $e->getMessage() . PHP_EOL,
            3,
            __DIR__ . '/../logs/errors.log'
        );
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">

<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">

<meta name="theme-color" content="#ffffff">

<title>KaryRide — Super Admin</title>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
:root{
    --bg:#f5f7fb;
    --surface:#ffffff;
    --surface-2:#f0f3f7;
    --surface-3:#e9edf3;

    --text:#111827;
    --muted:#667085;

    --border:#e4e8ef;

    --primary:#111827;
    --accent:#2563eb;
    --accent-soft:#eaf1ff;

    --success:#16a34a;
    --success-soft:rgba(22,163,74,.11);

    --danger:#dc2626;
    --danger-soft:rgba(220,38,38,.10);

    --warning:#f59e0b;
    --warning-soft:rgba(245,158,11,.12);

    --info:#0891b2;
    --info-soft:rgba(8,145,178,.11);

    --shadow:0 18px 50px rgba(15,23,42,.10);

    --radius:16px;
}

body.dark{
    --bg:#07101a;
    --surface:#0d1723;
    --surface-2:#142131;
    --surface-3:#1a2a3c;

    --text:#f5f7fa;
    --muted:#9aa8b9;

    --border:#203044;

    --primary:#f5f7fa;
    --accent:#60a5fa;
    --accent-soft:rgba(96,165,250,.13);

    --success:#4ade80;
    --success-soft:rgba(74,222,128,.12);

    --danger:#f87171;
    --danger-soft:rgba(248,113,113,.12);

    --warning:#fbbf24;
    --warning-soft:rgba(251,191,36,.12);

    --info:#22d3ee;
    --info-soft:rgba(34,211,238,.12);

    --shadow:0 18px 50px rgba(0,0,0,.30);
}

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
}

html{
    scroll-behavior:smooth;
}

body{
    min-height:100vh;
    background:var(--bg);
    color:var(--text);
    font-family:
        Inter,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

button,
input,
select,
textarea{
    font:inherit;
}

button{
    cursor:pointer;
}

a{
    color:inherit;
    text-decoration:none;
}

button:disabled{
    opacity:.5;
    cursor:not-allowed;
}

/* ============================================================
   TOPBAR
============================================================ */

.topbar{
    height:68px;

    position:fixed;
    inset:0 0 auto 0;

    z-index:3000;

    background:color-mix(
        in srgb,
        var(--surface) 94%,
        transparent
    );

    backdrop-filter:blur(18px);

    border-bottom:1px solid var(--border);
}

.topbar-inner{
    height:100%;

    width:min(1500px,calc(100% - 28px));

    margin:auto;

    display:flex;
    align-items:center;
    justify-content:space-between;

    gap:16px;
}

.brand{
    display:flex;
    align-items:center;
    gap:10px;

    font-size:20px;
    font-weight:850;
    letter-spacing:-.4px;
}

.brand-mark{
    width:38px;
    height:38px;

    border-radius:11px;

    background:var(--primary);
    color:var(--surface);

    display:grid;
    place-items:center;
}

.top-actions{
    display:flex;
    align-items:center;
    gap:6px;
}

.top-button{
    min-height:40px;

    padding:0 10px;

    border:0;
    border-radius:10px;

    background:transparent;
    color:var(--muted);

    display:flex;
    align-items:center;
    gap:8px;

    font-size:13px;
    font-weight:700;
}

.top-button:hover{
    background:var(--surface-2);
    color:var(--text);
}

.icon-only{
    width:40px;
    justify-content:center;
    padding:0;
}

.avatar{
    width:34px;
    height:34px;

    border-radius:50%;

    background:var(--accent-soft);
    color:var(--accent);

    display:grid;
    place-items:center;

    font-size:11px;
    font-weight:850;
}

.admin-label{
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    line-height:1.1;
}

.admin-label strong{
    font-size:12px;
    color:var(--text);
}

.admin-label span{
    margin-top:3px;
    font-size:9px;
    color:var(--muted);
}

.dropdown{
    position:relative;
}

.dropdown-panel{
    position:absolute;

    right:0;
    top:48px;

    width:245px;

    background:var(--surface);

    border:1px solid var(--border);
    border-radius:14px;

    padding:7px;

    box-shadow:var(--shadow);

    display:none;
}

.dropdown-panel.open{
    display:block;
}

.dropdown-head{
    padding:11px;

    border-bottom:1px solid var(--border);

    margin-bottom:5px;
}

.dropdown-head strong{
    display:block;
    font-size:14px;
}

.dropdown-head span{
    display:block;

    color:var(--muted);

    font-size:11px;

    margin-top:3px;
}

.dropdown-item{
    display:flex;
    align-items:center;
    gap:11px;

    padding:10px 11px;

    border-radius:9px;

    font-size:13px;
    font-weight:600;
}

.dropdown-item:hover{
    background:var(--surface-2);
}

.dropdown-item i{
    width:18px;
    color:var(--muted);
}

.dropdown-item.danger,
.dropdown-item.danger i{
    color:var(--danger);
}

.mobile-menu-button{
    display:none;
}

/* ============================================================
   SHELL
============================================================ */

.shell{
    min-height:100vh;
    padding-top:68px;
}

.sidebar{
    position:fixed;

    top:68px;
    left:0;
    bottom:0;

    width:255px;

    background:var(--surface);

    border-right:1px solid var(--border);

    padding:17px 12px;

    overflow-y:auto;

    z-index:2500;
}

.sidebar-section{
    margin:10px 7px 7px;

    color:var(--muted);

    font-size:9px;
    font-weight:850;

    letter-spacing:.8px;

    text-transform:uppercase;
}

.nav-item{
    width:100%;

    border:0;
    background:transparent;

    color:var(--muted);

    padding:11px 12px;

    border-radius:11px;

    display:flex;
    align-items:center;

    gap:11px;

    text-align:left;

    font-size:12px;
    font-weight:700;

    margin-bottom:3px;

    transition:.15s;
}

.nav-item:hover{
    background:var(--surface-2);
    color:var(--text);
}

.nav-item.active{
    background:var(--accent-soft);
    color:var(--accent);
}

.nav-item i{
    width:19px;

    font-size:16px;

    text-align:center;
}

.nav-badge{
    margin-left:auto;

    min-width:19px;
    height:19px;

    padding:0 5px;

    border-radius:999px;

    background:var(--danger);
    color:#fff;

    display:grid;
    place-items:center;

    font-size:9px;
    font-weight:850;
}

.sidebar-footer{
    margin-top:20px;

    padding:10px 7px;

    border-top:1px solid var(--border);

    color:var(--muted);

    font-size:9px;

    line-height:1.5;
}

/* ============================================================
   MAIN
============================================================ */

.main{
    margin-left:255px;

    padding:25px;

    width:calc(100% - 255px);
}

.page{
    width:min(1450px,100%);

    margin:auto;
}

.page-section{
    display:none;
}

.page-section.active{
    display:block;
}

/* ============================================================
   PAGE HEADER
============================================================ */

.page-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;

    gap:20px;

    margin-bottom:22px;
}

.page-title h1{
    font-size:27px;

    letter-spacing:-.8px;

    font-weight:850;
}

.page-title p{
    margin-top:5px;

    color:var(--muted);

    font-size:12px;
}

.header-actions{
    display:flex;
    align-items:center;

    gap:8px;
}

/* ============================================================
   BUTTONS
============================================================ */

.btn{
    min-height:40px;

    border:1px solid var(--border);

    border-radius:10px;

    padding:0 13px;

    background:var(--surface);

    color:var(--text);

    display:inline-flex;
    align-items:center;
    justify-content:center;

    gap:7px;

    font-size:11px;
    font-weight:750;

    transition:.15s;
}

.btn:hover{
    border-color:var(--accent);
}

.btn-primary{
    background:var(--accent);
    border-color:var(--accent);
    color:#fff;
}

.btn-success{
    background:var(--success);
    border-color:var(--success);
    color:#fff;
}

.btn-danger{
    background:var(--danger);
    border-color:var(--danger);
    color:#fff;
}

.btn-warning{
    background:var(--warning);
    border-color:var(--warning);
    color:#111;
}

.btn-soft{
    background:var(--surface-2);
}

.btn-sm{
    min-height:32px;
    padding:0 9px;

    font-size:10px;
}

/* ============================================================
   STAT CARDS
============================================================ */

.stats-grid{
    display:grid;

    grid-template-columns:
        repeat(4,minmax(0,1fr));

    gap:13px;

    margin-bottom:20px;
}

.stat-card{
    background:var(--surface);

    border:1px solid var(--border);

    border-radius:15px;

    padding:17px;

    box-shadow:0 5px 20px rgba(15,23,42,.035);
}

.stat-top{
    display:flex;
    align-items:center;
    justify-content:space-between;

    gap:10px;
}

.stat-icon{
    width:39px;
    height:39px;

    border-radius:11px;

    background:var(--accent-soft);
    color:var(--accent);

    display:grid;
    place-items:center;

    font-size:17px;
}

.stat-change{
    font-size:9px;
    font-weight:800;

    padding:4px 7px;

    border-radius:999px;
}

.stat-change.green{
    background:var(--success-soft);
    color:var(--success);
}

.stat-change.orange{
    background:var(--warning-soft);
    color:var(--warning);
}

.stat-change.red{
    background:var(--danger-soft);
    color:var(--danger);
}

.stat-card h3{
    margin-top:15px;

    font-size:25px;
    letter-spacing:-.7px;
}

.stat-card p{
    margin-top:3px;

    color:var(--muted);

    font-size:10px;
    font-weight:650;
}

/* ============================================================
   CARDS
============================================================ */

.card{
    background:var(--surface);

    border:1px solid var(--border);

    border-radius:15px;

    overflow:hidden;
}

.card-header{
    padding:15px 17px;

    display:flex;
    align-items:center;
    justify-content:space-between;

    gap:15px;

    border-bottom:1px solid var(--border);
}

.card-title{
    font-size:13px;
    font-weight:850;
}

.card-subtitle{
    margin-top:3px;

    color:var(--muted);

    font-size:10px;
}

.card-body{
    padding:17px;
}

/* ============================================================
   DASHBOARD GRID
============================================================ */

.dashboard-grid{
    display:grid;

    grid-template-columns:
        minmax(0,1.35fr)
        minmax(320px,.65fr);

    gap:15px;
}

/* ============================================================
   TABLE
============================================================ */

.table-wrap{
    width:100%;
    overflow-x:auto;
}

.data-table{
    width:100%;

    border-collapse:collapse;

    min-width:750px;
}

.data-table th{
    padding:10px 13px;

    background:var(--surface-2);

    color:var(--muted);

    text-align:left;

    font-size:9px;
    font-weight:850;

    text-transform:uppercase;

    letter-spacing:.4px;
}

.data-table td{
    padding:12px 13px;

    border-top:1px solid var(--border);

    font-size:11px;

    vertical-align:middle;
}

.data-table tr:hover td{
    background:color-mix(
        in srgb,
        var(--surface-2) 45%,
        transparent
    );
}

.person{
    display:flex;
    align-items:center;

    gap:9px;
}

.person-avatar{
    width:32px;
    height:32px;

    border-radius:9px;

    background:var(--accent-soft);
    color:var(--accent);

    display:grid;
    place-items:center;

    font-size:10px;
    font-weight:850;

    flex:none;
}

.person strong{
    display:block;

    font-size:11px;
}

.person span{
    display:block;

    margin-top:2px;

    color:var(--muted);

    font-size:9px;
}

.actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;

    gap:5px;
}

.action{
    width:30px;
    height:30px;

    border:1px solid var(--border);

    border-radius:8px;

    background:var(--surface);

    color:var(--muted);

    display:grid;
    place-items:center;
}

.action:hover{
    color:var(--accent);
    border-color:var(--accent);
}

.action.danger:hover{
    color:var(--danger);
    border-color:var(--danger);
}

/* ============================================================
   STATUS
============================================================ */

.status{
    display:inline-flex;

    align-items:center;

    gap:5px;

    padding:4px 8px;

    border-radius:999px;

    font-size:9px;

    font-weight:850;

    text-transform:capitalize;
}

.status::before{
    content:"";

    width:5px;
    height:5px;

    border-radius:50%;

    background:currentColor;
}

.status-approved,
.status-active,
.status-completed,
.status-paid,
.status-available{
    color:var(--success);
    background:var(--success-soft);
}

.status-pending,
.status-accepted,
.status-driver_arrived,
.status-in_progress{
    color:var(--warning);
    background:var(--warning-soft);
}

.status-rejected,
.status-suspended,
.status-cancelled,
.status-offline{
    color:var(--danger);
    background:var(--danger-soft);
}

.status-destination_reached{
    color:var(--info);
    background:var(--info-soft);
}

/* ============================================================
   FILTER BAR
============================================================ */

.toolbar{
    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:10px;

    flex-wrap:wrap;

    padding:13px;

    border-bottom:1px solid var(--border);
}

.filters{
    display:flex;

    align-items:center;

    gap:7px;

    flex-wrap:wrap;
}

.search{
    position:relative;

    width:260px;
}

.search i{
    position:absolute;

    left:11px;
    top:50%;

    transform:translateY(-50%);

    color:var(--muted);

    pointer-events:none;
}

.input,
.select,
.textarea{
    width:100%;

    min-height:39px;

    border:1px solid var(--border);

    border-radius:9px;

    background:var(--surface-2);

    color:var(--text);

    outline:none;

    padding:0 11px;

    font-size:11px;
}

.search .input{
    padding-left:33px;
}

.textarea{
    min-height:100px;

    padding:10px;

    resize:vertical;
}

.input:focus,
.select:focus,
.textarea:focus{
    border-color:var(--accent);

    box-shadow:
        0 0 0 3px var(--accent-soft);
}

/* ============================================================
   MODAL
============================================================ */

.modal-backdrop{
    position:fixed;

    inset:0;

    z-index:5000;

    background:rgba(2,8,23,.58);

    backdrop-filter:blur(5px);

    display:none;

    align-items:center;
    justify-content:center;

    padding:18px;
}

.modal-backdrop.open{
    display:flex;
}

.modal{
    width:min(700px,100%);

    max-height:calc(100vh - 36px);

    overflow:auto;

    background:var(--surface);

    border:1px solid var(--border);

    border-radius:17px;

    box-shadow:var(--shadow);
}

.modal-header{
    display:flex;

    align-items:center;
    justify-content:space-between;

    gap:15px;

    padding:15px 17px;

    border-bottom:1px solid var(--border);
}

.modal-header h3{
    font-size:15px;
    font-weight:850;
}

.modal-close{
    width:34px;
    height:34px;

    border:0;

    border-radius:9px;

    background:var(--surface-2);

    color:var(--muted);
}

.modal-body{
    padding:17px;
}

.modal-footer{
    padding:13px 17px;

    border-top:1px solid var(--border);

    display:flex;

    justify-content:flex-end;

    gap:7px;
}

.form-grid{
    display:grid;

    grid-template-columns:
        repeat(2,minmax(0,1fr));

    gap:12px;
}

.form-group{
    display:flex;
    flex-direction:column;

    gap:5px;
}

.form-group.full{
    grid-column:1/-1;
}

.form-group label{
    color:var(--muted);

    font-size:9px;

    font-weight:850;

    text-transform:uppercase;

    letter-spacing:.4px;
}

/* ============================================================
   EMPTY
============================================================ */

.empty{
    padding:40px 20px;

    text-align:center;

    color:var(--muted);
}

.empty i{
    font-size:28px;

    display:block;

    margin-bottom:10px;
}

.empty strong{
    display:block;

    color:var(--text);

    font-size:12px;
}

.empty span{
    display:block;

    margin-top:4px;

    font-size:10px;
}

/* ============================================================
   TOAST
============================================================ */

.toast{
    position:fixed;

    right:20px;
    bottom:20px;

    z-index:7000;

    min-width:260px;
    max-width:420px;

    background:var(--surface);

    border:1px solid var(--border);

    border-radius:12px;

    box-shadow:var(--shadow);

    padding:12px 14px;

    display:none;

    align-items:center;

    gap:10px;

    font-size:11px;
    font-weight:700;
}

.toast.show{
    display:flex;
}

.toast.success{
    border-left:4px solid var(--success);
}

.toast.error{
    border-left:4px solid var(--danger);
}

.toast.warning{
    border-left:4px solid var(--warning);
}

/* ============================================================
   LOADING
============================================================ */

.loading{
    position:relative;
    pointer-events:none;
}

.loading::after{
    content:"";

    width:15px;
    height:15px;

    border:2px solid currentColor;
    border-right-color:transparent;

    border-radius:50%;

    animation:spin .7s linear infinite;

    position:absolute;

    right:10px;
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

/* ============================================================
   MOBILE
============================================================ */

@media(max-width:1100px){

    .stats-grid{
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }

    .dashboard-grid{
        grid-template-columns:1fr;
    }

}

@media(max-width:850px){

    .topbar{
        height:60px;
    }

    .topbar-inner{
        width:calc(100% - 16px);
    }

    .admin-label{
        display:none;
    }

    .mobile-menu-button{
        display:flex;
    }

    .sidebar{
        top:60px;

        width:280px;

        transform:translateX(-100%);

        transition:.2s;

        box-shadow:var(--shadow);
    }

    .sidebar.open{
        transform:translateX(0);
    }

    .shell{
        padding-top:60px;
    }

    .main{
        margin-left:0;

        width:100%;

        padding:17px 12px;
    }

    .page-header{
        flex-direction:column;

        align-items:stretch;
    }

    .header-actions{
        justify-content:flex-start;
    }

}

@media(max-width:560px){

    .stats-grid{
        grid-template-columns:1fr;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }

    .page-title h1{
        font-size:23px;
    }

    .search{
        width:100%;
    }

    .toolbar{
        align-items:stretch;
    }

    .filters{
        width:100%;
    }

    .filters .select{
        flex:1;
    }

    .toast{
        right:10px;
        left:10px;

        min-width:0;
    }

}
</style>
</head>

<body>

<header class="topbar">

    <div class="topbar-inner">

        <a class="brand" href="../index.php">

            <span class="brand-mark">
                <i class="bi bi-sign-turn-right-fill"></i>
            </span>

            <span>KaryRide</span>

        </a>

        <div class="top-actions">

            <button
                class="top-button icon-only"
                id="themeBtn"
                type="button"
                aria-label="Toggle theme"
            >
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>

            <div class="dropdown">

                <button
                    class="top-button"
                    id="profileBtn"
                    type="button"
                >

                    <span class="avatar">
                        <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <span class="admin-label">

                        <strong>
                            <?= htmlspecialchars(
                                trim($firstName . ' ' . $lastName),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>

                        <span>Super Admin</span>

                    </span>

                    <i class="bi bi-chevron-down"></i>

                </button>

                <div class="dropdown-panel" id="profilePanel">

                    <div class="dropdown-head">

                        <strong>
                            <?= htmlspecialchars(
                                trim($firstName . ' ' . $lastName),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>

                        <span>
                            <?= htmlspecialchars(
                                $email,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </span>

                    </div>

                    <button
                        class="dropdown-item"
                        type="button"
                        data-page="profile"
                    >
                        <i class="bi bi-person"></i>
                        Admin Profile
                    </button>

                    <button
                        class="dropdown-item"
                        type="button"
                        data-page="settings"
                    >
                        <i class="bi bi-gear"></i>
                        System Settings
                    </button>

                    <a
                        class="dropdown-item danger"
                        href="logout.php"
                    >
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </a>

                </div>

            </div>

            <div class="dropdown">

                <button
                    class="top-button icon-only"
                    id="languageBtn"
                    type="button"
                    aria-label="Language"
                >
                    <i class="bi bi-translate"></i>
                </button>

                <div
                    class="dropdown-panel"
                    id="languagePanel"
                    style="width:145px"
                >

                    <a
                        class="dropdown-item"
                        href="?lang=en"
                    >
                        <i class="bi bi-translate"></i>
                        English
                    </a>

                    <a
                        class="dropdown-item"
                        href="?lang=rn"
                    >
                        <i class="bi bi-translate"></i>
                        Kirundi
                    </a>

                </div>

            </div>

            <button
                class="top-button icon-only mobile-menu-button"
                id="mobileMenuBtn"
                type="button"
            >
                <i class="bi bi-list" id="mobileMenuIcon"></i>
            </button>

        </div>

    </div>

</header>


<div class="shell">

<aside class="sidebar" id="sidebar">

    <div class="sidebar-section">Overview</div>

    <button
        class="nav-item active"
        data-page="dashboard"
    >
        <i class="bi bi-grid-1x2-fill"></i>
        Dashboard
    </button>

    <div class="sidebar-section">Platform</div>

    <button class="nav-item" data-page="companies">
        <i class="bi bi-buildings"></i>
        Companies

        <?php if ($stats['pending_companies'] > 0): ?>
            <span class="nav-badge">
                <?= $stats['pending_companies'] ?>
            </span>
        <?php endif; ?>
    </button>

    <button class="nav-item" data-page="users">
        <i class="bi bi-people"></i>
        Users
    </button>

    <button class="nav-item" data-page="drivers">
        <i class="bi bi-person-badge"></i>
        Drivers
    </button>

    <button class="nav-item" data-page="vehicles">
        <i class="bi bi-car-front"></i>
        Vehicles
    </button>

    <button class="nav-item" data-page="rides">
        <i class="bi bi-sign-turn-right"></i>
        Rides

        <?php if ($stats['active_rides'] > 0): ?>
            <span class="nav-badge">
                <?= $stats['active_rides'] ?>
            </span>
        <?php endif; ?>
    </button>

    <div class="sidebar-section">Finance</div>

    <button class="nav-item" data-page="payments">
        <i class="bi bi-credit-card"></i>
        Payments
    </button>

    <button class="nav-item" data-page="promotions">
        <i class="bi bi-tags"></i>
        Promotions
    </button>

    <div class="sidebar-section">Configuration</div>

    <button class="nav-item" data-page="categories">
        <i class="bi bi-diagram-3"></i>
        Categories & Pricing
    </button>

    <button class="nav-item" data-page="documents">
        <i class="bi bi-file-earmark-check"></i>
        Documents

        <?php if ($stats['pending_docs'] > 0): ?>
            <span class="nav-badge">
                <?= $stats['pending_docs'] ?>
            </span>
        <?php endif; ?>
    </button>

    <button class="nav-item" data-page="reviews">
        <i class="bi bi-star"></i>
        Reviews
    </button>

    <div class="sidebar-section">Insights</div>

    <button class="nav-item" data-page="reports">
        <i class="bi bi-bar-chart"></i>
        Reports
    </button>

    <div class="sidebar-section">System</div>

    <button class="nav-item" data-page="settings">
        <i class="bi bi-gear"></i>
        Settings
    </button>

    <button class="nav-item" data-page="profile">
        <i class="bi bi-person-circle"></i>
        Admin Profile
    </button>

    <div class="sidebar-footer">
        KaryRide V1<br>
        Super Admin Console
    </div>

</aside>


<main class="main">

<div class="page">

<!-- =========================================================
     DASHBOARD
========================================================= -->

<section
    class="page-section active"
    data-section="dashboard"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Dashboard</h1>

            <p>
                Platform overview and real-time operational statistics.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-soft"
                id="dashboardRefresh"
            >
                <i class="bi bi-arrow-repeat"></i>
                Refresh
            </button>

        </div>

    </div>


    <div class="stats-grid">

        <div class="stat-card">

            <div class="stat-top">

                <span class="stat-icon">
                    <i class="bi bi-buildings"></i>
                </span>

                <?php if ($stats['pending_companies']): ?>
                    <span class="stat-change orange">
                        <?= $stats['pending_companies'] ?> pending
                    </span>
                <?php endif; ?>

            </div>

            <h3><?= number_format($stats['companies']) ?></h3>

            <p>Total Companies</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span class="stat-icon">
                    <i class="bi bi-people"></i>
                </span>

            </div>

            <h3><?= number_format($stats['users']) ?></h3>

            <p>Platform Users</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span class="stat-icon">
                    <i class="bi bi-person-badge"></i>
                </span>

            </div>

            <h3><?= number_format($stats['drivers']) ?></h3>

            <p>Drivers</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span class="stat-icon">
                    <i class="bi bi-car-front"></i>
                </span>

            </div>

            <h3><?= number_format($stats['vehicles']) ?></h3>

            <p>Vehicles</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span class="stat-icon">
                    <i class="bi bi-sign-turn-right"></i>
                </span>

                <span class="stat-change green">
                    <?= number_format($stats['active_rides']) ?> active
                </span>

            </div>

            <h3><?= number_format($stats['rides']) ?></h3>

            <p>Total Rides</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span class="stat-icon">
                    <i class="bi bi-check-circle"></i>
                </span>

            </div>

            <h3><?= number_format($stats['completed_rides']) ?></h3>

            <p>Completed Rides</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span class="stat-icon">
                    <i class="bi bi-cash-stack"></i>
                </span>

            </div>

            <h3>
                <?= number_format(
                    (float)$stats['revenue'],
                    0,
                    '.',
                    ','
                ) ?>
            </h3>

            <p>Ride Revenue · BIF</p>

        </div>


        <div class="stat-card">

            <div class="stat-top">

                <span class="stat-icon">
                    <i class="bi bi-star-fill"></i>
                </span>

            </div>

            <h3><?= number_format($stats['reviews']) ?></h3>

            <p>Reviews</p>

        </div>

    </div>


    <div class="dashboard-grid">

        <div class="card">

            <div class="card-header">

                <div>

                    <div class="card-title">
                        Recent Companies
                    </div>

                    <div class="card-subtitle">
                        Latest company registrations and approvals
                    </div>

                </div>

                <button
                    class="btn btn-sm"
                    data-page="companies"
                >
                    View all
                </button>

            </div>

            <div class="table-wrap">

                <?php if ($recentCompanies): ?>

                <table class="data-table">

                    <thead>

                    <tr>
                        <th>Company</th>
                        <th>Owner</th>
                        <th>Location</th>
                        <th>Status</th>
                    </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($recentCompanies as $company): ?>

                    <tr>

                        <td>

                            <div class="person">

                                <span class="person-avatar">
                                    <i class="bi bi-building"></i>
                                </span>

                                <span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            (string)$company['company_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            (string)($company['company_code'] ?? ''),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                </span>

                            </div>

                        </td>

                        <td>
                            <?= htmlspecialchars(
                                trim(
                                    (string)$company['first_name'] .
                                    ' ' .
                                    (string)$company['last_name']
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                (string)($company['city'] ?? ''),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td>

                            <span class="status status-<?= htmlspecialchars(
                                (string)$company['status'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>">
                                <?= htmlspecialchars(
                                    (string)$company['status'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

                <?php else: ?>

                    <div class="empty">
                        <i class="bi bi-buildings"></i>
                        <strong>No companies yet</strong>
                        <span>Company registrations will appear here.</span>
                    </div>

                <?php endif; ?>

            </div>

        </div>


        <div class="card">

            <div class="card-header">

                <div>

                    <div class="card-title">
                        Platform Health
                    </div>

                    <div class="card-subtitle">
                        Current operational state
                    </div>

                </div>

            </div>

            <div class="card-body">

                <div style="
                    display:grid;
                    gap:10px;
                ">

                    <div style="
                        display:flex;
                        justify-content:space-between;
                        padding:11px;
                        background:var(--surface-2);
                        border-radius:10px;
                    ">
                        <span style="font-size:11px">
                            Active rides
                        </span>

                        <strong style="font-size:11px">
                            <?= number_format($stats['active_rides']) ?>
                        </strong>
                    </div>

                    <div style="
                        display:flex;
                        justify-content:space-between;
                        padding:11px;
                        background:var(--surface-2);
                        border-radius:10px;
                    ">
                        <span style="font-size:11px">
                            Pending companies
                        </span>

                        <strong style="font-size:11px;color:var(--warning)">
                            <?= number_format($stats['pending_companies']) ?>
                        </strong>
                    </div>

                    <div style="
                        display:flex;
                        justify-content:space-between;
                        padding:11px;
                        background:var(--surface-2);
                        border-radius:10px;
                    ">
                        <span style="font-size:11px">
                            Pending documents
                        </span>

                        <strong style="font-size:11px;color:var(--warning)">
                            <?= number_format($stats['pending_docs']) ?>
                        </strong>
                    </div>

                    <div style="
                        display:flex;
                        justify-content:space-between;
                        padding:11px;
                        background:var(--surface-2);
                        border-radius:10px;
                    ">
                        <span style="font-size:11px">
                            Registered clients
                        </span>

                        <strong style="font-size:11px">
                            <?= number_format($stats['clients']) ?>
                        </strong>
                    </div>

                    <div style="
                        display:flex;
                        justify-content:space-between;
                        padding:11px;
                        background:var(--surface-2);
                        border-radius:10px;
                    ">
                        <span style="font-size:11px">
                            Reviews
                        </span>

                        <strong style="font-size:11px">
                            <?= number_format($stats['reviews']) ?>
                        </strong>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <div class="card" style="margin-top:15px">

        <div class="card-header">

            <div>

                <div class="card-title">
                    Recent Rides
                </div>

                <div class="card-subtitle">
                    Latest activity across the platform
                </div>

            </div>

            <button
                class="btn btn-sm"
                data-page="rides"
            >
                View all
            </button>

        </div>

        <div class="table-wrap">

            <?php if ($recentRides): ?>

            <table class="data-table">

                <thead>

                <tr>
                    <th>Reference</th>
                    <th>Client</th>
                    <th>Company</th>
                    <th>Route</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>

                </thead>

                <tbody>

                <?php foreach ($recentRides as $ride): ?>

                <tr>

                    <td>
                        <strong>
                            <?= htmlspecialchars(
                                (string)$ride['ride_reference'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            trim(
                                (string)$ride['client_first_name'] .
                                ' ' .
                                (string)$ride['client_last_name']
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            (string)($ride['company_name'] ?? '—'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </td>

                    <td>
                        <span style="
                            display:block;
                            max-width:240px;
                            white-space:nowrap;
                            overflow:hidden;
                            text-overflow:ellipsis;
                            color:var(--muted);
                        ">
                            <?= htmlspecialchars(
                                (string)$ride['pickup_address'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                            →
                            <?= htmlspecialchars(
                                (string)$ride['destination_address'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </span>
                    </td>

                    <td>
                        <?= number_format(
                            (float)($ride['total_amount'] ?? 0),
                            0,
                            '.',
                            ','
                        ) ?>
                        BIF
                    </td>

                    <td>
                        <span class="status status-<?= htmlspecialchars(
                            (string)$ride['status'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>">
                            <?= htmlspecialchars(
                                (string)$ride['status'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </span>
                    </td>

                </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

            <?php else: ?>

                <div class="empty">
                    <i class="bi bi-sign-turn-right"></i>
                    <strong>No rides yet</strong>
                    <span>Ride activity will appear here.</span>
                </div>

            <?php endif; ?>

        </div>

    </div>

</section>


<!-- =========================================================
     COMPANIES
========================================================= -->

<section
    class="page-section"
    data-section="companies"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Companies</h1>

            <p>
                Register, approve, suspend and manage transport companies.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-primary"
                data-modal="companyModal"
            >
                <i class="bi bi-plus-lg"></i>
                Add Company
            </button>

        </div>

    </div>


    <div class="card">

        <div class="toolbar">

            <div class="filters">

                <div class="search">

                    <i class="bi bi-search"></i>

                    <input
                        class="input"
                        id="companySearch"
                        placeholder="Search company..."
                    >

                </div>

                <select
                    class="select"
                    id="companyStatusFilter"
                    style="width:150px"
                >
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="suspended">Suspended</option>
                </select>

            </div>

            <button
                class="btn btn-sm"
                onclick="loadEntity('company')"
            >
                <i class="bi bi-arrow-repeat"></i>
                Refresh
            </button>

        </div>

        <div
            class="table-wrap"
            id="companiesTable"
        >

            <div class="empty">
                <i class="bi bi-buildings"></i>
                <strong>Loading companies...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     USERS
========================================================= -->

<section
    class="page-section"
    data-section="users"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Users</h1>

            <p>
                Manage clients, company owners, staff accounts and platform access.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-primary"
                data-modal="userModal"
            >
                <i class="bi bi-person-plus"></i>
                Add User
            </button>

        </div>

    </div>

    <div class="card">

        <div class="toolbar">

            <div class="filters">

                <div class="search">

                    <i class="bi bi-search"></i>

                    <input
                        class="input"
                        id="userSearch"
                        placeholder="Search users..."
                    >

                </div>

                <select
                    class="select"
                    id="userRoleFilter"
                    style="width:160px"
                >
                    <option value="">All roles</option>
                    <option value="client">Client</option>
                    <option value="company_owner">Company Owner</option>
                    <option value="company_manager">Company Manager</option>
                    <option value="driver">Driver</option>
                    <option value="company_staff">Company Staff</option>
                </select>

            </div>

            <button
                class="btn btn-sm"
                onclick="loadEntity('user')"
            >
                <i class="bi bi-arrow-repeat"></i>
                Refresh
            </button>

        </div>

        <div
            class="table-wrap"
            id="usersTable"
        >

            <div class="empty">
                <i class="bi bi-people"></i>
                <strong>Loading users...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     DRIVERS
========================================================= -->

<section
    class="page-section"
    data-section="drivers"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Drivers</h1>

            <p>
                Manage driver accounts, companies, vehicles and availability.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-primary"
                data-modal="driverModal"
            >
                <i class="bi bi-person-plus"></i>
                Add Driver
            </button>

        </div>

    </div>

    <div class="card">

        <div class="toolbar">

            <div class="filters">

                <div class="search">

                    <i class="bi bi-search"></i>

                    <input
                        class="input"
                        id="driverSearch"
                        placeholder="Search drivers..."
                    >

                </div>

                <select
                    class="select"
                    id="driverStatusFilter"
                    style="width:150px"
                >
                    <option value="">All status</option>
                    <option value="available">Available</option>
                    <option value="busy">Busy</option>
                    <option value="offline">Offline</option>
                </select>

            </div>

            <button
                class="btn btn-sm"
                onclick="loadEntity('driver')"
            >
                <i class="bi bi-arrow-repeat"></i>
                Refresh
            </button>

        </div>

        <div
            class="table-wrap"
            id="driversTable"
        >

            <div class="empty">
                <i class="bi bi-person-badge"></i>
                <strong>Loading drivers...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     VEHICLES
========================================================= -->

<section
    class="page-section"
    data-section="vehicles"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Vehicles</h1>

            <p>
                Manage fleet vehicles, categories, companies and assignments.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-primary"
                data-modal="vehicleModal"
            >
                <i class="bi bi-plus-lg"></i>
                Add Vehicle
            </button>

        </div>

    </div>

    <div class="card">

        <div class="toolbar">

            <div class="filters">

                <div class="search">

                    <i class="bi bi-search"></i>

                    <input
                        class="input"
                        id="vehicleSearch"
                        placeholder="Search plate, brand, model..."
                    >

                </div>

                <select
                    class="select"
                    id="vehicleStatusFilter"
                    style="width:150px"
                >
                    <option value="">All status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

            </div>

            <button
                class="btn btn-sm"
                onclick="loadEntity('vehicle')"
            >
                <i class="bi bi-arrow-repeat"></i>
                Refresh
            </button>

        </div>

        <div
            class="table-wrap"
            id="vehiclesTable"
        >

            <div class="empty">
                <i class="bi bi-car-front"></i>
                <strong>Loading vehicles...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     RIDES
========================================================= -->

<section
    class="page-section"
    data-section="rides"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Rides</h1>

            <p>
                Monitor every ride across all companies and drivers.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-sm"
                onclick="loadEntity('ride')"
            >
                <i class="bi bi-arrow-repeat"></i>
                Refresh
            </button>

        </div>

    </div>

    <div class="card">

        <div class="toolbar">

            <div class="filters">

                <div class="search">

                    <i class="bi bi-search"></i>

                    <input
                        class="input"
                        id="rideSearch"
                        placeholder="Reference, client, route..."
                    >

                </div>

                <select
                    class="select"
                    id="rideStatusFilter"
                    style="width:170px"
                >
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="accepted">Accepted</option>
                    <option value="driver_arrived">Driver Arrived</option>
                    <option value="in_progress">In Progress</option>
                    <option value="destination_reached">Destination Reached</option>
                    <option value="completed">Completed</option>
                    <option value="paid">Paid</option>
                    <option value="cancelled">Cancelled</option>
                </select>

            </div>

        </div>

        <div
            class="table-wrap"
            id="ridesTable"
        >

            <div class="empty">
                <i class="bi bi-sign-turn-right"></i>
                <strong>Loading rides...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     PAYMENTS
========================================================= -->

<section
    class="page-section"
    data-section="payments"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Payments</h1>

            <p>
                Review invoices, payment references and payment status.
            </p>

        </div>

    </div>

    <div class="card">

        <div class="toolbar">

            <div class="filters">

                <div class="search">

                    <i class="bi bi-search"></i>

                    <input
                        class="input"
                        id="paymentSearch"
                        placeholder="Invoice or payment reference..."
                    >

                </div>

                <select
                    class="select"
                    id="paymentStatusFilter"
                    style="width:150px"
                >
                    <option value="">All payments</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="paid">Paid</option>
                    <option value="refunded">Refunded</option>
                </select>

            </div>

        </div>

        <div
            class="table-wrap"
            id="paymentsTable"
        >

            <div class="empty">
                <i class="bi bi-credit-card"></i>
                <strong>Loading payments...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     PROMOTIONS
========================================================= -->

<section
    class="page-section"
    data-section="promotions"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Promotions</h1>

            <p>
                Manage platform and company promotional campaigns.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-primary"
                data-modal="promotionModal"
            >
                <i class="bi bi-plus-lg"></i>
                Add Promotion
            </button>

        </div>

    </div>

    <div class="card">

        <div
            class="table-wrap"
            id="promotionsTable"
        >

            <div class="empty">
                <i class="bi bi-tags"></i>
                <strong>Loading promotions...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     CATEGORIES
========================================================= -->

<section
    class="page-section"
    data-section="categories"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Categories & Pricing</h1>

            <p>
                Control platform-wide vehicle categories and default tariffs.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-primary"
                data-modal="categoryModal"
            >
                <i class="bi bi-plus-lg"></i>
                Add Category
            </button>

        </div>

    </div>

    <div class="card">

        <div
            class="table-wrap"
            id="categoriesTable"
        >

            <div class="empty">
                <i class="bi bi-diagram-3"></i>
                <strong>Loading categories...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     DOCUMENTS
========================================================= -->

<section
    class="page-section"
    data-section="documents"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Documents</h1>

            <p>
                Verify company, driver and vehicle compliance documents.
            </p>

        </div>

    </div>

    <div class="card">

        <div
            class="table-wrap"
            id="documentsTable"
        >

            <div class="empty">
                <i class="bi bi-file-earmark-check"></i>
                <strong>Loading documents...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     REVIEWS
========================================================= -->

<section
    class="page-section"
    data-section="reviews"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Reviews</h1>

            <p>
                Monitor driver and company ratings submitted by clients.
            </p>

        </div>

    </div>

    <div class="card">

        <div
            class="table-wrap"
            id="reviewsTable"
        >

            <div class="empty">
                <i class="bi bi-star"></i>
                <strong>Loading reviews...</strong>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     REPORTS
========================================================= -->

<section
    class="page-section"
    data-section="reports"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Reports</h1>

            <p>
                Platform performance, rides and financial reporting.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-primary"
                id="loadReportBtn"
            >
                <i class="bi bi-bar-chart"></i>
                Generate Report
            </button>

        </div>

    </div>

    <div class="stats-grid" id="reportStats">

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-icon">
                    <i class="bi bi-sign-turn-right"></i>
                </span>
            </div>
            <h3 id="reportRides">—</h3>
            <p>Rides</p>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-icon">
                    <i class="bi bi-check-circle"></i>
                </span>
            </div>
            <h3 id="reportCompleted">—</h3>
            <p>Completed</p>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-icon">
                    <i class="bi bi-x-circle"></i>
                </span>
            </div>
            <h3 id="reportCancelled">—</h3>
            <p>Cancelled</p>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-icon">
                    <i class="bi bi-cash-stack"></i>
                </span>
            </div>
            <h3 id="reportRevenue">—</h3>
            <p>Revenue · BIF</p>
        </div>

    </div>

    <div class="card">

        <div
            class="table-wrap"
            id="reportTable"
        >

            <div class="empty">
                <i class="bi bi-bar-chart"></i>
                <strong>Generate a report</strong>
                <span>
                    Select the reporting period and generate the data.
                </span>
            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     SETTINGS
========================================================= -->

<section
    class="page-section"
    data-section="settings"
>

    <div class="page-header">

        <div class="page-title">

            <h1>System Settings</h1>

            <p>
                Manage global KaryRide configuration.
            </p>

        </div>

        <div class="header-actions">

            <button
                class="btn btn-primary"
                id="saveSettingsBtn"
            >
                <i class="bi bi-check-lg"></i>
                Save Changes
            </button>

        </div>

    </div>

    <div class="card">

        <div class="card-body">

            <form id="settingsForm">

                <div class="form-grid">

                    <div class="form-group">

                        <label>Platform commission (%)</label>

                        <input
                            class="input"
                            name="platform_commission_percent"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            value="5"
                        >

                    </div>

                    <div class="form-group">

                        <label>Default currency</label>

                        <select
                            class="select"
                            name="default_currency"
                        >
                            <option value="BIF">BIF</option>
                        </select>

                    </div>

                    <div class="form-group">

                        <label>Default language</label>

                        <select
                            class="select"
                            name="default_language"
                        >
                            <option value="en">English</option>
                            <option value="rn">Kirundi</option>
                        </select>

                    </div>

                    <div class="form-group">

                        <label>Nearby driver radius (KM)</label>

                        <input
                            class="input"
                            name="nearby_radius_km"
                            type="number"
                            min="1"
                            max="50"
                            step="1"
                            value="10"
                        >

                    </div>

                    <div class="form-group">

                        <label>Booking cancellation window</label>

                        <input
                            class="input"
                            name="cancellation_window_minutes"
                            type="number"
                            min="0"
                            value="5"
                        >

                    </div>

                    <div class="form-group">

                        <label>Platform name</label>

                        <input
                            class="input"
                            name="platform_name"
                            value="KaryRide"
                        >

                    </div>

                </div>

            </form>

        </div>

    </div>

</section>


<!-- =========================================================
     PROFILE
========================================================= -->

<section
    class="page-section"
    data-section="profile"
>

    <div class="page-header">

        <div class="page-title">

            <h1>Admin Profile</h1>

            <p>
                Manage your Super Admin account information.
            </p>

        </div>

    </div>

    <div class="card">

        <div class="card-body">

            <form id="profileForm">

                <div class="form-grid">

                    <div class="form-group">

                        <label>First name</label>

                        <input
                            class="input"
                            name="first_name"
                            value="<?= htmlspecialchars(
                                $firstName,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >

                    </div>

                    <div class="form-group">

                        <label>Last name</label>

                        <input
                            class="input"
                            name="last_name"
                            value="<?= htmlspecialchars(
                                $lastName,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >

                    </div>

                    <div class="form-group">

                        <label>Email</label>

                        <input
                            class="input"
                            type="email"
                            name="email"
                            value="<?= htmlspecialchars(
                                $email,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >

                    </div>

                    <div class="form-group">

                        <label>Phone</label>

                        <input
                            class="input"
                            name="phone"
                            value="<?= htmlspecialchars(
                                $phone,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >

                    </div>

                </div>

                <div style="margin-top:15px">

                    <button
                        class="btn btn-primary"
                        type="submit"
                    >
                        <i class="bi bi-check-lg"></i>
                        Update Profile
                    </button>

                </div>

            </form>

        </div>

    </div>

</section>

</div>

</main>

</div>


<!-- =========================================================
     COMPANY MODAL
========================================================= -->

<div
    class="modal-backdrop"
    id="companyModal"
>

<div class="modal">

    <div class="modal-header">

        <h3 id="companyModalTitle">
            Add Company
        </h3>

        <button
            class="modal-close"
            data-close-modal
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <form id="companyForm">

        <div class="modal-body">

            <input
                type="hidden"
                name="id"
                id="companyId"
            >

            <div class="form-grid">

                <div class="form-group">

                    <label>Company name</label>

                    <input
                        class="input"
                        name="company_name"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Company code</label>

                    <input
                        class="input"
                        name="company_code"
                        placeholder="KR-COMP-001"
                    >

                </div>

                <div class="form-group">

                    <label>Owner user ID</label>

                    <input
                        class="input"
                        type="number"
                        name="owner_user_id"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Phone</label>

                    <input
                        class="input"
                        name="phone"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Email</label>

                    <input
                        class="input"
                        type="email"
                        name="email"
                    >

                </div>

                <div class="form-group">

                    <label>Address</label>

                    <input
                        class="input"
                        name="address"
                    >

                </div>

                <div class="form-group">

                    <label>City</label>

                    <input
                        class="input"
                        name="city"
                        value="Bujumbura"
                    >

                </div>

                <div class="form-group">

                    <label>Province</label>

                    <input
                        class="input"
                        name="province"
                        value="Bujumbura Mairie"
                    >

                </div>

                <div class="form-group">

                    <label>Status</label>

                    <select
                        class="select"
                        name="status"
                    >
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="suspended">Suspended</option>
                    </select>

                </div>

            </div>

        </div>

        <div class="modal-footer">

            <button
                class="btn"
                type="button"
                data-close-modal
            >
                Cancel
            </button>

            <button
                class="btn btn-primary"
                type="submit"
            >
                <i class="bi bi-check-lg"></i>
                Save Company
            </button>

        </div>

    </form>

</div>

</div>


<!-- =========================================================
     USER MODAL
========================================================= -->

<div
    class="modal-backdrop"
    id="userModal"
>

<div class="modal">

    <div class="modal-header">

        <h3>Add User</h3>

        <button
            class="modal-close"
            data-close-modal
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <form id="userForm">

        <div class="modal-body">

            <input
                type="hidden"
                name="id"
            >

            <div class="form-grid">

                <div class="form-group">

                    <label>First name</label>

                    <input
                        class="input"
                        name="first_name"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Last name</label>

                    <input
                        class="input"
                        name="last_name"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Email</label>

                    <input
                        class="input"
                        type="email"
                        name="email"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Phone</label>

                    <input
                        class="input"
                        name="phone"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Role</label>

                    <select
                        class="select"
                        name="role"
                        required
                    >
                        <option value="client">Client</option>
                        <option value="company_owner">Company Owner</option>
                        <option value="company_manager">Company Manager</option>
                        <option value="company_staff">Company Staff</option>
                        <option value="driver">Driver</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>Password</label>

                    <input
                        class="input"
                        type="password"
                        name="password"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Language</label>

                    <select
                        class="select"
                        name="preferred_language"
                    >
                        <option value="en">English</option>
                        <option value="rn">Kirundi</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>Active</label>

                    <select
                        class="select"
                        name="is_active"
                    >
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>

                </div>

            </div>

        </div>

        <div class="modal-footer">

            <button
                class="btn"
                type="button"
                data-close-modal
            >
                Cancel
            </button>

            <button
                class="btn btn-primary"
                type="submit"
            >
                Save User
            </button>

        </div>

    </form>

</div>

</div>


<!-- =========================================================
     DRIVER MODAL
========================================================= -->

<div
    class="modal-backdrop"
    id="driverModal"
>

<div class="modal">

    <div class="modal-header">

        <h3>Add Driver</h3>

        <button
            class="modal-close"
            data-close-modal
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <form id="driverForm">

        <div class="modal-body">

            <div class="form-grid">

                <div class="form-group">

                    <label>User ID</label>

                    <input
                        class="input"
                        type="number"
                        name="user_id"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Company ID</label>

                    <input
                        class="input"
                        type="number"
                        name="company_id"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>License number</label>

                    <input
                        class="input"
                        name="license_number"
                    >

                </div>

                <div class="form-group">

                    <label>Driver status</label>

                    <select
                        class="select"
                        name="driver_status"
                    >
                        <option value="offline">Offline</option>
                        <option value="available">Available</option>
                        <option value="busy">Busy</option>
                    </select>

                </div>

            </div>

        </div>

        <div class="modal-footer">

            <button
                class="btn"
                type="button"
                data-close-modal
            >
                Cancel
            </button>

            <button
                class="btn btn-primary"
                type="submit"
            >
                Save Driver
            </button>

        </div>

    </form>

</div>

</div>


<!-- =========================================================
     VEHICLE MODAL
========================================================= -->

<div
    class="modal-backdrop"
    id="vehicleModal"
>

<div class="modal">

    <div class="modal-header">

        <h3>Add Vehicle</h3>

        <button
            class="modal-close"
            data-close-modal
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <form id="vehicleForm">

        <div class="modal-body">

            <div class="form-grid">

                <div class="form-group">

                    <label>Company ID</label>

                    <input
                        class="input"
                        type="number"
                        name="company_id"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Category ID</label>

                    <input
                        class="input"
                        type="number"
                        name="category_id"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Plate number</label>

                    <input
                        class="input"
                        name="plate_number"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Brand</label>

                    <input
                        class="input"
                        name="brand"
                    >

                </div>

                <div class="form-group">

                    <label>Model</label>

                    <input
                        class="input"
                        name="model"
                    >

                </div>

                <div class="form-group">

                    <label>Color</label>

                    <input
                        class="input"
                        name="color"
                    >

                </div>

                <div class="form-group">

                    <label>Seating capacity</label>

                    <input
                        class="input"
                        type="number"
                        name="seating_capacity"
                        min="1"
                        value="4"
                    >

                </div>

                <div class="form-group">

                    <label>Active</label>

                    <select
                        class="select"
                        name="is_active"
                    >
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>

                </div>

            </div>

        </div>

        <div class="modal-footer">

            <button
                class="btn"
                type="button"
                data-close-modal
            >
                Cancel
            </button>

            <button
                class="btn btn-primary"
                type="submit"
            >
                Save Vehicle
            </button>

        </div>

    </form>

</div>

</div>


<!-- =========================================================
     PROMOTION MODAL
========================================================= -->

<div
    class="modal-backdrop"
    id="promotionModal"
>

<div class="modal">

    <div class="modal-header">

        <h3>Add Promotion</h3>

        <button
            class="modal-close"
            data-close-modal
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <form id="promotionForm">

        <div class="modal-body">

            <div class="form-grid">

                <div class="form-group">

                    <label>Company ID</label>

                    <input
                        class="input"
                        type="number"
                        name="company_id"
                    >

                </div>

                <div class="form-group">

                    <label>Promotion code</label>

                    <input
                        class="input"
                        name="code"
                        required
                    >

                </div>

                <div class="form-group full">

                    <label>Name</label>

                    <input
                        class="input"
                        name="name"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Discount type</label>

                    <select
                        class="select"
                        name="discount_type"
                    >
                        <option value="percentage">Percentage</option>
                        <option value="fixed">Fixed amount</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>Discount value</label>

                    <input
                        class="input"
                        type="number"
                        name="discount_value"
                        min="0"
                        step="0.01"
                    >

                </div>

                <div class="form-group">

                    <label>Start date</label>

                    <input
                        class="input"
                        type="datetime-local"
                        name="start_at"
                    >

                </div>

                <div class="form-group">

                    <label>End date</label>

                    <input
                        class="input"
                        type="datetime-local"
                        name="end_at"
                    >

                </div>

                <div class="form-group">

                    <label>Usage limit</label>

                    <input
                        class="input"
                        type="number"
                        name="usage_limit"
                        min="0"
                    >

                </div>

                <div class="form-group">

                    <label>Status</label>

                    <select
                        class="select"
                        name="is_active"
                    >
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>

                </div>

            </div>

        </div>

        <div class="modal-footer">

            <button
                class="btn"
                type="button"
                data-close-modal
            >
                Cancel
            </button>

            <button
                class="btn btn-primary"
                type="submit"
            >
                Save Promotion
            </button>

        </div>

    </form>

</div>

</div>


<!-- =========================================================
     CATEGORY MODAL
========================================================= -->

<div
    class="modal-backdrop"
    id="categoryModal"
>

<div class="modal">

    <div class="modal-header">

        <h3>Add Category</h3>

        <button
            class="modal-close"
            data-close-modal
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <form id="categoryForm">

        <div class="modal-body">

            <div class="form-grid">

                <div class="form-group">

                    <label>Category name</label>

                    <input
                        class="input"
                        name="category_name"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Vehicle type</label>

                    <select
                        class="select"
                        name="vehicle_type"
                    >
                        <option value="car">Car</option>
                        <option value="motorcycle">Motorcycle</option>
                        <option value="minibus">Minibus</option>
                        <option value="truck">Truck</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>Base fare</label>

                    <input
                        class="input"
                        type="number"
                        name="base_fare"
                        min="0"
                        step="1"
                    >

                </div>

                <div class="form-group">

                    <label>Price per KM</label>

                    <input
                        class="input"
                        type="number"
                        name="price_per_km"
                        min="0"
                        step="1"
                    >

                </div>

                <div class="form-group">

                    <label>Minimum fare</label>

                    <input
                        class="input"
                        type="number"
                        name="min_fare"
                        min="0"
                        step="1"
                    >

                </div>

                <div class="form-group">

                    <label>Advance booking fee</label>

                    <input
                        class="input"
                        type="number"
                        name="advance_booking_fee"
                        min="0"
                        step="1"
                    >

                </div>

            </div>

        </div>

        <div class="modal-footer">

            <button
                class="btn"
                type="button"
                data-close-modal
            >
                Cancel
            </button>

            <button
                class="btn btn-primary"
                type="submit"
            >
                Save Category
            </button>

        </div>

    </form>

</div>

</div>


<div class="toast" id="toast">

    <i id="toastIcon" class="bi bi-check-circle"></i>

    <span id="toastText"></span>

</div>


<script>
'use strict';

/*
|--------------------------------------------------------------------------
| KaryRide Admin JavaScript
|--------------------------------------------------------------------------
*/

const API = '../api/';

let currentPage = 'dashboard';

/* ============================================================
   THEME
============================================================ */

const themeBtn = document.getElementById('themeBtn');
const themeIcon = document.getElementById('themeIcon');

function applyTheme(theme) {

    const dark = theme === 'dark';

    document.body.classList.toggle('dark', dark);

    if (themeIcon) {
        themeIcon.className = dark
            ? 'bi bi-sun-fill'
            : 'bi bi-moon-stars-fill';
    }

    localStorage.setItem(
        'karyride_theme',
        dark ? 'dark' : 'light'
    );
}

const savedTheme =
    localStorage.getItem('karyride_theme');

applyTheme(
    savedTheme ||
    (
        window.matchMedia &&
        window.matchMedia(
            '(prefers-color-scheme: dark)'
        ).matches
            ? 'dark'
            : 'light'
    )
);

themeBtn?.addEventListener('click', function(){

    applyTheme(
        document.body.classList.contains('dark')
            ? 'light'
            : 'dark'
    );

});


/* ============================================================
   DROPDOWNS
============================================================ */

const profileBtn =
    document.getElementById('profileBtn');

const profilePanel =
    document.getElementById('profilePanel');

const languageBtn =
    document.getElementById('languageBtn');

const languagePanel =
    document.getElementById('languagePanel');

profileBtn?.addEventListener('click', function(e){

    e.stopPropagation();

    profilePanel?.classList.toggle('open');

    languagePanel?.classList.remove('open');

});

languageBtn?.addEventListener('click', function(e){

    e.stopPropagation();

    languagePanel?.classList.toggle('open');

    profilePanel?.classList.remove('open');

});

document.addEventListener('click', function(){

    profilePanel?.classList.remove('open');

    languagePanel?.classList.remove('open');

});


/* ============================================================
   MOBILE SIDEBAR
============================================================ */

const sidebar =
    document.getElementById('sidebar');

const mobileMenuBtn =
    document.getElementById('mobileMenuBtn');

const mobileMenuIcon =
    document.getElementById('mobileMenuIcon');

mobileMenuBtn?.addEventListener('click', function(){

    sidebar?.classList.toggle('open');

    if (mobileMenuIcon) {

        mobileMenuIcon.className =
            sidebar.classList.contains('open')
                ? 'bi bi-x-lg'
                : 'bi bi-list';

    }

});


/* ============================================================
   TOAST
============================================================ */

const toast =
    document.getElementById('toast');

const toastText =
    document.getElementById('toastText');

const toastIcon =
    document.getElementById('toastIcon');

let toastTimer = null;

function showToast(
    message,
    type = 'success'
){

    if (!toast) return;

    toast.className =
        'toast show ' + type;

    toastText.textContent =
        message || '';

    toastIcon.className =
        type === 'error'
            ? 'bi bi-exclamation-circle'
            : type === 'warning'
                ? 'bi bi-exclamation-triangle'
                : 'bi bi-check-circle';

    clearTimeout(toastTimer);

    toastTimer = setTimeout(function(){

        toast.classList.remove('show');

    }, 3500);

}


/* ============================================================
   PAGE NAVIGATION
============================================================ */

function openPage(page){

    if (!page) return;

    currentPage = page;

    document.querySelectorAll('.nav-item')
        .forEach(function(item){

            item.classList.toggle(
                'active',
                item.dataset.page === page
            );

        });

    document.querySelectorAll('.page-section')
        .forEach(function(section){

            section.classList.toggle(
                'active',
                section.dataset.section === page
            );

        });

    sidebar?.classList.remove('open');

    if (mobileMenuIcon) {
        mobileMenuIcon.className =
            'bi bi-list';
    }

    if (page !== 'dashboard') {

        const entityPages = [
            'companies',
            'users',
            'drivers',
            'vehicles',
            'rides',
            'payments',
            'promotions',
            'categories',
            'documents',
            'reviews'
        ];

        if (entityPages.includes(page)) {

            loadEntity(
                page.endsWith('ies')
                    ? page.slice(0,-3) + 'y'
                    : page.endsWith('s')
                        ? page.slice(0,-1)
                        : page
            );

        }

    }

}

document.querySelectorAll(
    '[data-page]'
).forEach(function(button){

    button.addEventListener('click', function(){

        openPage(this.dataset.page);

    });

});


/* ============================================================
   MODALS
============================================================ */

function openModal(id){

    const modal =
        document.getElementById(id);

    if (modal) {
        modal.classList.add('open');
    }

}

function closeModal(modal){

    if (!modal) return;

    modal.classList.remove('open');

}

document.querySelectorAll(
    '[data-modal]'
).forEach(function(button){

    button.addEventListener('click', function(){

        openModal(
            this.dataset.modal
        );

    });

});

document.querySelectorAll(
    '[data-close-modal]'
).forEach(function(button){

    button.addEventListener('click', function(){

        closeModal(
            this.closest('.modal-backdrop')
        );

    });

});

document.querySelectorAll(
    '.modal-backdrop'
).forEach(function(backdrop){

    backdrop.addEventListener('click', function(e){

        if (e.target === backdrop) {
            closeModal(backdrop);
        }

    });

});

document.addEventListener('keydown', function(e){

    if (e.key === 'Escape') {

        document.querySelectorAll(
            '.modal-backdrop.open'
        ).forEach(function(modal){

            closeModal(modal);

        });

    }

});


/* ============================================================
   API REQUEST
============================================================ */

async function apiRequest(
    file,
    action,
    data = {}
){

    const form =
        new FormData();

    form.append(
        'action',
        action
    );

    Object.entries(data)
        .forEach(function([key,value]){

            if (
                value !== undefined &&
                value !== null
            ) {
                form.append(
                    key,
                    value
                );
            }

        });

    const response =
        await fetch(
            API + file + '.php',
            {
                method:'POST',
                body:form,
                credentials:'same-origin',
                headers:{
                    'Accept':'application/json'
                }
            }
        );

    const text =
        await response.text();

    let result;

    try {

        result =
            JSON.parse(text);

    } catch(error){

        console.error(
            'Invalid API response:',
            text
        );

        throw new Error(
            'The server returned an invalid response.'
        );

    }

    if (!response.ok || !result.success) {

        throw new Error(
            result.message ||
            'The operation failed.'
        );

    }

    return result;

}


/* ============================================================
   HTML ESCAPE
============================================================ */

function escapeHtml(value){

    return String(value ?? '')
        .replace(
            /[&<>"']/g,
            function(char){

                return {
                    '&':'&amp;',
                    '<':'&lt;',
                    '>':'&gt;',
                    '"':'&quot;',
                    "'":'&#039;'
                }[char];

            }
        );

}


/* ============================================================
   INITIAL LETTERS
============================================================ */

function initials(name){

    const parts =
        String(name || '')
            .trim()
            .split(/\s+/)
            .filter(Boolean);

    if (!parts.length) return '—';

    return (
        parts[0][0] || ''
    ) + (
        parts.length > 1
            ? parts[parts.length - 1][0]
            : ''
    );

}


/* ============================================================
   GENERIC ENTITY LOADING
============================================================ */

async function loadEntity(entity){

    const targetMap = {

        company:'companiesTable',
        user:'usersTable',
        driver:'driversTable',
        vehicle:'vehiclesTable',
        ride:'ridesTable',
        payment:'paymentsTable',
        promotion:'promotionsTable',
        category:'categoriesTable',
        document:'documentsTable',
        review:'reviewsTable'

    };

    const target =
        document.getElementById(
            targetMap[entity]
        );

    if (!target) return;

    target.innerHTML = `
        <div class="empty">
            <i class="bi bi-arrow-repeat"></i>
            <strong>Loading...</strong>
        </div>
    `;

    try {

        const filters = {};

        const searchMap = {
            company:'companySearch',
            user:'userSearch',
            driver:'driverSearch',
            vehicle:'vehicleSearch',
            ride:'rideSearch',
            payment:'paymentSearch'
        };

        const filterMap = {
            company:'companyStatusFilter',
            user:'userRoleFilter',
            driver:'driverStatusFilter',
            vehicle:'vehicleStatusFilter',
            ride:'rideStatusFilter',
            payment:'paymentStatusFilter'
        };

        if (searchMap[entity]) {

            const el =
                document.getElementById(
                    searchMap[entity]
                );

            if (el) {
                filters.search =
                    el.value.trim();
            }

        }

        if (filterMap[entity]) {

            const el =
                document.getElementById(
                    filterMap[entity]
                );

            if (el) {
                filters.filter =
                    el.value;
            }

        }

        const result =
            await apiRequest(
                entity,
                'list',
                filters
            );

        renderEntity(
            entity,
            result.data || result.items || []
        );

    } catch(error){

        console.error(error);

        target.innerHTML = `
            <div class="empty">
                <i class="bi bi-exclamation-circle"></i>
                <strong>Could not load data</strong>
                <span>${escapeHtml(error.message)}</span>
            </div>
        `;

    }

}


/* ============================================================
   ENTITY RENDERING
============================================================ */

function renderEntity(
    entity,
    rows
){

    const targetMap = {

        company:'companiesTable',
        user:'usersTable',
        driver:'driversTable',
        vehicle:'vehiclesTable',
        ride:'ridesTable',
        payment:'paymentsTable',
        promotion:'promotionsTable',
        category:'categoriesTable',
        document:'documentsTable',
        review:'reviewsTable'

    };

    const target =
        document.getElementById(
            targetMap[entity]
        );

    if (!target) return;

    if (!Array.isArray(rows) || !rows.length){

        target.innerHTML = `
            <div class="empty">
                <i class="bi bi-inbox"></i>
                <strong>No records found</strong>
                <span>There is nothing matching the current filters.</span>
            </div>
        `;

        return;
    }


    if (entity === 'company'){

        target.innerHTML = `
            <table class="data-table">

                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Owner</th>
                        <th>Contact</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                ${rows.map(function(row){

                    const owner =
                        (
                            row.owner_name ||
                            (
                                String(row.first_name || '') +
                                ' ' +
                                String(row.last_name || '')
                            )
                        ).trim();

                    return `
                    <tr>

                        <td>
                            <div class="person">

                                <span class="person-avatar">
                                    <i class="bi bi-building"></i>
                                </span>

                                <span>
                                    <strong>
                                        ${escapeHtml(row.company_name)}
                                    </strong>

                                    <span>
                                        ${escapeHtml(
                                            row.company_code || ''
                                        )}
                                    </span>
                                </span>

                            </div>
                        </td>

                        <td>
                            ${escapeHtml(owner || '—')}
                        </td>

                        <td>
                            <span>
                                ${escapeHtml(row.phone || '—')}
                            </span>
                            <span style="
                                display:block;
                                color:var(--muted);
                                font-size:9px;
                                margin-top:2px;
                            ">
                                ${escapeHtml(row.email || '')}
                            </span>
                        </td>

                        <td>
                            ${escapeHtml(
                                row.city || '—'
                            )}
                        </td>

                        <td>
                            <span class="status status-${escapeHtml(
                                row.status || ''
                            )}">
                                ${escapeHtml(
                                    row.status || 'unknown'
                                )}
                            </span>
                        </td>

                        <td>
                            <div class="actions">

                                <button
                                    class="action"
                                    title="Edit"
                                    onclick="editCompany(${Number(row.id)})"
                                >
                                    <i class="bi bi-pencil"></i>
                                </button>

                                ${
                                    row.status === 'pending'
                                    ? `
                                    <button
                                        class="action"
                                        title="Approve"
                                        onclick="companyAction(${Number(row.id)},'approve')"
                                    >
                                        <i class="bi bi-check-lg"></i>
                                    </button>

                                    <button
                                        class="action danger"
                                        title="Reject"
                                        onclick="companyAction(${Number(row.id)},'reject')"
                                    >
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                    `
                                    : ''
                                }

                                ${
                                    row.status === 'approved'
                                    ? `
                                    <button
                                        class="action danger"
                                        title="Suspend"
                                        onclick="companyAction(${Number(row.id)},'suspend')"
                                    >
                                        <i class="bi bi-pause"></i>
                                    </button>
                                    `
                                    : ''
                                }

                                ${
                                    row.status === 'suspended'
                                    ? `
                                    <button
                                        class="action"
                                        title="Activate"
                                        onclick="companyAction(${Number(row.id)},'activate')"
                                    >
                                        <i class="bi bi-play"></i>
                                    </button>
                                    `
                                    : ''
                                }

                                <button
                                    class="action danger"
                                    title="Delete"
                                    onclick="deleteEntity('company',${Number(row.id)})"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>

                            </div>
                        </td>

                    </tr>
                    `;

                }).join('')}

                </tbody>

            </table>
        `;

        return;
    }


    if (entity === 'user'){

        target.innerHTML = `
            <table class="data-table">

                <thead>
                    <tr>
                        <th>User</th>
                        <th>Contact</th>
                        <th>Role</th>
                        <th>Language</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                ${rows.map(function(row){

                    const name =
                        (
                            String(row.first_name || '') +
                            ' ' +
                            String(row.last_name || '')
                        ).trim();

                    return `
                    <tr>

                        <td>
                            <div class="person">

                                <span class="person-avatar">
                                    ${escapeHtml(
                                        initials(name)
                                    )}
                                </span>

                                <span>
                                    <strong>
                                        ${escapeHtml(
                                            name || 'Unknown'
                                        )}
                                    </strong>

                                    <span>
                                        ID #${Number(row.id)}
                                    </span>
                                </span>

                            </div>
                        </td>

                        <td>
                            ${escapeHtml(row.email || '—')}
                            <span style="
                                display:block;
                                color:var(--muted);
                                font-size:9px;
                                margin-top:2px;
                            ">
                                ${escapeHtml(row.phone || '')}
                            </span>
                        </td>

                        <td>
                            ${escapeHtml(
                                row.role || '—'
                            )}
                        </td>

                        <td>
                            ${escapeHtml(
                                row.preferred_language || 'en'
                            )}
                        </td>

                        <td>
                            <span class="status ${
                                Number(row.is_active)
                                    ? 'status-active'
                                    : 'status-suspended'
                            }">
                                ${
                                    Number(row.is_active)
                                        ? 'active'
                                        : 'inactive'
                                }
                            </span>
                        </td>

                        <td>
                            <div class="actions">

                                <button
                                    class="action"
                                    onclick="toggleUser(${Number(row.id)},${Number(row.is_active) ? 0 : 1})"
                                >
                                    <i class="bi ${
                                        Number(row.is_active)
                                            ? 'bi-person-slash'
                                            : 'bi-person-check'
                                    }"></i>
                                </button>

                                <button
                                    class="action danger"
                                    onclick="deleteEntity('user',${Number(row.id)})"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>

                            </div>
                        </td>

                    </tr>
                    `;

                }).join('')}

                </tbody>

            </table>
        `;

        return;
    }


    if (entity === 'driver'){

        target.innerHTML = `
            <table class="data-table">

                <thead>
                    <tr>
                        <th>Driver</th>
                        <th>Company</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                ${rows.map(function(row){

                    const name =
                        (
                            String(row.first_name || '') +
                            ' ' +
                            String(row.last_name || '')
                        ).trim();

                    return `
                    <tr>

                        <td>
                            <div class="person">

                                <span class="person-avatar">
                                    <i class="bi bi-person-badge"></i>
                                </span>

                                <span>
                                    <strong>
                                        ${escapeHtml(
                                            name || 'Unknown'
                                        )}
                                    </strong>

                                    <span>
                                        ${escapeHtml(
                                            row.license_number || 'No license'
                                        )}
                                    </span>
                                </span>

                            </div>
                        </td>

                        <td>
                            ${escapeHtml(
                                row.company_name || '—'
                            )}
                        </td>

                        <td>
                            ${escapeHtml(
                                row.plate_number || 'Unassigned'
                            )}
                        </td>

                        <td>
                            <span class="status status-${escapeHtml(
                                row.driver_status || 'offline'
                            )}">
                                ${escapeHtml(
                                    row.driver_status || 'offline'
                                )}
                            </span>
                        </td>

                        <td>
                            ${
                                row.current_latitude &&
                                row.current_longitude
                                    ? 'GPS available'
                                    : 'No GPS'
                            }
                        </td>

                        <td>
                            <div class="actions">

                                <button
                                    class="action"
                                    onclick="editDriver(${Number(row.id)})"
                                >
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <button
                                    class="action danger"
                                    onclick="driverAction(${Number(row.id)},'suspend')"
                                >
                                    <i class="bi bi-pause"></i>
                                </button>

                                <button
                                    class="action danger"
                                    onclick="deleteEntity('driver',${Number(row.id)})"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>

                            </div>
                        </td>

                    </tr>
                    `;

                }).join('')}

                </tbody>

            </table>
        `;

        return;
    }


    if (entity === 'vehicle'){

        target.innerHTML = `
            <table class="data-table">

                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Company</th>
                        <th>Category</th>
                        <th>Driver</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                ${rows.map(function(row){

                    return `
                    <tr>

                        <td>
                            <div class="person">

                                <span class="person-avatar">
                                    <i class="bi bi-car-front"></i>
                                </span>

                                <span>
                                    <strong>
                                        ${escapeHtml(
                                            row.plate_number || '—'
                                        )}
                                    </strong>

                                    <span>
                                        ${escapeHtml(
                                            (
                                                row.brand || ''
                                            ) +
                                            ' ' +
                                            (
                                                row.model || ''
                                            )
                                        )}
                                    </span>
                                </span>

                            </div>
                        </td>

                        <td>
                            ${escapeHtml(
                                row.company_name || '—'
                            )}
                        </td>

                        <td>
                            ${escapeHtml(
                                row.category_name || '—'
                            )}
                        </td>

                        <td>
                            ${escapeHtml(
                                row.driver_name || 'Unassigned'
                            )}
                        </td>

                        <td>
                            <span class="status ${
                                Number(row.is_active)
                                    ? 'status-active'
                                    : 'status-suspended'
                            }">
                                ${
                                    Number(row.is_active)
                                        ? 'active'
                                        : 'inactive'
                                }
                            </span>
                        </td>

                        <td>
                            <div class="actions">

                                <button
                                    class="action"
                                    onclick="editVehicle(${Number(row.id)})"
                                >
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <button
                                    class="action"
                                    onclick="vehicleAction(
                                        ${Number(row.id)},
                                        ${Number(row.is_active) ? '0' : '1'}
                                    )"
                                >
                                    <i class="bi ${
                                        Number(row.is_active)
                                            ? 'bi-pause'
                                            : 'bi-play'
                                    }"></i>
                                </button>

                                <button
                                    class="action danger"
                                    onclick="deleteEntity('vehicle',${Number(row.id)})"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>

                            </div>
                        </td>

                    </tr>
                    `;

                }).join('')}

                </tbody>

            </table>
        `;

        return;
    }


    if (entity === 'ride'){

        target.innerHTML = `
            <table class="data-table">

                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Client</th>
                        <th>Company</th>
                        <th>Route</th>
                        <th>Fare</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>

                ${rows.map(function(row){

                    const client =
                        (
                            String(row.client_first_name || '') +
                            ' ' +
                            String(row.client_last_name || '')
                        ).trim();

                    return `
                    <tr>

                        <td>
                            <strong>
                                ${escapeHtml(
                                    row.ride_reference || '#'+row.id
                                )}
                            </strong>
                        </td>

                        <td>
                            ${escapeHtml(
                                client || 'Unknown'
                            )}
                        </td>

                        <td>
                            ${escapeHtml(
                                row.company_name || '—'
                            )}
                        </td>

                        <td>
                            <span style="
                                display:block;
                                max-width:260px;
                                overflow:hidden;
                                white-space:nowrap;
                                text-overflow:ellipsis;
                                color:var(--muted);
                            ">
                                ${escapeHtml(
                                    row.pickup_address || ''
                                )}
                                →
                                ${escapeHtml(
                                    row.destination_address || ''
                                )}
                            </span>
                        </td>

                        <td>
                            ${Number(
                                row.total_amount || 0
                            ).toLocaleString()} BIF
                        </td>

                        <td>
                            <span class="status status-${escapeHtml(
                                row.status || ''
                            )}">
                                ${escapeHtml(
                                    row.status || 'unknown'
                                )}
                            </span>
                        </td>

                        <td>
                            <div class="actions">

                                <button
                                    class="action"
                                    onclick="viewRide(${Number(row.id)})"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>

                                ${
                                    [
                                        'pending',
                                        'accepted',
                                        'driver_arrived',
                                        'in_progress'
                                    ].includes(row.status)
                                    ? `
                                    <button
                                        class="action danger"
                                        onclick="cancelRide(${Number(row.id)})"
                                    >
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                    `
                                    : ''
                                }

                            </div>
                        </td>

                    </tr>
                    `;

                }).join('')}

                </tbody>

            </table>
        `;

        return;
    }


    /*
     * Remaining entities use a safe generic renderer until
     * their dedicated API supplies their complete fields.
     */

    target.innerHTML = `
        <table class="data-table">

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name / Reference</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>

            ${rows.map(function(row){

                const name =
                    row.name ||
                    row.title ||
                    row.code ||
                    row.invoice_number ||
                    row.payment_reference ||
                    row.category_name ||
                    row.promotion_name ||
                    row.id;

                const status =
                    row.status ||
                    row.payment_status ||
                    (
                        Number(row.is_active)
                            ? 'active'
                            : 'inactive'
                    );

                return `
                <tr>

                    <td>
                        #${Number(row.id || 0)}
                    </td>

                    <td>
                        <strong>
                            ${escapeHtml(name)}
                        </strong>
                    </td>

                    <td>
                        <span class="status status-${escapeHtml(
                            status
                        )}">
                            ${escapeHtml(status)}
                        </span>
                    </td>

                    <td>
                        ${escapeHtml(
                            row.created_at || '—'
                        )}
                    </td>

                    <td>

                        <div class="actions">

                            <button
                                class="action"
                                onclick="viewEntity(
                                    '${escapeHtml(entity)}',
                                    ${Number(row.id)}
                                )"
                            >
                                <i class="bi bi-eye"></i>
                            </button>

                            <button
                                class="action danger"
                                onclick="deleteEntity(
                                    '${escapeHtml(entity)}',
                                    ${Number(row.id)}
                                )"
                            >
                                <i class="bi bi-trash"></i>
                            </button>

                        </div>

                    </td>

                </tr>
                `;

            }).join('')}

            </tbody>

        </table>
    `;

}


/* ============================================================
   COMPANY ACTIONS
============================================================ */

async function companyAction(
    id,
    action
){

    if (!id) return;

    const labels = {
        approve:'approve',
        reject:'reject',
        suspend:'suspend',
        activate:'activate'
    };

    const label =
        labels[action] || action;

    if (!confirm(
        'Are you sure you want to ' +
        label +
        ' this company?'
    )) {
        return;
    }

    try {

        await apiRequest(
            'company',
            action,
            {
                id:id
            }
        );

        showToast(
            'Company ' + label + 'd successfully.'
        );

        loadEntity('company');

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   DELETE
============================================================ */

async function deleteEntity(
    entity,
    id
){

    if (!id) return;

    if (!confirm(
        'This action cannot be undone. Continue?'
    )) {
        return;
    }

    try {

        await apiRequest(
            entity,
            'delete',
            {
                id:id
            }
        );

        showToast(
            'Record deleted successfully.'
        );

        loadEntity(entity);

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   USER ACTION
============================================================ */

async function toggleUser(
    id,
    active
){

    try {

        await apiRequest(
            'user',
            active ? 'activate' : 'deactivate',
            {
                id:id
            }
        );

        showToast(
            active
                ? 'User activated.'
                : 'User deactivated.'
        );

        loadEntity('user');

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   DRIVER ACTION
============================================================ */

async function driverAction(
    id,
    action
){

    if (!confirm(
        'Change this driver status?'
    )) {
        return;
    }

    try {

        await apiRequest(
            'driver',
            action,
            {
                id:id
            }
        );

        showToast(
            'Driver updated successfully.'
        );

        loadEntity('driver');

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   VEHICLE ACTION
============================================================ */

async function vehicleAction(
    id,
    active
){

    try {

        await apiRequest(
            'vehicle',
            active === '1'
                ? 'activate'
                : 'deactivate',
            {
                id:id
            }
        );

        showToast(
            'Vehicle status updated.'
        );

        loadEntity('vehicle');

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   RIDE ACTION
============================================================ */

async function cancelRide(id){

    if (!confirm(
        'Cancel this ride?'
    )) {
        return;
    }

    try {

        await apiRequest(
            'ride',
            'admin_cancel',
            {
                ride_id:id
            }
        );

        showToast(
            'Ride cancelled.'
        );

        loadEntity('ride');

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}

async function viewRide(id){

    try {

        const result =
            await apiRequest(
                'ride',
                'get',
                {
                    id:id
                }
            );

        alert(
            JSON.stringify(
                result.data || result.ride,
                null,
                2
            )
        );

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   GENERIC VIEW
============================================================ */

async function viewEntity(
    entity,
    id
){

    try {

        const result =
            await apiRequest(
                entity,
                'get',
                {
                    id:id
                }
            );

        alert(
            JSON.stringify(
                result.data ||
                result.item ||
                result,
                null,
                2
            )
        );

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   COMPANY EDIT
============================================================ */

async function editCompany(id){

    try {

        const result =
            await apiRequest(
                'company',
                'get',
                {
                    id:id
                }
            );

        const company =
            result.data ||
            result.company;

        if (!company) {
            throw new Error(
                'Company data not returned.'
            );
        }

        const form =
            document.getElementById(
                'companyForm'
            );

        Object.keys(company)
            .forEach(function(key){

                const field =
                    form.elements[key];

                if (field) {
                    field.value =
                        company[key] ?? '';
                }

            });

        document.getElementById(
            'companyId'
        ).value = company.id;

        document.getElementById(
            'companyModalTitle'
        ).textContent = 'Edit Company';

        openModal('companyModal');

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   DRIVER / VEHICLE EDIT
============================================================ */

async function editDriver(id){

    showToast(
        'Loading driver...',
        'warning'
    );

    try {

        const result =
            await apiRequest(
                'driver',
                'get',
                {
                    id:id
                }
            );

        const data =
            result.data ||
            result.driver;

        const form =
            document.getElementById(
                'driverForm'
            );

        Object.keys(data || {})
            .forEach(function(key){

                const field =
                    form.elements[key];

                if (field) {
                    field.value =
                        data[key] ?? '';
                }

            });

        openModal('driverModal');

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}

async function editVehicle(id){

    try {

        const result =
            await apiRequest(
                'vehicle',
                'get',
                {
                    id:id
                }
            );

        const data =
            result.data ||
            result.vehicle;

        const form =
            document.getElementById(
                'vehicleForm'
            );

        Object.keys(data || {})
            .forEach(function(key){

                const field =
                    form.elements[key];

                if (field) {
                    field.value =
                        data[key] ?? '';
                }

            });

        openModal('vehicleModal');

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    }

}


/* ============================================================
   FORM SUBMISSION
============================================================ */

async function submitForm(
    form,
    apiFile,
    defaultAction,
    modalId,
    successMessage
){

    const submit =
        form.querySelector(
            '[type="submit"]'
        );

    if (submit) {
        submit.classList.add('loading');
        submit.disabled = true;
    }

    try {

        const formData =
            new FormData(form);

        const data = {};

        formData.forEach(
            function(value,key){

                data[key] = value;

            }
        );

        const id =
            data.id || '';

        const action =
            id
                ? 'update'
                : defaultAction;

        await apiRequest(
            apiFile,
            action,
            data
        );

        closeModal(
            document.getElementById(modalId)
        );

        form.reset();

        showToast(
            successMessage
        );

        const entityMap = {
            company:'company',
            user:'user',
            driver:'driver',
            vehicle:'vehicle',
            promotion:'promotion',
            category:'category'
        };

        if (entityMap[apiFile]) {
            loadEntity(
                entityMap[apiFile]
            );
        }

    } catch(error){

        showToast(
            error.message,
            'error'
        );

    } finally {

        if (submit) {
            submit.classList.remove('loading');
            submit.disabled = false;
        }

    }

}


/* ============================================================
   FORMS
============================================================ */

document.getElementById(
    'companyForm'
)?.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        submitForm(
            this,
            'company',
            'create',
            'companyModal',
            'Company saved successfully.'
        );

    }
);


document.getElementById(
    'userForm'
)?.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        submitForm(
            this,
            'user',
            'create',
            'userModal',
            'User saved successfully.'
        );

    }
);


document.getElementById(
    'driverForm'
)?.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        submitForm(
            this,
            'driver',
            'create',
            'driverModal',
            'Driver saved successfully.'
        );

    }
);


document.getElementById(
    'vehicleForm'
)?.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        submitForm(
            this,
            'vehicle',
            'create',
            'vehicleModal',
            'Vehicle saved successfully.'
        );

    }
);


document.getElementById(
    'promotionForm'
)?.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        submitForm(
            this,
            'promotion',
            'create',
            'promotionModal',
            'Promotion saved successfully.'
        );

    }
);


document.getElementById(
    'categoryForm'
)?.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        submitForm(
            this,
            'category',
            'create',
            'categoryModal',
            'Category saved successfully.'
        );

    }
);


/* ============================================================
   SETTINGS
============================================================ */

document.getElementById(
    'saveSettingsBtn'
)?.addEventListener(
    'click',
    async function(){

        const form =
            document.getElementById(
                'settingsForm'
            );

        const data = {};

        new FormData(form)
            .forEach(
                function(value,key){
                    data[key] = value;
                }
            );

        try {

            await apiRequest(
                'settings',
                'update',
                data
            );

            showToast(
                'System settings saved.'
            );

        } catch(error){

            showToast(
                error.message,
                'error'
            );

        }

    }
);


/* ============================================================
   PROFILE
============================================================ */

document.getElementById(
    'profileForm'
)?.addEventListener(
    'submit',
    async function(e){

        e.preventDefault();

        const data = {};

        new FormData(this)
            .forEach(
                function(value,key){
                    data[key] = value;
                }
            );

        try {

            await apiRequest(
                'user',
                'update_profile',
                data
            );

            showToast(
                'Profile updated successfully.'
            );

        } catch(error){

            showToast(
                error.message,
                'error'
            );

        }

    }
);


/* ============================================================
   REPORTS
============================================================ */

document.getElementById(
    'loadReportBtn'
)?.addEventListener(
    'click',
    async function(){

        try {

            const result =
                await apiRequest(
                    'report',
                    'overview',
                    {}
                );

            const data =
                result.data ||
                result.report ||
                {};

            document.getElementById(
                'reportRides'
            ).textContent =
                Number(
                    data.total_rides || 0
                ).toLocaleString();

            document.getElementById(
                'reportCompleted'
            ).textContent =
                Number(
                    data.completed_rides || 0
                ).toLocaleString();

            document.getElementById(
                'reportCancelled'
            ).textContent =
                Number(
                    data.cancelled_rides || 0
                ).toLocaleString();

            document.getElementById(
                'reportRevenue'
            ).textContent =
                Number(
                    data.revenue || 0
                ).toLocaleString();

            showToast(
                'Report generated.'
            );

        } catch(error){

            showToast(
                error.message,
                'error'
            );

        }

    }
);


/* ============================================================
   SEARCH / FILTER
============================================================ */

[
    ['companySearch','company'],
    ['companyStatusFilter','company'],

    ['userSearch','user'],
    ['userRoleFilter','user'],

    ['driverSearch','driver'],
    ['driverStatusFilter','driver'],

    ['vehicleSearch','vehicle'],
    ['vehicleStatusFilter','vehicle'],

    ['rideSearch','ride'],
    ['rideStatusFilter','ride'],

    ['paymentSearch','payment'],
    ['paymentStatusFilter','payment']

].forEach(function(pair){

    const input =
        document.getElementById(
            pair[0]
        );

    if (!input) return;

    input.addEventListener(
        'input',
        debounce(
            function(){
                loadEntity(pair[1]);
            },
            300
        )
    );

    input.addEventListener(
        'change',
        function(){
            loadEntity(pair[1]);
        }
    );

});


function debounce(
    fn,
    delay
){

    let timer;

    return function(){

        clearTimeout(timer);

        const args = arguments;

        timer = setTimeout(
            function(){
                fn.apply(
                    null,
                    args
                );
            },
            delay
        );

    };

}


/* ============================================================
   DASHBOARD REFRESH
============================================================ */

document.getElementById(
    'dashboardRefresh'
)?.addEventListener(
    'click',
    function(){

        window.location.reload();

    }
);


/* ============================================================
   INIT
============================================================ */

console.log(
    'KaryRide Super Admin loaded.'
);

</script>

</body>
</html>
