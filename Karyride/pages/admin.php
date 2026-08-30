<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KaryRide V1 — Client Ride Booking
|--------------------------------------------------------------------------
| - Fast address autocomplete (5 suggestions)
| - Map click to set pickup/destination
| - Nearby drivers from database
| - Real-time route and pricing
|--------------------------------------------------------------------------
*/

$session = require_once __DIR__ . '/auth.php';

if (($session['role'] ?? '') !== 'client') {
    header('Location: ' . (($session['role'] ?? '') === 'driver' ? 'driver.php' : 'login.php'));
    exit;
}

$userId   = (int)($session['user_id'] ?? 0);
$firstName = (string)($session['first_name'] ?? '');
$lastName  = (string)($session['last_name'] ?? '');
$email     = (string)($session['email'] ?? '');
$phone     = (string)($session['phone'] ?? '');

$supportedLanguages = ['en', 'rn'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLanguages, true)) {
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
    return htmlspecialchars((string)($translations[$key] ?? $fallback), ENT_QUOTES, 'UTF-8');
}

$db = null;
$databaseFile = __DIR__ . '/../config/database.php';

if (is_file($databaseFile)) {
    try {
        $db = require $databaseFile;
    } catch (Throwable $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] Client DB error: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../logs/errors.log');
    }
}

$activeRide = null;
$categories = [];

if ($db instanceof mysqli) {
    try {
        $stmt = $db->prepare("
            SELECT
                r.*,
                d.first_name AS driver_first_name,
                d.last_name AS driver_last_name,
                v.plate_number,
                v.brand,
                v.model,
                c.category_name
            FROM rides r
            LEFT JOIN drivers dr ON r.driver_id = dr.id
            LEFT JOIN users d ON dr.user_id = d.id
            LEFT JOIN vehicles v ON r.vehicle_id = v.id
            LEFT JOIN vehicle_categories c ON r.category_id = c.id
            WHERE r.client_id = ?
              AND r.status NOT IN ('completed', 'cancelled')
            ORDER BY r.created_at DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $activeRide = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] Active ride error: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../logs/errors.log');
    }

    try {
        $stmt = $db->prepare("
            SELECT
                c.id,
                c.category_name,
                c.vehicle_type,
                c.icon_url,
                p.base_fare,
                p.price_per_km,
                p.min_fare,
                p.advance_booking_fee
            FROM vehicle_categories c
            LEFT JOIN pricing_plans p ON c.id = p.category_id
            WHERE c.is_active = 1
              AND c.company_id IS NULL
            ORDER BY c.category_name
        ");
        $stmt->execute();
        $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] Categories error: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../logs/errors.log');
    }
}

if (!$categories) {
    $categories = [
        ['id'=>1,'category_name'=>'Standard Taxi','vehicle_type'=>'car','base_fare'=>1000,'price_per_km'=>500,'min_fare'=>1500],
        ['id'=>2,'category_name'=>'Moto','vehicle_type'=>'motorcycle','base_fare'=>500,'price_per_km'=>300,'min_fare'=>800],
        ['id'=>3,'category_name'=>'VIP','vehicle_type'=>'car','base_fare'=>2000,'price_per_km'=>800,'min_fare'=>3000],
    ];
}

function statusText(string $status): string
{
    return [
        'pending' => 'Finding a driver',
        'accepted' => 'Driver assigned',
        'driver_arrived' => 'Driver arrived',
        'in_progress' => 'Ride in progress',
        'destination_reached' => 'Destination reached',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

$initials = strtoupper(substr($firstName ?: 'C', 0, 1) . substr($lastName ?: '', 0, 1));
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title>KaryRide — Client</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../api/dist/leaflet.css">
<script src="../api/dist/leaflet.js"></script>

<style>
:root{
    --bg:#f5f7fb;
    --surface:#fff;
    --surface-2:#f0f3f7;
    --text:#111827;
    --muted:#667085;
    --border:#e4e8ef;
    --primary:#111827;
    --accent:#2563eb;
    --accent-soft:#eaf1ff;
    --success:#16a34a;
    --danger:#dc2626;
    --warning:#f59e0b;
    --shadow:0 18px 50px rgba(15,23,42,.12);
    --radius:16px;
}
body.dark{
    --bg:#07101a;
    --surface:#0d1723;
    --surface-2:#142131;
    --text:#f5f7fa;
    --muted:#9aa8b9;
    --border:#203044;
    --primary:#f5f7fa;
    --accent:#60a5fa;
    --accent-soft:rgba(96,165,250,.13);
    --success:#4ade80;
    --danger:#f87171;
    --warning:#fbbf24;
}
*{box-sizing:border-box;margin:0;padding:0}
body{
    min-height:100vh;background:var(--bg);color:var(--text);
    font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
button,input{font:inherit}
button{cursor:pointer}
a{color:inherit;text-decoration:none}

.topbar{
    height:68px;position:sticky;top:0;z-index:2000;
    background:color-mix(in srgb,var(--surface) 92%,transparent);
    backdrop-filter:blur(18px);
    border-bottom:1px solid var(--border);
}
.topbar-inner{
    height:100%;width:min(1450px,calc(100% - 28px));margin:auto;
    display:flex;align-items:center;justify-content:space-between;gap:16px;
}
.brand{display:flex;align-items:center;gap:10px;font-weight:850;font-size:20px}
.brand-mark{
    width:38px;height:38px;border-radius:11px;background:var(--primary);
    color:var(--surface);display:grid;place-items:center
}
.top-actions{display:flex;align-items:center;gap:5px}
.top-link,.top-button{
    border:0;background:transparent;color:var(--muted);
    min-height:40px;padding:0 11px;border-radius:10px;display:flex;
    align-items:center;gap:8px;font-size:13px;font-weight:650
}
.top-link:hover,.top-button:hover{background:var(--surface-2);color:var(--text)}
.avatar{
    width:34px;height:34px;border-radius:50%;background:var(--accent-soft);
    color:var(--accent);display:grid;place-items:center;font-weight:800
}
.icon-only{width:40px;padding:0;justify-content:center;font-size:18px}
.menu-button{display:none}

.dropdown{position:relative}
.dropdown-panel{
    position:absolute;right:0;top:46px;width:235px;background:var(--surface);
    border:1px solid var(--border);border-radius:14px;padding:7px;
    box-shadow:var(--shadow);display:none
}
.dropdown-panel.open{display:block}
.dropdown-head{padding:10px 11px 12px;border-bottom:1px solid var(--border);margin-bottom:5px}
.dropdown-head strong{display:block;font-size:14px}
.dropdown-head span{display:block;color:var(--muted);font-size:11px;margin-top:2px}
.dropdown-item{
    display:flex;align-items:center;gap:11px;padding:10px 11px;border-radius:9px;
    font-size:13px
}
.dropdown-item:hover{background:var(--surface-2)}
.dropdown-item i{width:19px;color:var(--muted)}
.dropdown-item.danger,.dropdown-item.danger i{color:var(--danger)}

.mobile-menu{
    display:none;position:fixed;inset:68px 0 0;z-index:1900;
    background:var(--surface);padding:14px;overflow:auto
}
.mobile-menu.open{display:block}
.mobile-menu a{
    display:flex;align-items:center;gap:12px;padding:14px;border-radius:12px;
    font-weight:650
}
.mobile-menu a:hover{background:var(--surface-2)}
.mobile-menu i{width:22px;color:var(--muted)}

.app{
    display:grid;grid-template-columns:430px minmax(0,1fr);
    height:calc(100vh - 68px);min-height:600px
}
.sidebar{
    background:var(--surface);border-right:1px solid var(--border);
    padding:22px 19px;overflow:auto
}
.heading{font-size:24px;letter-spacing:-.8px;font-weight:850;margin-bottom:5px}
.subheading{font-size:13px;color:var(--muted);margin-bottom:20px}

.field{position:relative;margin-bottom:12px}
.field label{
    display:block;font-size:11px;font-weight:800;text-transform:uppercase;
    letter-spacing:.45px;color:var(--muted);margin-bottom:5px
}
.input-wrap{position:relative;display:flex;align-items:center}
.input-wrap>i{
    position:absolute;left:14px;top:50%;transform:translateY(-50%);
    z-index:2;color:var(--muted);pointer-events:none
}
.input-wrap input{
    width:100%;height:50px;border:1.5px solid var(--border);
    background:var(--surface-2);color:var(--text);border-radius:12px;
    padding:0 50px 0 42px;font-size:13px;outline:0;flex:1
}
.input-wrap input:focus{
    border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)
}
.input-wrap .btn-group{
    position:absolute;right:6px;top:50%;transform:translateY(-50%);
    display:flex;gap:2px;z-index:3
}
.input-wrap .btn-group button{
    width:34px;height:34px;border:0;border-radius:8px;
    background:transparent;color:var(--muted);
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;transition:all .15s
}
.input-wrap .btn-group button:hover{background:var(--surface-2);color:var(--text)}
.input-wrap .btn-group button.active{background:var(--accent-soft);color:var(--accent)}
.input-wrap .btn-group .clear-input{display:none}
.input-wrap .btn-group .clear-input.show{display:flex}
.map-pick.active{background:var(--accent-soft);color:var(--accent)}
.map-wrap.pick-mode #map{cursor:crosshair}
.pick-hint{
    position:absolute;left:50%;top:20px;transform:translateX(-50%);z-index:850;
    background:var(--primary);color:var(--surface);padding:9px 13px;border-radius:999px;
    font-size:11px;font-weight:800;box-shadow:var(--shadow);display:none;white-space:nowrap
}
.pick-hint.show{display:block}
.suggestions{
    position:absolute;left:0;right:0;top:80px;z-index:1200;
    background:var(--surface);border:1px solid var(--border);border-radius:12px;
    box-shadow:var(--shadow);overflow:hidden;display:none;max-height:280px;overflow-y:auto
}
.suggestions.show{display:block}
.suggestion{
    width:100%;border:0;background:transparent;color:var(--text);
    text-align:left;padding:11px 13px;display:flex;gap:11px;align-items:flex-start;
    cursor:pointer;transition:background .1s
}
.suggestion:hover,.suggestion:focus{background:var(--surface-2)}
.suggestion i{color:var(--accent);margin-top:2px;flex-shrink:0}
.suggestion strong{display:block;font-size:12px;font-weight:750}
.suggestion span{display:block;color:var(--muted);font-size:11px;line-height:1.35;margin-top:2px}

.location-row{
    display:flex;justify-content:space-between;align-items:center;margin:2px 0 14px
}
.use-location{
    border:0;background:var(--accent-soft);color:var(--accent);
    border-radius:9px;padding:8px 10px;font-size:11px;font-weight:750
}
.swap{
    border:0;background:var(--surface-2);color:var(--muted);
    width:34px;height:34px;border-radius:9px
}
.swap:hover{color:var(--text)}

.trip-summary{
    display:none;background:var(--surface-2);border:1px solid var(--border);
    border-radius:13px;padding:12px;margin:8px 0 16px
}
.trip-summary.show{display:block}
.summary-line{display:flex;justify-content:space-between;font-size:12px}
.summary-line+ .summary-line{margin-top:5px}
.summary-line span{color:var(--muted)}
.summary-line strong{font-weight:800}

.section-title{
    display:flex;justify-content:space-between;align-items:center;
    font-size:13px;font-weight:800;margin:15px 0 9px
}
.section-title small{font-size:11px;color:var(--muted);font-weight:600}

.vehicle-list{display:grid;gap:8px}
.vehicle{
    width:100%;display:flex;align-items:center;gap:11px;text-align:left;
    padding:11px;border:1.5px solid var(--border);background:var(--surface);
    border-radius:13px;color:var(--text);transition:.15s;position:relative
}
.vehicle:hover{border-color:var(--accent)}
.vehicle.selected{border-color:var(--accent);background:var(--accent-soft)}
.vehicle.disabled{opacity:.5;cursor:not-allowed}
.vehicle .dist-badge{
    position:absolute;top:-6px;right:8px;
    background:var(--accent);color:#fff;font-size:8px;font-weight:800;
    padding:1px 8px;border-radius:999px;display:none
}
.vehicle .dist-badge.show{display:block}
.vehicle-icon{
    width:42px;height:42px;border-radius:10px;background:var(--surface-2);
    display:grid;place-items:center;font-size:20px;flex:none
}
.vehicle-info{min-width:0;flex:1}
.vehicle-name{font-size:13px;font-weight:800}
.vehicle-desc{font-size:10px;color:var(--muted);margin-top:2px}
.vehicle-price{text-align:right;font-size:13px;font-weight:850;white-space:nowrap}
.vehicle-price small{display:block;color:var(--muted);font-size:9px;font-weight:550}

.nearby{
    display:none;padding:9px 11px;border-radius:10px;background:var(--surface-2);
    color:var(--muted);font-size:11px;margin:9px 0
}
.nearby.show{display:flex;align-items:center;gap:8px}
.nearby .dot{width:7px;height:7px;border-radius:50%;background:var(--success);animation:pulse 1.5s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

.book{
    width:100%;height:54px;border:0;border-radius:13px;background:var(--accent);
    color:#fff;font-weight:850;font-size:15px;margin-top:14px;
    display:flex;align-items:center;justify-content:center;gap:9px
}
.book:disabled{opacity:.45;cursor:not-allowed}
.book.loading .book-label{display:none}
.book .spinner{display:none}
.book.loading .spinner{display:inline-block;animation:spin .8s linear infinite}
.message{font-size:12px;text-align:center;color:var(--muted);min-height:18px;margin-top:9px}
.message.error{color:var(--danger)}
.message.success{color:var(--success)}

.map-wrap{position:relative;min-width:0;background:var(--surface-2)}
#map{width:100%;height:100%;min-height:400px}
.map-card{
    position:absolute;left:20px;top:20px;z-index:800;
    background:var(--surface);border:1px solid var(--border);border-radius:13px;
    box-shadow:var(--shadow);padding:10px 12px;font-size:11px
}
.map-card strong{display:block;font-size:12px}
.map-card span{color:var(--muted)}

.active-ride{
    position:absolute;left:50%;bottom:22px;transform:translateX(-50%);
    width:min(92%,520px);z-index:900;background:var(--surface);
    border:1px solid var(--border);border-radius:15px;box-shadow:var(--shadow);
    padding:14px;display:none
}
.active-ride.show{display:block}
.active-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
.active-head strong{font-size:14px}
.status{
    border-radius:999px;background:var(--accent-soft);color:var(--accent);
    padding:4px 9px;font-size:10px;font-weight:800
}
.active-body{font-size:11px;color:var(--muted);margin-top:8px}
.active-actions{display:flex;gap:7px;margin-top:10px}
.small-btn{
    border:0;border-radius:9px;padding:8px 11px;font-size:11px;font-weight:750
}
.cancel{background:rgba(220,38,38,.1);color:var(--danger)}
.refresh{background:var(--surface-2);color:var(--text)}

.leaflet-control-zoom a{
    background:var(--surface)!important;color:var(--text)!important;
    border-color:var(--border)!important
}
.leaflet-popup-content-wrapper,.leaflet-popup-tip{
    background:var(--surface);color:var(--text)
}

.driver-marker{
    width:30px;height:30px;border-radius:50%;background:#fff;
    border:3px solid var(--accent);box-shadow:0 3px 12px rgba(0,0,0,.22);
    display:grid;place-items:center;color:var(--accent);font-size:14px
}
.pickup-marker,.destination-marker{
    width:18px;height:18px;border-radius:50%;border:3px solid #fff;
    box-shadow:0 2px 10px rgba(0,0,0,.3)
}
.pickup-marker{background:var(--success)}
.destination-marker{background:var(--danger)}

@keyframes spin{to{transform:rotate(360deg)}}

@media(max-width:1050px){
    .app{grid-template-columns:380px 1fr}
    .top-link span{display:none}
}
@media(max-width:820px){
    .topbar{height:60px}
    .topbar-inner{width:calc(100% - 16px)}
    .menu-button{display:flex}
    .desktop-nav{display:none}
    .mobile-menu{inset:60px 0 0}
    .app{
        display:flex;flex-direction:column;height:auto;min-height:calc(100vh - 60px)
    }
    .sidebar{order:1;border-right:0;border-bottom:1px solid var(--border);padding:17px 14px}
    .map-wrap{order:2;height:52vh;min-height:380px}
    #map{min-height:380px}
}
@media(max-width:480px){
    .brand{font-size:18px}
    .brand-mark{width:34px;height:34px}
    .sidebar{padding:14px 11px}
    .heading{font-size:21px}
    .map-wrap{height:48vh;min-height:330px}
    #map{min-height:330px}
    .map-card{left:10px;top:10px}
    .active-ride{bottom:10px}
}
</style>
</head>

<body>

<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="../index.php">
            <span class="brand-mark"><i class="bi bi-sign-turn-right-fill"></i></span>
            <span>KaryRide</span>
        </a>

        <nav class="top-actions desktop-nav">
            <a class="top-link" href="client.php"><i class="bi bi-house-fill"></i><span>Dashboard</span></a>
            <a class="top-link" href="rides.php"><i class="bi bi-clock-history"></i><span>My Rides</span></a>
            <a class="top-link" href="promotions.php"><i class="bi bi-tags-fill"></i><span>Promotions</span></a>
            <a class="top-link" href="settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a>

            <button class="top-button icon-only" id="themeBtn" type="button" aria-label="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>

            <div class="dropdown">
                <button class="top-button" id="profileBtn" type="button">
                    <span class="avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown-panel" id="profilePanel">
                    <div class="dropdown-head">
                        <strong><?= htmlspecialchars(trim($firstName . ' ' . $lastName), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> My Profile</a>
                    <a class="dropdown-item" href="client.php"><i class="bi bi-grid"></i> Dashboard</a>
                    <a class="dropdown-item" href="rides.php"><i class="bi bi-clock-history"></i> My Rides</a>
                    <a class="dropdown-item" href="promotions.php"><i class="bi bi-tags"></i> Promotions</a>
                    <a class="dropdown-item" href="settings.php"><i class="bi bi-gear"></i> Settings</a>
                    <a class="dropdown-item" href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>
                    <a class="dropdown-item danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>

            <div class="dropdown">
                <button class="top-button icon-only" id="languageBtn" type="button" aria-label="Language">
                    <i class="bi bi-translate"></i>
                </button>
                <div class="dropdown-panel" id="languagePanel" style="width:145px">
                    <a class="dropdown-item" href="?lang=en"><i class="bi bi-translate"></i> English</a>
                    <a class="dropdown-item" href="?lang=rn"><i class="bi bi-translate"></i> Kirundi</a>
                </div>
            </div>
        </nav>

        <button class="top-button icon-only menu-button" id="mobileMenuBtn" type="button" aria-label="Open menu">
            <i class="bi bi-list" id="mobileMenuIcon"></i>
        </button>
    </div>
</header>

<div class="mobile-menu" id="mobileMenu">
    <a href="client.php"><i class="bi bi-grid"></i> Dashboard</a>
    <a href="rides.php"><i class="bi bi-clock-history"></i> My Rides / Current Ride</a>
    <a href="promotions.php"><i class="bi bi-tags"></i> Promotions</a>
    <a href="profile.php"><i class="bi bi-person"></i> My Profile</a>
    <a href="settings.php"><i class="bi bi-gear"></i> Settings</a>
    <a href="notifications.php"><i class="bi bi-bell"></i> Notifications</a>
    <a href="?lang=en"><i class="bi bi-translate"></i> English</a>
    <a href="?lang=rn"><i class="bi bi-translate"></i> Kirundi</a>
    <a href="logout.php" style="color:var(--danger)"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<main class="app">

    <aside class="sidebar">
        <h1 class="heading">Where are you going?</h1>
        <p class="subheading">Choose your pickup and destination. KaryRide will calculate the road distance and available vehicles.</p>

        <!-- PICKUP -->
        <div class="field">
            <label for="pickupInput">Pickup location</label>
            <div class="input-wrap">
                <i class="bi bi-circle-fill" style="color:var(--success);font-size:9px"></i>
                <input id="pickupInput" type="text" autocomplete="off" placeholder="Enter pickup address" value="">
                <div class="btn-group">
                    <button class="clear-input" id="clearPickup" type="button" aria-label="Clear pickup"><i class="bi bi-x"></i></button>
                    <button class="map-pick" id="pickPickupOnMap" type="button" aria-label="Choose pickup on map" title="Choose on map"><i class="bi bi-crosshair"></i></button>
                </div>
                <div class="suggestions" id="pickupSuggestions"></div>
            </div>
        </div>

        <div class="location-row">
            <button class="use-location" id="useCurrentLocation" type="button">
                <i class="bi bi-crosshair"></i> Use my current location
            </button>
            <button class="swap" id="swapBtn" type="button" aria-label="Swap locations">
                <i class="bi bi-arrow-down-up"></i>
            </button>
        </div>

        <!-- DESTINATION -->
        <div class="field">
            <label for="destinationInput">Destination</label>
            <div class="input-wrap">
                <i class="bi bi-square-fill" style="color:var(--danger);font-size:9px"></i>
                <input id="destinationInput" type="text" autocomplete="off" placeholder="Where should we take you?" value="">
                <div class="btn-group">
                    <button class="clear-input" id="clearDestination" type="button" aria-label="Clear destination"><i class="bi bi-x"></i></button>
                    <button class="map-pick" id="pickDestinationOnMap" type="button" aria-label="Choose destination on map" title="Choose on map"><i class="bi bi-crosshair"></i></button>
                </div>
                <div class="suggestions" id="destinationSuggestions"></div>
            </div>
        </div>

        <div class="trip-summary" id="tripSummary">
            <div class="summary-line"><span>Road distance</span><strong id="distanceDisplay">0.00 km</strong></div>
            <div class="summary-line"><span>Estimated travel time</span><strong id="durationDisplay">—</strong></div>
        </div>

        <div class="section-title">
            <span>Available vehicles</span>
            <small id="vehicleCount">Choose a route first</small>
        </div>

        <div class="nearby" id="nearbyStatus">
            <span class="dot"></span>
            <span id="nearbyText">Searching for nearby vehicles...</span>
        </div>

        <div class="vehicle-list" id="vehicleList">
            <?php foreach ($categories as $index => $cat): ?>
                <?php
                    $type = strtolower((string)($cat['vehicle_type'] ?? 'car'));
                    $icon = str_contains($type, 'motor') || str_contains($type, 'moto')
                        ? 'bi-bicycle'
                        : (str_contains($type, 'bus') ? 'bi-bus-front' : 'bi-car-front-fill');
                ?>
                <button
                    type="button"
                    class="vehicle <?= $index === 0 ? 'selected' : '' ?>"
                    data-category-id="<?= (int)$cat['id'] ?>"
                    data-base-fare="<?= (float)($cat['base_fare'] ?? 0) ?>"
                    data-price-per-km="<?= (float)($cat['price_per_km'] ?? 0) ?>"
                    data-min-fare="<?= (float)($cat['min_fare'] ?? 0) ?>"
                    data-available="false"
                >
                    <span class="dist-badge" id="distBadge-<?= (int)$cat['id'] ?>">0.0 km</span>
                    <span class="vehicle-icon"><i class="bi <?= $icon ?>"></i></span>
                    <span class="vehicle-info">
                        <span class="vehicle-name"><?= htmlspecialchars((string)$cat['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="vehicle-desc" id="desc-<?= (int)$cat['id'] ?>"><?= htmlspecialchars(ucfirst((string)($cat['vehicle_type'] ?? 'vehicle')), ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                    <span class="vehicle-price" id="price-<?= (int)$cat['id'] ?>">—<small>estimated</small></span>
                </button>
            <?php endforeach; ?>
        </div>

        <button class="book" id="bookBtn" type="button" disabled>
            <i class="bi bi-car-front-fill"></i>
            <span class="book-label">Book Ride</span>
            <span class="spinner"><i class="bi bi-arrow-repeat"></i></span>
        </button>

        <div class="message" id="bookMessage"></div>
    </aside>

    <section class="map-wrap">
        <div id="map"></div>
        <div class="pick-hint" id="pickHint">Click the map to choose a location</div>

        <div class="map-card">
            <strong id="mapStatus">Ready for your trip</strong>
            <span id="mapHint">Allow location access for automatic pickup.</span>
        </div>

        <?php if ($activeRide): ?>
            <div class="active-ride show" id="activeRide">
                <div class="active-head">
                    <strong><i class="bi bi-car-front"></i> Current Ride</strong>
                    <span class="status"><?= htmlspecialchars(statusText((string)$activeRide['status']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="active-body">
                    <div><strong>Pickup:</strong> <?= htmlspecialchars((string)$activeRide['pickup_address'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Destination:</strong> <?= htmlspecialchars((string)$activeRide['destination_address'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($activeRide['driver_first_name'])): ?>
                        <div><strong>Driver:</strong> <?= htmlspecialchars(trim((string)$activeRide['driver_first_name'].' '.(string)$activeRide['driver_last_name']), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
                <div class="active-actions">
                    <?php if (($activeRide['status'] ?? '') === 'pending'): ?>
                        <button class="small-btn cancel" id="cancelRideBtn" type="button"><i class="bi bi-x-circle"></i> Cancel</button>
                    <?php endif; ?>
                    <button class="small-btn refresh" id="refreshRideBtn" type="button"><i class="bi bi-arrow-repeat"></i> Refresh</button>
                    <a class="small-btn refresh" href="rides.php"><i class="bi bi-clock-history"></i> My rides</a>
                </div>
            </div>
        <?php endif; ?>
    </section>

</main>

<script>
'use strict';

/*
|--------------------------------------------------------------------------
| KaryRide Client JavaScript — Fully Fixed
|--------------------------------------------------------------------------
| - Fast address autocomplete (5 suggestions, 300ms debounce)
| - Input → marker sync both ways
| - Map click to set location with reverse geocoding
| - Nearby drivers from database with distance
| - Route calculation via OSRM
|--------------------------------------------------------------------------
*/

const MAP_DEFAULT = { lat: -3.3818, lng: 29.3620, zoom: 14 };
const GEOCODER_URL = 'https://nominatim.openstreetmap.org/search';
const REVERSE_URL = 'https://nominatim.openstreetmap.org/reverse';
const ROUTER_URL = 'https://router.project-osrm.org/route/v1/driving';

/* -----------------------------
   Theme
----------------------------- */
const themeBtn = document.getElementById('themeBtn');
const themeIcon = document.getElementById('themeIcon');

function applyTheme(theme) {
    var dark = theme === 'dark';
    document.body.classList.toggle('dark', dark);
    if (themeIcon) themeIcon.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    localStorage.setItem('karyride_theme', dark ? 'dark' : 'light');
    setTimeout(function() { if (map) map.invalidateSize(); }, 100);
}
var storedTheme = localStorage.getItem('karyride_theme');
applyTheme(storedTheme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));

themeBtn?.addEventListener('click', function() {
    applyTheme(document.body.classList.contains('dark') ? 'light' : 'dark');
});

/* -----------------------------
   Menus
----------------------------- */
var profileBtn = document.getElementById('profileBtn');
var profilePanel = document.getElementById('profilePanel');
var languageBtn = document.getElementById('languageBtn');
var languagePanel = document.getElementById('languagePanel');
var mobileMenuBtn = document.getElementById('mobileMenuBtn');
var mobileMenu = document.getElementById('mobileMenu');
var mobileMenuIcon = document.getElementById('mobileMenuIcon');

profileBtn?.addEventListener('click', function(e) {
    e.stopPropagation();
    if (profilePanel) profilePanel.classList.toggle('open');
    if (languagePanel) languagePanel.classList.remove('open');
});
languageBtn?.addEventListener('click', function(e) {
    e.stopPropagation();
    if (languagePanel) languagePanel.classList.toggle('open');
    if (profilePanel) profilePanel.classList.remove('open');
});
mobileMenuBtn?.addEventListener('click', function(e) {
    e.stopPropagation();
    if (mobileMenu) mobileMenu.classList.toggle('open');
    if (mobileMenuIcon) mobileMenuIcon.className = mobileMenu.classList.contains('open') ? 'bi bi-x-lg' : 'bi bi-list';
});
document.addEventListener('click', function() {
    if (profilePanel) profilePanel.classList.remove('open');
    if (languagePanel) languagePanel.classList.remove('open');
});

/* -----------------------------
   Map Setup
----------------------------- */
var map = L.map('map', { zoomControl: true }).setView(
    [MAP_DEFAULT.lat, MAP_DEFAULT.lng], MAP_DEFAULT.zoom
);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

var pickupPoint = null;
var destinationPoint = null;
var pickupMarker = null;
var destinationMarker = null;
var userMarker = null;
var routeLayer = null;
var nearbyDriverMarkers = [];
var routeDistanceKm = 0;
var routeDurationMin = 0;
var searchTimers = { pickup: null, destination: null };
var nearbyRefreshInterval = null;
var isNearbyLoading = false;
var mapPickMode = null;
var isUpdatingFromMap = false;

/* -----------------------------
   Utility Functions
----------------------------- */
function markerIcon(type) {
    return L.divIcon({
        className: '',
        html: '<div class="' + type + '-marker"></div>',
        iconSize: [18,18],
        iconAnchor: [9,9]
    });
}

function driverIcon(distance) {
    var color = distance < 1 ? '#16a34a' : distance < 3 ? '#f59e0b' : '#dc2626';
    return L.divIcon({
        className: '',
        html: '<div class="driver-marker" style="border-color:' + color + ';color:' + color + ';">' +
            '<i class="bi bi-car-front-fill"></i>' +
            '<span style="position:absolute;bottom:-18px;font-size:8px;font-weight:800;background:rgba(0,0,0,0.7);color:#fff;padding:1px 6px;border-radius:4px;white-space:nowrap;">' +
            distance.toFixed(1) + ' km</span></div>',
        iconSize: [30,30],
        iconAnchor: [15,15]
    });
}

function setMapStatus(title, hint) {
    document.getElementById('mapStatus').textContent = title || 'Ready for your trip';
    document.getElementById('mapHint').textContent = hint || '';
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function(c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];
    });
}

function formatDuration(minutes) {
    var mins = Math.max(1, Math.round(minutes));
    if (mins < 60) return mins + ' min';
    var h = Math.floor(mins / 60);
    var m = mins % 60;
    return m ? h + ' h ' + m + ' min' : h + ' h';
}

function showMessage(text, error, success) {
    var el = document.getElementById('bookMessage');
    el.textContent = text || '';
    el.className = 'message' + (error ? ' error' : '') + (success ? ' success' : '');
}

function updateClearButtons() {
    var pickup = document.getElementById('pickupInput');
    var dest = document.getElementById('destinationInput');
    document.getElementById('clearPickup').classList.toggle('show', !!pickup.value);
    document.getElementById('clearDestination').classList.toggle('show', !!dest.value);
}

/* -----------------------------
   Set Point (Pickup/Destination)
----------------------------- */
function setPoint(type, result, updateInput, moveMap) {
    updateInput = updateInput !== undefined ? updateInput : true;
    moveMap = moveMap !== undefined ? moveMap : true;

    var lat = Number(result.lat);
    var lng = Number(result.lng);

    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

    var point = {
        lat: lat,
        lng: lng,
        address: result.display_name || result.address || ''
    };

    if (type === 'pickup') {
        pickupPoint = point;
        if (pickupMarker) map.removeLayer(pickupMarker);
        pickupMarker = L.marker([lat, lng], { icon: markerIcon('pickup') })
            .addTo(map)
            .bindPopup('<strong>Pickup</strong><br>' + escapeHtml(point.address || 'Selected location'));
        if (updateInput) {
            document.getElementById('pickupInput').value = point.address || lat.toFixed(6) + ', ' + lng.toFixed(6);
        }
    } else {
        destinationPoint = point;
        if (destinationMarker) map.removeLayer(destinationMarker);
        destinationMarker = L.marker([lat, lng], { icon: markerIcon('destination') })
            .addTo(map)
            .bindPopup('<strong>Destination</strong><br>' + escapeHtml(point.address || 'Selected location'));
        if (updateInput) {
            document.getElementById('destinationInput').value = point.address || lat.toFixed(6) + ', ' + lng.toFixed(6);
        }
    }

    if (moveMap) {
        if (pickupPoint && destinationPoint) {
            map.fitBounds(
                L.latLngBounds(
                    [pickupPoint.lat, pickupPoint.lng],
                    [destinationPoint.lat, destinationPoint.lng]
                ),
                { padding: [70, 70], maxZoom: 16 }
            );
        } else {
            map.setView([lat, lng], 16, { animate: true });
        }
    }

    updateClearButtons();
}

/* -----------------------------
   Address Autocomplete (Fast)
----------------------------- */
var pickupInput = document.getElementById('pickupInput');
var destinationInput = document.getElementById('destinationInput');
var pickupSuggestions = document.getElementById('pickupSuggestions');
var destinationSuggestions = document.getElementById('destinationSuggestions');
var clearPickup = document.getElementById('clearPickup');
var clearDestination = document.getElementById('clearDestination');

function hideSuggestions(box) {
    box.classList.remove('show');
    box.innerHTML = '';
}

function renderSuggestions(box, results, type) {
    box.innerHTML = '';
    if (!results || results.length === 0) { hideSuggestions(box); return; }

    results.forEach(function(result) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'suggestion';

        var nameParts = String(result.display_name || '').split(',');
        var title = nameParts.shift()?.trim() || 'Location';
        var detail = nameParts.join(',').trim();

        button.innerHTML = '<i class="bi bi-geo-alt-fill"></i>' +
            '<span><strong>' + escapeHtml(title) + '</strong>' +
            (detail ? '<span>' + escapeHtml(detail) + '</span>' : '') + '</span>';

        button.addEventListener('click', function(e) {
            e.preventDefault();
            // Set the point from suggestion
            setPoint(type, result, true, true);
            hideSuggestions(box);
            // Clear any pending search
            clearTimeout(searchTimers[type]);
            // Update route if both points exist
            if (pickupPoint && destinationPoint) {
                updateRoute();
            }
        });

        box.appendChild(button);
    });

    box.classList.add('show');
}

async function searchAddresses(type, value) {
    var box = type === 'pickup' ? pickupSuggestions : destinationSuggestions;
    var input = type === 'pickup' ? pickupInput : destinationInput;

    var query = value.trim();
    if (query.length < 2) { hideSuggestions(box); return; }

    try {
        var url = new URL(GEOCODER_URL);
        url.searchParams.set('format', 'json');
        url.searchParams.set('q', query);
        url.searchParams.set('limit', '5');
        url.searchParams.set('countrycodes', 'bi');
        url.searchParams.set('addressdetails', '1');
        url.searchParams.set('accept-language', document.documentElement.lang === 'rn' ? 'rn,en' : 'en,rn');

        var response = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' }
        });

        if (!response.ok) throw new Error('Search failed');

        var results = await response.json();

        // Only show if input hasn't changed
        if (input.value.trim() === query) {
            renderSuggestions(box, results, type);
        }
    } catch (error) {
        hideSuggestions(box);
    }
}

function scheduleSearch(type, value) {
    clearTimeout(searchTimers[type]);
    searchTimers[type] = setTimeout(function() {
        searchAddresses(type, value);
    }, 300);
}

// ============================================================
// INPUT EVENTS
// ============================================================

pickupInput.addEventListener('input', function() {
    var val = this.value.trim();
    // Clear existing marker if input is cleared
    if (val === '') {
        pickupPoint = null;
        if (pickupMarker) { map.removeLayer(pickupMarker); pickupMarker = null; }
        invalidateTrip();
    } else {
        // Only search if we don't have an exact match already
        if (!pickupPoint || pickupPoint.address !== val) {
            pickupPoint = null;
            if (pickupMarker) { map.removeLayer(pickupMarker); pickupMarker = null; }
            scheduleSearch('pickup', val);
            invalidateTrip();
        }
    }
    updateClearButtons();
});

destinationInput.addEventListener('input', function() {
    var val = this.value.trim();
    if (val === '') {
        destinationPoint = null;
        if (destinationMarker) { map.removeLayer(destinationMarker); destinationMarker = null; }
        invalidateTrip();
    } else {
        if (!destinationPoint || destinationPoint.address !== val) {
            destinationPoint = null;
            if (destinationMarker) { map.removeLayer(destinationMarker); destinationMarker = null; }
            scheduleSearch('destination', val);
            invalidateTrip();
        }
    }
    updateClearButtons();
});

// Focus to show suggestions
pickupInput.addEventListener('focus', function() {
    if (this.value.trim().length >= 2) {
        scheduleSearch('pickup', this.value);
    }
});

destinationInput.addEventListener('focus', function() {
    if (this.value.trim().length >= 2) {
        scheduleSearch('destination', this.value);
    }
});

// Blur with delay for click
pickupInput.addEventListener('blur', function() {
    setTimeout(function() { hideSuggestions(pickupSuggestions); }, 200);
});
destinationInput.addEventListener('blur', function() {
    setTimeout(function() { hideSuggestions(destinationSuggestions); }, 200);
});

// Click outside to close
document.addEventListener('click', function(e) {
    if (!e.target.closest('.input-wrap') && !e.target.closest('.suggestions')) {
        hideSuggestions(pickupSuggestions);
        hideSuggestions(destinationSuggestions);
    }
});

// ============================================================
// CLEAR BUTTONS
// ============================================================

clearPickup.addEventListener('click', function() {
    pickupInput.value = '';
    pickupPoint = null;
    if (pickupMarker) { map.removeLayer(pickupMarker); pickupMarker = null; }
    invalidateTrip();
    updateClearButtons();
    if (mapPickMode === 'pickup') setMapPickMode(null);
    pickupInput.focus();
});

clearDestination.addEventListener('click', function() {
    destinationInput.value = '';
    destinationPoint = null;
    if (destinationMarker) { map.removeLayer(destinationMarker); destinationMarker = null; }
    invalidateTrip();
    updateClearButtons();
    if (mapPickMode === 'destination') setMapPickMode(null);
    destinationInput.focus();
});

// ============================================================
// MAP PICKING
// ============================================================

var mapWrap = document.querySelector('.map-wrap');
var pickHint = document.getElementById('pickHint');
var pickPickupOnMap = document.getElementById('pickPickupOnMap');
var pickDestinationOnMap = document.getElementById('pickDestinationOnMap');

function setMapPickMode(type) {
    mapPickMode = type;
    mapWrap.classList.toggle('pick-mode', !!type);
    pickPickupOnMap.classList.toggle('active', type === 'pickup');
    pickDestinationOnMap.classList.toggle('active', type === 'destination');

    if (type) {
        pickHint.textContent = type === 'pickup'
            ? 'Click the map to choose your pickup'
            : 'Click the map to choose your destination';
        pickHint.classList.add('show');
        setMapStatus(
            type === 'pickup' ? 'Choose pickup on map' : 'Choose destination on map',
            'Click the exact point on the map.'
        );
    } else {
        pickHint.classList.remove('show');
        setMapStatus('Ready for your trip', '');
    }
}

pickPickupOnMap.addEventListener('click', function() {
    setMapPickMode(mapPickMode === 'pickup' ? null : 'pickup');
});

pickDestinationOnMap.addEventListener('click', function() {
    setMapPickMode(mapPickMode === 'destination' ? null : 'destination');
});

map.on('click', async function(event) {
    if (!mapPickMode) {
        // Natural flow: first click = pickup, second = destination
        if (!pickupPoint) {
            mapPickMode = 'pickup';
        } else if (!destinationPoint) {
            mapPickMode = 'destination';
        } else {
            return;
        }
    }

    var type = mapPickMode;
    var lat = event.latlng.lat;
    var lng = event.latlng.lng;

    setMapStatus('Finding address...', 'Resolving the exact map point.');
    pickHint.textContent = 'Loading address...';

    try {
        var url = new URL(REVERSE_URL);
        url.searchParams.set('format', 'json');
        url.searchParams.set('lat', lat);
        url.searchParams.set('lon', lng);
        url.searchParams.set('zoom', '18');
        url.searchParams.set('addressdetails', '1');

        var response = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' }
        });

        if (!response.ok) throw new Error('Reverse geocoding failed');

        var data = await response.json();

        // Set point and clear any pending searches
        setPoint(type, {
            lat: lat,
            lng: lng,
            display_name: data.display_name || lat.toFixed(6) + ', ' + lng.toFixed(6)
        }, true, true);

        // Clear any pending search for this input
        clearTimeout(searchTimers[type]);

        setMapPickMode(null);
        setMapStatus(
            type === 'pickup' ? 'Pickup selected' : 'Destination selected',
            data.display_name || 'Exact map location selected.'
        );

        if (pickupPoint && destinationPoint) {
            await updateRoute();
        }
    } catch (error) {
        // Keep coordinates even if address lookup fails
        setPoint(type, {
            lat: lat,
            lng: lng,
            display_name: lat.toFixed(6) + ', ' + lng.toFixed(6)
        }, true, true);

        setMapPickMode(null);
        setMapStatus('Location selected', 'Address lookup was unavailable.');
        if (pickupPoint && destinationPoint) {
            await updateRoute();
        }
    }
});

// ============================================================
// CURRENT LOCATION
// ============================================================

document.getElementById('useCurrentLocation').addEventListener('click', getCurrentLocation);

function getCurrentLocation() {
    if (!navigator.geolocation) {
        showMessage('This browser does not support location.', true);
        return;
    }

    setMapStatus('Getting your location...', 'Please allow GPS access.');

    navigator.geolocation.getCurrentPosition(
        async function(position) {
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;

            if (userMarker) map.removeLayer(userMarker);
            userMarker = L.circleMarker([lat, lng], {
                radius: 7,
                color: '#2563eb',
                fillColor: '#2563eb',
                fillOpacity: .25,
                weight: 2
            }).addTo(map).bindPopup('Your current location');

            map.setView([lat, lng], 16);

            try {
                var url = new URL(REVERSE_URL);
                url.searchParams.set('format', 'json');
                url.searchParams.set('lat', lat);
                url.searchParams.set('lon', lng);
                url.searchParams.set('zoom', '18');
                url.searchParams.set('addressdetails', '1');

                var response = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json' }
                });
                var data = await response.json();

                if (data?.display_name) {
                    setPoint('pickup', { lat: lat, lng: lng, display_name: data.display_name }, true, true);
                    setMapStatus('Pickup detected', 'Your current location was used as pickup.');
                    if (pickupPoint && destinationPoint) {
                        await updateRoute();
                    }
                }
            } catch (error) {
                setMapStatus('Location detected', 'Could not resolve the address name.');
                pickupPoint = { lat: lat, lng: lng, address: 'Current location' };
            }
        },
        function() {
            setMapStatus('Location permission needed', 'You can enter the pickup address manually.');
            showMessage('Could not access your current location.', true);
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 30000
        }
    );
}

// ============================================================
// ROUTE
// ============================================================

async function updateRoute() {
    if (!pickupPoint || !destinationPoint) {
        invalidateTrip();
        return;
    }

    setMapStatus('Calculating route...', 'Finding the real road distance and ETA.');

    try {
        var url = ROUTER_URL + '/' + pickupPoint.lng + ',' + pickupPoint.lat + ';' + destinationPoint.lng + ',' + destinationPoint.lat;
        var response = await fetch(url + '?overview=full&geometries=geojson');

        if (!response.ok) throw new Error('Routing failed');

        var data = await response.json();

        if (data.code !== 'Ok' || !data.routes?.length) {
            throw new Error('No route found');
        }

        var route = data.routes[0];

        routeDistanceKm = route.distance / 1000;
        routeDurationMin = route.duration / 60;

        if (routeLayer) map.removeLayer(routeLayer);

        routeLayer = L.geoJSON({
            type: 'Feature',
            geometry: route.geometry
        }, {
            style: {
                weight: 5,
                color: '#2563eb',
                opacity: .7,
                dashArray: '8, 8'
            }
        }).addTo(map);

        document.getElementById('distanceDisplay').textContent = routeDistanceKm.toFixed(2) + ' km';
        document.getElementById('durationDisplay').textContent = formatDuration(routeDurationMin);
        document.getElementById('tripSummary').classList.add('show');

        map.fitBounds(routeLayer.getBounds(), { padding: [45, 45] });

        updatePrices(routeDistanceKm);
        await loadNearbyDrivers();

        setMapStatus('Route ready', routeDistanceKm.toFixed(2) + ' km · ' + formatDuration(routeDurationMin));
        startNearbyRefresh();

    } catch (error) {
        setMapStatus('Route unavailable', 'Check the two locations and try again.');
        showMessage('Could not calculate the road route.', true);
        invalidateTrip(false);
        stopNearbyRefresh();
    }
}

function invalidateTrip(clearMessage) {
    clearMessage = clearMessage !== undefined ? clearMessage : true;

    routeDistanceKm = 0;
    routeDurationMin = 0;

    if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }

    document.getElementById('tripSummary').classList.remove('show');
    document.getElementById('distanceDisplay').textContent = '0.00 km';
    document.getElementById('durationDisplay').textContent = '—';
    document.getElementById('nearbyStatus').classList.remove('show');
    document.getElementById('vehicleCount').textContent = 'Choose a route first';
    document.getElementById('bookBtn').disabled = true;

    document.querySelectorAll('.vehicle').forEach(function(v) {
        v.dataset.available = 'false';
        v.querySelector('.vehicle-price').innerHTML = '—<small>estimated</small>';
        var badge = v.querySelector('.dist-badge');
        if (badge) badge.classList.remove('show');
        v.classList.remove('disabled');
        var desc = v.querySelector('.vehicle-desc');
        var baseDesc = desc.dataset.base || desc.textContent.split(' ·')[0] || 'vehicle';
        if (!desc.dataset.base) desc.dataset.base = baseDesc;
        desc.textContent = baseDesc;
    });

    stopNearbyRefresh();
    clearDriverMarkers();

    if (clearMessage) showMessage('');
}

// ============================================================
// PRICING
// ============================================================

function updatePrices(distance) {
    document.querySelectorAll('.vehicle').forEach(function(vehicle) {
        var base = Number(vehicle.dataset.baseFare || 0);
        var perKm = Number(vehicle.dataset.pricePerKm || 0);
        var minimum = Number(vehicle.dataset.minFare || 0);

        var fare = Math.max(base + (distance * perKm), minimum);

        vehicle.dataset.fare = Math.round(fare);
        var priceEl = vehicle.querySelector('.vehicle-price');
        if (priceEl) {
            priceEl.innerHTML = Math.round(fare).toLocaleString() + ' BIF<small>estimated</small>';
        }
    });
}

// ============================================================
// NEARBY DRIVERS FROM DB
// ============================================================

async function loadNearbyDrivers() {
    if (isNearbyLoading || !pickupPoint) return;
    isNearbyLoading = true;

    var nearbyStatus = document.getElementById('nearbyStatus');
    var nearbyText = document.getElementById('nearbyText');

    nearbyStatus.classList.add('show');
    nearbyText.textContent = 'Searching for nearby vehicles...';

    clearDriverMarkers();

    try {
        var form = new FormData();
        form.append('action', 'nearby_drivers');
        form.append('lat', pickupPoint.lat);
        form.append('lng', pickupPoint.lng);
        form.append('radius_km', '10');

        var response = await fetch('../api/ride.php', {
            method: 'POST',
            body: form
        });

        var data = await response.json();

        if (!data.success || !Array.isArray(data.drivers)) {
            nearbyText.textContent = 'Vehicle availability will be confirmed when you book.';
            markAllVehiclesAvailableFallback();
            isNearbyLoading = false;
            return;
        }

        // Sort by distance (closest first)
        var drivers = data.drivers.sort(function(a, b) {
            return (a.distance_km || 999) - (b.distance_km || 999);
        });

        // Add markers
        drivers.forEach(function(driver) {
            if (!Number.isFinite(Number(driver.lat)) || !Number.isFinite(Number(driver.lng))) return;

            var dist = Number(driver.distance_km || 0);
            var marker = L.marker(
                [Number(driver.lat), Number(driver.lng)],
                { icon: driverIcon(dist) }
            ).addTo(map);

            marker.bindPopup(
                '<strong>KaryRide Vehicle</strong><br>' +
                'Distance: ' + dist.toFixed(1) + ' km' +
                (driver.category_name ? '<br>Category: ' + driver.category_name : '')
            );
            nearbyDriverMarkers.push(marker);
        });

        // Count by category
        var counts = {};
        var closestDistances = {};
        drivers.forEach(function(d) {
            var id = String(d.category_id || '');
            var dist = Number(d.distance_km || 999);
            counts[id] = (counts[id] || 0) + 1;
            if (!closestDistances[id] || dist < closestDistances[id]) {
                closestDistances[id] = dist;
            }
        });

        // Update vehicle cards
        document.querySelectorAll('.vehicle').forEach(function(vehicle) {
            var id = String(vehicle.dataset.categoryId);
            var count = counts[id] || 0;
            var closest = closestDistances[id] || null;

            vehicle.dataset.available = count > 0 ? 'true' : 'false';

            var desc = vehicle.querySelector('.vehicle-desc');
            var baseDesc = desc.dataset.base || desc.textContent.split(' ·')[0] || 'vehicle';
            desc.dataset.base = baseDesc;
            desc.textContent = count > 0 ? baseDesc + ' · ' + count + ' nearby' : baseDesc + ' · unavailable';

            var badge = vehicle.querySelector('.dist-badge');
            if (badge && closest !== null) {
                badge.textContent = closest < 1 ? (closest * 1000).toFixed(0) + ' m' : closest.toFixed(1) + ' km';
                badge.classList.add('show');
            } else if (badge) {
                badge.classList.remove('show');
            }

            vehicle.classList.toggle('disabled', count === 0);
        });

        var totalDrivers = drivers.length;
        document.getElementById('vehicleCount').textContent =
            totalDrivers > 0 ?
            totalDrivers + ' nearby vehicle' + (totalDrivers === 1 ? '' : 's') :
            'No vehicles nearby';

        nearbyText.textContent =
            totalDrivers > 0 ?
            totalDrivers + ' nearby vehicle' + (totalDrivers === 1 ? '' : 's') + ' found — closest ' + (drivers[0]?.distance_km?.toFixed(1) || '?') + ' km' :
            'No nearby vehicles found right now.';

        // Auto-select closest available
        var firstAvailable = document.querySelector('.vehicle:not(.disabled)');
        if (firstAvailable) {
            document.querySelectorAll('.vehicle').forEach(function(v) { v.classList.remove('selected'); });
            firstAvailable.classList.add('selected');
            updateBookState();
        }

        isNearbyLoading = false;

    } catch (error) {
        console.warn('Nearby drivers error:', error);
        markAllVehiclesAvailableFallback();
        nearbyText.textContent = 'Vehicle availability will be confirmed when you book.';
        isNearbyLoading = false;
    }
}

function markAllVehiclesAvailableFallback() {
    document.querySelectorAll('.vehicle').forEach(function(vehicle) {
        vehicle.dataset.available = 'true';
        vehicle.classList.remove('disabled');
        var desc = vehicle.querySelector('.vehicle-desc');
        var baseDesc = desc.dataset.base || desc.textContent.split(' ·')[0] || 'vehicle';
        desc.textContent = baseDesc;
    });
    document.getElementById('vehicleCount').textContent = 'Route calculated';
}

function clearDriverMarkers() {
    nearbyDriverMarkers.forEach(function(marker) { map.removeLayer(marker); });
    nearbyDriverMarkers = [];
}

function startNearbyRefresh() {
    stopNearbyRefresh();
    nearbyRefreshInterval = setInterval(function() {
        if (pickupPoint && destinationPoint) {
            loadNearbyDrivers();
        }
    }, 10000);
}

function stopNearbyRefresh() {
    if (nearbyRefreshInterval) {
        clearInterval(nearbyRefreshInterval);
        nearbyRefreshInterval = null;
    }
}

// ============================================================
// VEHICLE SELECTION
// ============================================================

var vehicles = document.querySelectorAll('.vehicle');

vehicles.forEach(function(vehicle) {
    vehicle.addEventListener('click', function() {
        if (!pickupPoint || !destinationPoint || !routeDistanceKm) return;
        if (this.classList.contains('disabled')) return;

        vehicles.forEach(function(v) { v.classList.remove('selected'); });
        this.classList.add('selected');
        updateBookState();
    });
});

function selectedVehicle() {
    return document.querySelector('.vehicle.selected:not(.disabled)');
}

function updateBookState() {
    var vehicle = selectedVehicle();
    document.getElementById('bookBtn').disabled =
        !(pickupPoint && destinationPoint && routeDistanceKm > 0 && vehicle);
}

// ============================================================
// SWAP
// ============================================================

document.getElementById('swapBtn').addEventListener('click', function() {
    var pickupText = pickupInput.value;
    var destinationText = destinationInput.value;
    var oldPickup = pickupPoint;
    var oldDestination = destinationPoint;

    pickupInput.value = destinationText;
    destinationInput.value = pickupText;
    pickupPoint = oldDestination;
    destinationPoint = oldPickup;

    if (pickupMarker) map.removeLayer(pickupMarker);
    if (destinationMarker) map.removeLayer(destinationMarker);
    pickupMarker = destinationMarker = null;

    if (pickupPoint) {
        pickupMarker = L.marker([pickupPoint.lat, pickupPoint.lng], { icon: markerIcon('pickup') }).addTo(map);
    }
    if (destinationPoint) {
        destinationMarker = L.marker([destinationPoint.lat, destinationPoint.lng], { icon: markerIcon('destination') }).addTo(map);
    }

    updateClearButtons();
    updateRoute();
});

// ============================================================
// BOOK RIDE
// ============================================================

document.getElementById('bookBtn').addEventListener('click', async function() {
    var vehicle = selectedVehicle();

    if (!pickupPoint || !destinationPoint || !vehicle || !routeDistanceKm) {
        showMessage('Complete pickup, destination and vehicle selection first.', true);
        return;
    }

    this.classList.add('loading');
    this.disabled = true;
    showMessage('Checking availability and creating your booking...');

    try {
        var form = new FormData();
        form.append('action', 'book');
        form.append('pickup_address', pickupPoint.address || pickupInput.value.trim());
        form.append('pickup_lat', pickupPoint.lat);
        form.append('pickup_lng', pickupPoint.lng);
        form.append('destination_address', destinationPoint.address || destinationInput.value.trim());
        form.append('destination_lat', destinationPoint.lat);
        form.append('destination_lng', destinationPoint.lng);
        form.append('category_id', vehicle.dataset.categoryId);
        form.append('estimated_distance_km', routeDistanceKm.toFixed(3));
        form.append('estimated_duration_minutes', Math.round(routeDurationMin));
        form.append('estimated_fare', vehicle.dataset.fare || '0');

        var response = await fetch('../api/ride.php', {
            method: 'POST',
            body: form
        });

        var data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'Ride booking failed.');
        }

        showMessage('Ride booked successfully. Finding your driver...', false, true);

        setTimeout(function() {
            window.location.reload();
        }, 1200);

    } catch (error) {
        showMessage(error.message || 'Could not book the ride.', true);
        this.classList.remove('loading');
        updateBookState();
    }
});

// ============================================================
// CURRENT RIDE
// ============================================================

document.getElementById('refreshRideBtn')?.addEventListener('click', function() {
    window.location.reload();
});

document.getElementById('cancelRideBtn')?.addEventListener('click', async function() {
    var rideId = <?= (int)($activeRide['id'] ?? 0) ?>;

    if (!rideId || !confirm('Cancel this ride?')) return;

    var form = new FormData();
    form.append('action', 'cancel');
    form.append('ride_id', rideId);

    try {
        var response = await fetch('../api/ride.php', {
            method: 'POST',
            body: form
        });
        var data = await response.json();

        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Could not cancel the ride.');
        }
    } catch {
        alert('Network error. Please try again.');
    }
});

// ============================================================
// INITIAL GPS
// ============================================================

if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        function(position) {
            var lat = position.coords.latitude;
            var lng = position.coords.longitude;

            if (userMarker) map.removeLayer(userMarker);
            userMarker = L.circleMarker([lat, lng], {
                radius: 7,
                color: '#2563eb',
                fillColor: '#2563eb',
                fillOpacity: .22,
                weight: 2
            }).addTo(map);

            if (!pickupPoint && !pickupInput.value.trim()) {
                reverseCurrentLocation(lat, lng);
            }
        },
        function() {
            setMapStatus('Ready for your trip', 'Enter your pickup address to start.');
        },
        {
            enableHighAccuracy: true,
            timeout: 8000,
            maximumAge: 60000
        }
    );
}

async function reverseCurrentLocation(lat, lng) {
    try {
        var url = new URL(REVERSE_URL);
        url.searchParams.set('format', 'json');
        url.searchParams.set('lat', lat);
        url.searchParams.set('lon', lng);
        url.searchParams.set('zoom', '18');
        url.searchParams.set('addressdetails', '1');

        var response = await fetch(url.toString());
        var data = await response.json();

        if (data.display_name) {
            setPoint('pickup', { lat: lat, lng: lng, display_name: data.display_name }, true, true);
            setMapStatus('Pickup detected', 'Your current location is ready.');
        }
    } catch (error) {}
}

// ============================================================
// INIT
// ============================================================

window.addEventListener('resize', function() {
    setTimeout(function() { map.invalidateSize(); }, 150);
});
setTimeout(function() { map.invalidateSize(); }, 400);
updateClearButtons();
updateBookState();

console.log('KaryRide Client loaded successfully.');
</script>

</body>
</html> 
