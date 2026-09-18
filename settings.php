<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KaryRide V1 — Superadmin Settings Center
|--------------------------------------------------------------------------
| Superadmin only.
|
| Every setting in this file has a matching row in `system_settings`.
| On first load, missing keys are auto-seeded.
|
| Includes the Platform Finance module (settlement engine).
|
| Ride Operations and Drivers & Compliance panels have been removed from
| the UI. Their DB rows still exist and are still readable by other pages.
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ============================================================
   AUTH
   ============================================================ */
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (($role !== 'superadmin') || empty($_SESSION['user_id'])) {
    if ($role === 'driver') {
        header('Location: driver.php');
    } elseif (in_array($role, ['company_owner','company_manager','company_employee'], true)) {
        header('Location: company.php');
    } elseif ($role === 'client') {
        header('Location: client.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

$userId = (int)$_SESSION['user_id'];
$firstName = (string)($_SESSION['first_name'] ?? 'Admin');
$lastName = (string)($_SESSION['last_name'] ?? 'KaryRide');
$displayName = trim($firstName . ' ' . $lastName) ?: 'Super Administrator';
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));

/* ============================================================
   LANGUAGE
   ============================================================ */
$supportedLanguages = ['en', 'rn'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLanguages, true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
$lang = $_SESSION['lang'] ?? 'en';
if (!in_array($lang, $supportedLanguages, true)) {
    $lang = 'en';
}
$translationFile = __DIR__ . '/../translations/' . $lang . '.php';
$translations = is_file($translationFile) ? require $translationFile : [];
if (!is_array($translations)) {
    $translations = [];
}
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   DATABASE
   ============================================================ */
$db = null;
$databaseFile = __DIR__ . '/../config/database.php';
if (is_file($databaseFile)) {
    try {
        $db = require $databaseFile;
    } catch (Throwable $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] settings DB: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../logs/errors.log');
    }
}
if (!$db instanceof mysqli) {
    http_response_code(500);
    exit('Database unavailable.');
}

/* ============================================================
   CSRF
   ============================================================ */
if (empty($_SESSION['settings_csrf'])) {
    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['settings_csrf'];

function requireSettingsCsrf(array $data): void
{
    global $csrf;
    $token = (string)($data['csrf'] ?? '');
    if (!hash_equals($csrf, $token)) {
        throw new RuntimeException('Security validation failed. Refresh the page and try again.');
    }
}
function jsonResponse(bool $success, string $message = '', array $extra = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function requestData(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

/* ============================================================
   SETTINGS CATALOG — 6 groups only
   (Ride Operations and Drivers & Compliance removed)
   ============================================================ */
$settingGroups = [

    'general' => [
        'label' => 'General',
        'icon' => 'bi-sliders2',
        'description' => 'Timezone and platform identity used by live pages.',
        'settings' => [
            ['key'=>'timezone','label'=>'Timezone','type'=>'select','default'=>'Africa/Bujumbura',
             'options'=>['Africa/Bujumbura'=>'Africa/Bujumbura (Bujumbura)','UTC'=>'UTC','Africa/Nairobi'=>'Africa/Nairobi']],
            ['key'=>'site_email','label'=>'Platform email','type'=>'email','default'=>'noreply@karyride.com',
             'help'=>'Used as the From address in transactional emails.'],
        ]
    ],

    'pricing' => [
        'label' => 'Pricing & Commission',
        'icon' => 'bi-cash-coin',
        'description' => 'Financial defaults used by the finance engine and the billing cron.',
        'settings' => [
            ['key'=>'platform_commission_percent','label'=>'Platform commission (%)','type'=>'number','default'=>'5','step'=>'0.01','min'=>0,'max'=>100,
             'help'=>'Default suggestion when setting a month rate.'],
            ['key'=>'commission_grace_period_days','label'=>'Grace period (days)','type'=>'number','default'=>'5','min'=>1,'max'=>30,
             'help'=>'Days a company has to pay before auto-suspension.'],
            ['key'=>'commission_billing_day','label'=>'Billing day of month','type'=>'number','default'=>'5','min'=>1,'max'=>28,
             'help'=>'Day of the month the billing cron sends emails.'],
            ['key'=>'commission_settlement_basis','label'=>'Settlement basis','type'=>'select','default'=>'completed_rides',
             'options'=>['completed_rides'=>'Completed rides','paid_rides'=>'Paid rides'],
             'help'=>'Which rides are eligible for commission.'],
            ['key'=>'commission_min_settlement_amount','label'=>'Minimum settlement amount (BIF)','type'=>'number','default'=>'0','min'=>0,
             'help'=>'Skip generating settlements below this amount.'],
            ['key'=>'commission_auto_suspend','label'=>'Auto-suspend unpaid companies','type'=>'toggle','default'=>'1',
             'help'=>'Suspend company when grace period expires with an unpaid balance.'],
            ['key'=>'fare_rounding','label'=>'Fare rounding','type'=>'select','default'=>'0',
             'options'=>['0'=>'No rounding','100'=>'Nearest 100 BIF','500'=>'Nearest 500 BIF','1000'=>'Nearest 1,000 BIF'],
             'help'=>'Applied to the total fare in client.php book action.'],
        ]
    ],

    'users_security' => [
        'label' => 'Users & Security',
        'icon' => 'bi-shield-lock',
        'description' => 'Authentication rules read by login.php and other security pages.',
        'settings' => [
            ['key'=>'user_registration_enabled','label'=>'Allow client registration','type'=>'toggle','default'=>'1'],
            ['key'=>'password_min_length','label'=>'Minimum password length','type'=>'number','default'=>'8','min'=>6,'max'=>64],
            ['key'=>'login_max_attempts','label'=>'Maximum failed login attempts','type'=>'number','default'=>'5','min'=>1,'max'=>20],
            ['key'=>'login_lockout_minutes','label'=>'Login lockout duration (minutes)','type'=>'number','default'=>'15','min'=>1,'max'=>1440],
            ['key'=>'session_lifetime_minutes','label'=>'Session lifetime (minutes)','type'=>'number','default'=>'120','min'=>15,'max'=>10080],
            ['key'=>'remember_me_days','label'=>'Remember-me lifetime (days)','type'=>'number','default'=>'30','min'=>1,'max'=>365],
            ['key'=>'single_session_mode','label'=>'Restrict account to one active session','type'=>'toggle','default'=>'0'],
        ]
    ],

    'password_reset' => [
        'label' => 'Password Recovery',
        'icon' => 'bi-key',
        'description' => 'Expiry and attempt rules used by forgot-password.php.',
        'settings' => [
            ['key'=>'reset_code_expiry_minutes','label'=>'Reset code expiry (minutes)','type'=>'number','default'=>'15','min'=>5,'max'=>120],
            ['key'=>'reset_max_attempts','label'=>'Maximum reset attempts','type'=>'number','default'=>'3','min'=>1,'max'=>10],
            ['key'=>'reset_resend_cooldown_seconds','label'=>'Reset resend cooldown (seconds)','type'=>'number','default'=>'60','min'=>10,'max'=>3600],
            ['key'=>'reset_requests_per_hour','label'=>'Reset requests per hour / IP','type'=>'number','default'=>'5','min'=>1,'max'=>100],
        ]
    ],

    'email' => [
        'label' => 'Email & SMTP',
        'icon' => 'bi-envelope-at',
        'description' => 'Read by config/mail.php. Password field is intentionally not shown here for security.',
        'settings' => [
            ['key'=>'smtp_enabled','label'=>'Enable SMTP','type'=>'toggle','default'=>'1'],
            ['key'=>'smtp_host','label'=>'SMTP host','type'=>'text','default'=>'smtp.gmail.com'],
            ['key'=>'smtp_port','label'=>'SMTP port','type'=>'number','default'=>'587','min'=>1,'max'=>65535],
            ['key'=>'smtp_encryption','label'=>'SMTP encryption','type'=>'select','default'=>'tls',
             'options'=>['tls'=>'TLS','ssl'=>'SSL','none'=>'None']],
            ['key'=>'smtp_username','label'=>'SMTP username','type'=>'text','default'=>''],
            ['key'=>'smtp_from_email','label'=>'From email','type'=>'email','default'=>'noreply@karyride.com'],
            ['key'=>'smtp_from_name','label'=>'From name','type'=>'text','default'=>'KaryRide'],
        ]
    ],

    'payments' => [
        'label' => 'Payments & Invoices',
        'icon' => 'bi-wallet2',
        'description' => 'Payment methods and prefix rules read by booking.php.',
        'settings' => [
            ['key'=>'payments_enabled','label'=>'Enable payments module','type'=>'toggle','default'=>'1'],
            ['key'=>'cash_enabled','label'=>'Allow cash payments','type'=>'toggle','default'=>'1'],
            ['key'=>'ecocash_manual_enabled','label'=>'Allow manual EcoCash references','type'=>'toggle','default'=>'1'],
            ['key'=>'lumicash_manual_enabled','label'=>'Allow manual Lumicash references','type'=>'toggle','default'=>'1'],
            ['key'=>'card_enabled','label'=>'Allow card payments','type'=>'toggle','default'=>'0'],
            ['key'=>'invoice_auto_create','label'=>'Auto-create invoice after ride completion','type'=>'toggle','default'=>'1'],
            ['key'=>'invoice_prefix','label'=>'Invoice number prefix','type'=>'text','default'=>'KR-INV'],
            ['key'=>'payment_reference_prefix','label'=>'Payment reference prefix','type'=>'text','default'=>'KR-PAY'],
        ]
    ],

];

/* ============================================================
   HIDDEN SETTINGS — kept in DB, not shown in UI
   ============================================================ */
$hiddenDefaults = [
    'smtp_password' => '',
];

/* ============================================================
   DEFAULTS
   ============================================================ */
$defaults = [];
foreach ($settingGroups as $group) {
    foreach ($group['settings'] as $setting) {
        $defaults[$setting['key']] = (string)($setting['default'] ?? '');
    }
}
foreach ($hiddenDefaults as $key => $value) {
    $defaults[$key] = (string)$value;
}

/* ============================================================
   LOAD FROM DB
   ============================================================ */
$settings = $defaults;
$result = $db->query('SELECT setting_key, setting_value FROM system_settings');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
    }
    $result->free();
}

/* ============================================================
   AUTO-SEED MISSING KEYS
   ============================================================ */
$syncReport = ['inserted' => 0, 'missing' => []];
try {
    $existing = [];
    $r = $db->query("SELECT setting_key FROM system_settings");
    if ($r) {
        while ($row = $r->fetch_assoc()) $existing[(string)$row['setting_key']] = true;
        $r->free();
    }

    $ins = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key");
    if ($ins) {
        foreach ($settingGroups as $groupKey => $group) {
            foreach ($group['settings'] as $def) {
                $key = $def['key'];
                if (!isset($existing[$key])) {
                    $value = (string)($def['default'] ?? '');
                    $desc  = (string)($def['help'] ?? $group['description'] ?? 'Platform setting');
                    $ins->bind_param('sss', $key, $value, $desc);
                    $ins->execute();
                    $syncReport['inserted']++;
                    $settings[$key] = $value;
                }
            }
        }
        foreach ($hiddenDefaults as $key => $value) {
            if (!isset($existing[$key])) {
                $value = (string)$value;
                $desc  = 'Hidden setting — see config/mail.php';
                $ins->bind_param('sss', $key, $value, $desc);
                $ins->execute();
                $syncReport['inserted']++;
                $settings[$key] = $value;
            }
        }
        $ins->close();
    }
} catch (Throwable $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] settings sync: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../logs/errors.log');
}

/* ============================================================
   APPLY TIMEZONE
   ============================================================ */
$tz = (string)($settings['timezone'] ?? 'Africa/Bujumbura');
if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Africa/Bujumbura';
date_default_timezone_set($tz);

/* ============================================================
   FINANCE HELPERS
   ============================================================ */
function financeMonthRange(int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    return [$start, $end];
}

function financeRecalcSettlementTotals(mysqli $db, int $settlementId): array
{
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN record_type='ride'
                              AND eligibility_status IN ('included','adjusted')
                         THEN commission_amount ELSE 0 END),0) AS total_commission,
            COALESCE(SUM(CASE WHEN record_type='payment'
                              AND payment_status='confirmed'
                         THEN payment_amount ELSE 0 END),0) AS total_paid,
            COUNT(CASE WHEN record_type='ride' AND eligibility_status='included'  THEN 1 END) AS included_count,
            COUNT(CASE WHEN record_type='ride' AND eligibility_status='excluded'  THEN 1 END) AS excluded_count,
            COUNT(CASE WHEN record_type='ride' AND eligibility_status='exception' THEN 1 END) AS exception_count,
            COUNT(CASE WHEN record_type='ride' AND eligibility_status='adjusted'  THEN 1 END) AS adjusted_count
        FROM platform_finance_ledger
        WHERE parent_id = ?
    ");
    $stmt->bind_param('i', $settlementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalCommission = (float)($row['total_commission'] ?? 0);
    $totalPaid = (float)($row['total_paid'] ?? 0);
    $balance = max(0, $totalCommission - $totalPaid);

    return [
        'total_commission' => $totalCommission,
        'total_paid'       => $totalPaid,
        'balance_due'      => $balance,
        'included_count'   => (int)($row['included_count'] ?? 0),
        'excluded_count'   => (int)($row['excluded_count'] ?? 0),
        'exception_count'  => (int)($row['exception_count'] ?? 0),
        'adjusted_count'   => (int)($row['adjusted_count'] ?? 0),
    ];
}

function financeSettlementStatusFromTotals(array $current, float $balance, float $totalCommission, float $totalPaid): string
{
    if ($current === 'void')     return 'void';
    if ($current === 'disputed') return 'disputed';
    if ($balance <= 0.009)       return 'paid';
    if ($totalPaid > 0.009)      return 'partially_paid';
    if ($current === 'overdue')  return 'overdue';
    return $current ?: 'finalized';
}

/* ============================================================
   API / CRUD
   ============================================================ */
$isApi = isset($_GET['action']) || isset($_POST['action']) || str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
if ($isApi) {
    try {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
        $data = requestData();

        /* ---------- SETTINGS ---------- */
        if ($action === 'load_settings' && $method === 'GET') {
            jsonResponse(true, 'Settings loaded.', ['settings'=>$settings]);
        }

        if ($action === 'save_settings' && $method === 'POST') {
            requireSettingsCsrf($data);
            $incoming = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $saved = 0;
            $db->begin_transaction();

            $upsert = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), description=VALUES(description), updated_at=NOW()');
            if (!$upsert) throw new RuntimeException('Could not prepare settings save query.');

            foreach ($settingGroups as $group) {
                foreach ($group['settings'] as $definition) {
                    $key      = $definition['key'];
                    $isToggle = ($definition['type'] === 'toggle');

                    /* Toggles are always present (0/1). Non-toggles only write
                       when the client actually sent them, so saving one panel
                       never revalidates settings from another. */
                    if (!array_key_exists($key, $incoming)) {
                        if (!$isToggle) continue;
                        $value = '0';
                    } else {
                        $value = is_scalar($incoming[$key]) ? trim((string)$incoming[$key]) : '';
                    }

                    switch ($definition['type']) {
                        case 'toggle':
                            $value = ($value === '1') ? '1' : '0';
                            break;

                        case 'number':
                            if ($value === '' || !is_numeric($value)) {
                                throw new RuntimeException($definition['label'] . ' must be a valid number.');
                            }
                            if (isset($definition['min']) && (float)$value < (float)$definition['min']) {
                                throw new RuntimeException($definition['label'] . ' is below the allowed minimum.');
                            }
                            if (isset($definition['max']) && (float)$value > (float)$definition['max']) {
                                throw new RuntimeException($definition['label'] . ' is above the allowed maximum.');
                            }
                            if (isset($definition['options'])) $value = (string)(int)$value;
                            break;

                        case 'email':
                            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                throw new RuntimeException($definition['label'] . ' must be a valid email address.');
                            }
                            break;

                        case 'select':
                            /* array_keys() returns ints for numeric-looking keys.
                               Cast to string before strict compare, then fall
                               back to default instead of failing the whole save. */
                            $rawOptions = $definition['options'] ?? [];
                            $options    = array_map('strval', array_keys($rawOptions));
                            $value      = (string)$value;

                            if (!in_array($value, $options, true)) {
                                $fallback = isset($definition['default'])
                                    ? (string)$definition['default']
                                    : ($options[0] ?? '');
                                $value = in_array($fallback, $options, true)
                                    ? $fallback
                                    : ($options[0] ?? '');
                            }
                            break;
                    }

                    if (strlen($value) > 10000) {
                        throw new RuntimeException($definition['label'] . ' is too long.');
                    }

                    $description = (string)($definition['help'] ?? $group['description'] ?? 'Platform setting');
                    $upsert->bind_param('sss', $key, $value, $description);
                    $upsert->execute();
                    if ($upsert->affected_rows >= 0) $saved++;
                    $settings[$key] = $value;
                }
            }
            $upsert->close();
            $db->commit();
            $_SESSION['settings_updated_at'] = date('Y-m-d H:i:s');
            jsonResponse(true, $saved . ' settings saved successfully.', ['settings'=>$settings]);
        }

        if ($action === 'reset_group' && $method === 'POST') {
            requireSettingsCsrf($data);
            $groupKey = (string)($data['group'] ?? '');
            if (!isset($settingGroups[$groupKey])) throw new RuntimeException('Unknown settings group.');
            $db->begin_transaction();
            $upsert = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), description=VALUES(description), updated_at=NOW()');
            foreach ($settingGroups[$groupKey]['settings'] as $definition) {
                $key = $definition['key'];
                $value = (string)($definition['default'] ?? '');
                $description = (string)($definition['help'] ?? $settingGroups[$groupKey]['description']);
                $upsert->bind_param('sss', $key, $value, $description);
                $upsert->execute();
                $settings[$key] = $value;
            }
            $upsert->close();
            $db->commit();
            jsonResponse(true, $settingGroups[$groupKey]['label'] . ' reset to defaults.', ['settings'=>$settings]);
        }

        if ($action === 'reset_all' && $method === 'POST') {
            requireSettingsCsrf($data);
            $db->begin_transaction();
            $upsert = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), description=VALUES(description), updated_at=NOW()');
            foreach ($settingGroups as $group) {
                foreach ($group['settings'] as $definition) {
                    $key = $definition['key'];
                    $value = (string)($definition['default'] ?? '');
                    $description = (string)($definition['help'] ?? $group['description']);
                    $upsert->bind_param('sss', $key, $value, $description);
                    $upsert->execute();
                    $settings[$key] = $value;
                }
            }
            $upsert->close();
            $db->commit();
            jsonResponse(true, 'All platform settings restored to defaults.', ['settings'=>$settings]);
        }

        if ($action === 'export_settings' && $method === 'GET') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="karyride-settings-' . date('Y-m-d-His') . '.json"');
            echo json_encode(['application'=>'KaryRide V1','exported_at'=>date('c'),'settings'=>$settings], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'import_settings' && $method === 'POST') {
            requireSettingsCsrf($data);
            if (!isset($_FILES['settings_file']) || !is_uploaded_file($_FILES['settings_file']['tmp_name'])) throw new RuntimeException('Choose a valid settings JSON file.');
            if ($_FILES['settings_file']['size'] > 1024 * 1024) throw new RuntimeException('Settings file is too large.');
            $raw = file_get_contents($_FILES['settings_file']['tmp_name']);
            $decoded = json_decode($raw ?: '', true);
            $incoming = is_array($decoded['settings'] ?? null) ? $decoded['settings'] : [];
            if (!$incoming) throw new RuntimeException('No settings were found in the imported file.');

            $allowedKeys = $defaults;
            $db->begin_transaction();
            $upsert = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), description=VALUES(description), updated_at=NOW()');
            $count = 0;
            foreach ($incoming as $key => $value) {
                if (!array_key_exists((string)$key, $allowedKeys)) continue;
                $value = is_scalar($value) ? trim((string)$value) : '';
                $description = 'Imported KaryRide platform setting';
                $upsert->bind_param('sss', $key, $value, $description);
                $upsert->execute();
                $settings[(string)$key] = $value;
                $count++;
            }
            $upsert->close();
            $db->commit();
            jsonResponse(true, $count . ' settings imported successfully.', ['settings'=>$settings]);
        }

        if ($action === 'cleanup_sessions' && $method === 'POST') {
            requireSettingsCsrf($data);
            $result = $db->query("UPDATE user_sessions SET is_active=0 WHERE expires_at <= NOW() AND is_active=1");
            $affected = $result ? $db->affected_rows : 0;
            jsonResponse(true, $affected . ' expired session(s) deactivated.');
        }

        if ($action === 'diagnostics' && $method === 'GET') {
            $checks = [];
            $checks['database'] = ['ok'=>true,'detail'=>'MySQLi connection is active.'];
            $requiredTables = [
                'users','companies','company_staff','drivers','vehicles','vehicle_categories',
                'pricing_plans','promotions','rides','invoices','payments','reviews',
                'uploaded_documents','user_sessions','system_settings',
                'platform_finance_ledger','platform_commission_rates'
            ];
            $missing = [];
            foreach ($requiredTables as $table) {
                $safe = $db->real_escape_string($table);
                $q = $db->query("SHOW TABLES LIKE '{$safe}'");
                if (!$q || $q->num_rows === 0) $missing[] = $table;
                if ($q) $q->free();
            }
            $checks['schema'] = ['ok'=>count($missing)===0,'detail'=>count($missing)===0?'All core KaryRide tables detected.':'Missing: '.implode(', ', $missing)];

            $requiredCompanyCols = ['suspended_for_nonpayment','suspension_started_at','status_before_nonpayment_suspension','last_billing_email_at'];
            $missingCols = [];
            foreach ($requiredCompanyCols as $col) {
                $safe = $db->real_escape_string($col);
                $q = $db->query("SHOW COLUMNS FROM companies LIKE '{$safe}'");
                if (!$q || $q->num_rows === 0) $missingCols[] = $col;
                if ($q) $q->free();
            }
            $checks['companies_columns'] = ['ok'=>count($missingCols)===0,'detail'=>count($missingCols)===0?'All required companies columns detected.':'Missing: '.implode(', ', $missingCols)];

            $requiredUserCols = ['profile','bio','city','country'];
            $missingUserCols = [];
            foreach ($requiredUserCols as $col) {
                $safe = $db->real_escape_string($col);
                $q = $db->query("SHOW COLUMNS FROM users LIKE '{$safe}'");
                if (!$q || $q->num_rows === 0) $missingUserCols[] = $col;
                if ($q) $q->free();
            }
            $checks['users_profile_columns'] = ['ok'=>count($missingUserCols)===0,'detail'=>count($missingUserCols)===0?'All required profile columns detected.':'Missing: '.implode(', ', $missingUserCols)];

            $missingSettings = [];
            foreach ($defaults as $key => $value) {
                if (!array_key_exists($key, $settings)) $missingSettings[] = $key;
            }
            $checks['settings_sync'] = ['ok'=>count($missingSettings)===0,'detail'=>count($missingSettings)===0?('All '.count($defaults).' settings are synced.'):'Missing: '.implode(', ', $missingSettings)];

            $logsDir = __DIR__ . '/../logs';
            $checks['logs'] = ['ok'=>is_dir($logsDir) && is_writable($logsDir),'detail'=>is_dir($logsDir) ? (is_writable($logsDir) ? 'Logs directory is writable.' : 'Logs directory is not writable.') : 'Logs directory does not exist.'];
            $uploadsDir = __DIR__ . '/../assets/uploads';
            $checks['uploads'] = ['ok'=>is_dir($uploadsDir) && is_writable($uploadsDir),'detail'=>is_dir($uploadsDir) ? (is_writable($uploadsDir) ? 'Uploads directory is writable.' : 'Uploads directory is not writable.') : 'Uploads directory does not exist.'];
            $checks['mail_config'] = ['ok'=>is_file(__DIR__ . '/../config/mail.php'),'detail'=>is_file(__DIR__ . '/../config/mail.php') ? 'config/mail.php detected.' : 'config/mail.php not found.'];
            $checks['leaflet'] = ['ok'=>is_dir(__DIR__ . '/../api/dist'),'detail'=>is_dir(__DIR__ . '/../api/dist') ? 'Local API/dist assets detected.' : 'api/dist was not found.'];
            jsonResponse(true, 'Diagnostics completed.', ['checks'=>$checks, 'sync' => $syncReport]);
        }

        /* ============================================================
           PLATFORM FINANCE MODULE
           ============================================================ */

        if ($action === 'finance_state' && $method === 'GET') {
            $year = (int)($_GET['year'] ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));
            if ($month < 1 || $month > 12) $month = (int)date('n');
            if ($year < 2020 || $year > 2100) $year = (int)date('Y');

            [$periodStart, $periodEnd] = financeMonthRange($year, $month);

            $rs = $db->prepare("SELECT id, rate_percent, settlement_basis, status FROM platform_commission_rates WHERE period_start = ? LIMIT 1");
            $rs->bind_param('s', $periodStart);
            $rs->execute();
            $rateRow = $rs->get_result()->fetch_assoc();
            $rs->close();

            $ss = $db->prepare("
                SELECT l.*, c.company_name, c.status AS company_status, c.suspended_for_nonpayment
                FROM platform_finance_ledger l
                INNER JOIN companies c ON c.id = l.company_id
                WHERE l.record_type = 'settlement'
                  AND l.period_start = ?
                ORDER BY c.company_name ASC
            ");
            $ss->bind_param('s', $periodStart);
            $ss->execute();
            $settlements = $ss->get_result()->fetch_all(MYSQLI_ASSOC);
            $ss->close();

            $summary = [
                'total_settlements' => count($settlements),
                'total_rides' => 0,
                'total_gross' => 0.0,
                'total_commission' => 0.0,
                'total_paid' => 0.0,
                'total_balance' => 0.0,
                'suspended_companies' => 0,
            ];
            foreach ($settlements as $s) {
                $summary['total_rides'] += (int)$s['completed_rides_count'];
                $summary['total_gross'] += (float)$s['gross_revenue'];
                $summary['total_commission'] += (float)$s['total_commission'];
                $summary['total_paid'] += (float)$s['total_paid'];
                $summary['total_balance'] += (float)$s['balance_due'];
                if ((int)$s['suspended_for_nonpayment'] === 1) $summary['suspended_companies']++;
            }

            jsonResponse(true, 'Finance state loaded.', [
                'year' => $year,
                'month' => $month,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'rate' => $rateRow,
                'settlements' => $settlements,
                'summary' => $summary,
            ]);
        }

        if ($action === 'finance_set_rate' && $method === 'POST') {
            requireSettingsCsrf($data);
            $year = (int)($data['year'] ?? 0);
            $month = (int)($data['month'] ?? 0);
            $rate = (float)($data['rate'] ?? 0);
            $basis = (string)($data['basis'] ?? 'completed_rides');

            if ($month < 1 || $month > 12) throw new RuntimeException('Invalid month.');
            if ($rate < 0 || $rate > 100) throw new RuntimeException('Rate must be between 0 and 100.');
            if (!in_array($basis, ['completed_rides','paid_rides'], true)) $basis = 'completed_rides';

            [$periodStart] = financeMonthRange($year, $month);

            $chk = $db->prepare("
                SELECT COUNT(*) AS c FROM platform_finance_ledger
                WHERE record_type='settlement' AND period_start = ?
                  AND settlement_status IN ('finalized','invoiced','overdue','partially_paid','paid')
            ");
            $chk->bind_param('s', $periodStart);
            $chk->execute();
            $locked = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0);
            $chk->close();
            if ($locked > 0) throw new RuntimeException('This month is locked. Rate cannot be changed.');

            $stmt = $db->prepare("
                INSERT INTO platform_commission_rates
                    (period_start, rate_percent, settlement_basis, status, created_by_user_id, updated_by_user_id)
                VALUES (?, ?, ?, 'active', ?, ?)
                ON DUPLICATE KEY UPDATE
                    rate_percent = VALUES(rate_percent),
                    settlement_basis = VALUES(settlement_basis),
                    updated_by_user_id = VALUES(updated_by_user_id)
            ");
            $stmt->bind_param('sdsii', $periodStart, $rate, $basis, $userId, $userId);
            $stmt->execute();
            $stmt->close();

            jsonResponse(true, 'Commission rate saved for ' . $periodStart . '.');
        }

        if ($action === 'finance_calculate' && $method === 'POST') {
            requireSettingsCsrf($data);
            $year = (int)($data['year'] ?? 0);
            $month = (int)($data['month'] ?? 0);
            if ($month < 1 || $month > 12) throw new RuntimeException('Invalid month.');

            [$periodStart, $periodEnd] = financeMonthRange($year, $month);
            $periodEndDateTime = $periodEnd . ' 23:59:59';

            $rs = $db->prepare("SELECT id, rate_percent, settlement_basis FROM platform_commission_rates WHERE period_start = ? LIMIT 1");
            $rs->bind_param('s', $periodStart);
            $rs->execute();
            $rateRow = $rs->get_result()->fetch_assoc();
            $rs->close();
            if (!$rateRow) throw new RuntimeException('Set a commission rate for this month before calculating.');

            $commissionRate = (float)$rateRow['rate_percent'];
            $basis = (string)$rateRow['settlement_basis'];

            $cStmt = $db->prepare("
                SELECT DISTINCT r.company_id
                FROM rides r
                WHERE r.status = 'completed'
                  AND r.completed_at >= ?
                  AND r.completed_at <= ?
            ");
            $cStmt->bind_param('ss', $periodStart, $periodEndDateTime);
            $cStmt->execute();
            $companyIds = [];
            $cRes = $cStmt->get_result();
            while ($row = $cRes->fetch_assoc()) $companyIds[] = (int)$row['company_id'];
            $cStmt->close();

            if (empty($companyIds)) {
                jsonResponse(true, 'No completed rides found for this month.', ['created' => 0, 'updated' => 0]);
            }

            $db->begin_transaction();

            $createdCount = 0;
            $updatedCount = 0;

            $insSettlement = $db->prepare("
                INSERT INTO platform_finance_ledger
                    (record_type, parent_id, company_id,
                     period_start, period_end, settlement_year, settlement_month,
                     commission_rate, settlement_status,
                     completed_rides_count, eligible_rides_count, excluded_rides_count, exception_rides_count,
                     gross_revenue, total_commission, total_paid, balance_due,
                     created_by_user_id, created_at, updated_at)
                VALUES
                    ('settlement', NULL, ?,
                     ?, ?, ?, ?,
                     ?, 'calculated',
                     0, 0, 0, 0,
                     0, 0, 0, 0,
                     ?, NOW(), NOW())
            ");

            $updSettlement = $db->prepare("
                UPDATE platform_finance_ledger
                SET commission_rate = ?,
                    completed_rides_count = ?,
                    eligible_rides_count = ?,
                    excluded_rides_count = ?,
                    exception_rides_count = ?,
                    gross_revenue = ?,
                    total_commission = ?,
                    total_paid = ?,
                    balance_due = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $findSettlement = $db->prepare("
                SELECT id FROM platform_finance_ledger
                WHERE record_type='settlement' AND company_id = ? AND period_start = ?
                LIMIT 1
            ");

            $findRideInLedger = $db->prepare("
                SELECT id FROM platform_finance_ledger WHERE ride_id = ? LIMIT 1
            ");

            $insertRide = $db->prepare("
                INSERT INTO platform_finance_ledger
                    (record_type, parent_id, company_id,
                     period_start, period_end, settlement_year, settlement_month,
                     commission_rate, ride_id, ride_reference, completed_at,
                     ride_amount, invoice_id, invoice_amount,
                     commission_base, commission_amount,
                     eligibility_status, exception_code, exception_notes,
                     created_by_user_id, created_at, updated_at)
                VALUES
                    ('ride', ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?,
                     ?, ?, ?,
                     ?, NOW(), NOW())
            ");

            $fetchRides = $db->prepare("
                SELECT r.id, r.ride_reference, r.completed_at, r.total_amount,
                       i.id AS invoice_id, i.amount_bif AS invoice_amount
                FROM rides r
                LEFT JOIN invoices i ON i.ride_id = r.id
                WHERE r.company_id = ?
                  AND r.status = 'completed'
                  AND r.completed_at >= ?
                  AND r.completed_at <= ?
                ORDER BY r.completed_at ASC
            ");

            foreach ($companyIds as $companyId) {
                $findSettlement->bind_param('is', $companyId, $periodStart);
                $findSettlement->execute();
                $existing = $findSettlement->get_result()->fetch_assoc();
                $findSettlement->free_result();

                if ($existing) {
                    $settlementId = (int)$existing['id'];

                    $lockChk = $db->prepare("SELECT settlement_status FROM platform_finance_ledger WHERE id = ?");
                    $lockChk->bind_param('i', $settlementId);
                    $lockChk->execute();
                    $st = (string)($lockChk->get_result()->fetch_assoc()['settlement_status'] ?? '');
                    $lockChk->close();
                    if (in_array($st, ['finalized','invoiced','overdue','partially_paid','paid','void'], true)) {
                        continue;
                    }
                } else {
                    $insSettlement->bind_param(
                        'isiiid',
                        $companyId,
                        $periodStart, $periodEnd, $year, $month,
                        $commissionRate,
                        $userId
                    );
                    $insSettlement->execute();
                    $settlementId = (int)$insSettlement->insert_id;
                    $createdCount++;
                }

                $fetchRides->bind_param('iss', $companyId, $periodStart, $periodEndDateTime);
                $fetchRides->execute();
                $ridesRes = $fetchRides->get_result();

                $gross = 0.0;
                $commissionTotal = 0.0;
                $included = 0;
                $excluded = 0;
                $exceptions = 0;

                while ($ride = $ridesRes->fetch_assoc()) {
                    $rideId = (int)$ride['id'];

                    $findRideInLedger->bind_param('i', $rideId);
                    $findRideInLedger->execute();
                    $alreadyIn = $findRideInLedger->get_result()->fetch_assoc();
                    $findRideInLedger->free_result();
                    if ($alreadyIn) continue;

                    $rideAmount = (float)$ride['total_amount'];
                    $invoiceAmount = $ride['invoice_amount'] !== null ? (float)$ride['invoice_amount'] : null;

                    $eligibility = 'included';
                    $exceptionCode = null;
                    $exceptionNotes = null;
                    $commissionBase = $rideAmount;

                    if ($rideAmount <= 0) {
                        $eligibility = 'exception';
                        $exceptionCode = 'ZERO_RIDE_AMOUNT';
                        $exceptionNotes = 'Ride total is zero or negative.';
                        $commissionBase = 0;
                        $exceptions++;
                    } elseif ($invoiceAmount !== null && abs($invoiceAmount - $rideAmount) > 0.01) {
                        $eligibility = 'exception';
                        $exceptionCode = 'AMOUNT_MISMATCH';
                        $exceptionNotes = 'Invoice amount does not match ride amount.';
                        $exceptions++;
                    } else {
                        $included++;
                    }

                    $commissionAmount = $eligibility === 'included'
                        ? round($commissionBase * $commissionRate / 100, 2)
                        : 0.0;

                    $gross += $rideAmount;
                    $commissionTotal += $commissionAmount;

                    $insertRide->bind_param(
                        'iisiiidssddisssss',
                        $settlementId,
                        $companyId,
                        $periodStart,
                        $periodEnd,
                        $year,
                        $month,
                        $commissionRate,
                        $rideId,
                        $ride['ride_reference'],
                        $ride['completed_at'],
                        $rideAmount,
                        $ride['invoice_id'],
                        $invoiceAmount,
                        $commissionBase,
                        $commissionAmount,
                        $eligibility,
                        $exceptionCode,
                        $exceptionNotes,
                        $userId
                    );
                    $insertRide->execute();
                }
                $fetchRides->free_result();

                $balance = $commissionTotal;
                $updSettlement->bind_param(
                    'diiiiddddi',
                    $commissionRate,
                    (int)$included + (int)$excluded + (int)$exceptions,
                    $included,
                    $excluded,
                    $exceptions,
                    $gross,
                    $commissionTotal,
                    0.0,
                    $balance,
                    $settlementId
                );
                $updSettlement->execute();

                $updatedCount++;
            }

            $insSettlement->close();
            $updSettlement->close();
            $findSettlement->close();
            $findRideInLedger->close();
            $insertRide->close();
            $fetchRides->close();

            $db->commit();

            jsonResponse(true, "Calculation complete. $createdCount new settlement(s), $updatedCount updated.");
        }

        if ($action === 'finance_settlement_detail' && $method === 'GET') {
            $settlementId = (int)($_GET['settlement_id'] ?? 0);
            if ($settlementId <= 0) throw new RuntimeException('Invalid settlement.');

            $ss = $db->prepare("
                SELECT l.*, c.company_name, c.status AS company_status,
                       c.suspended_for_nonpayment, c.suspension_started_at
                FROM platform_finance_ledger l
                INNER JOIN companies c ON c.id = l.company_id
                WHERE l.id = ? AND l.record_type='settlement'
                LIMIT 1
            ");
            $ss->bind_param('i', $settlementId);
            $ss->execute();
            $settlement = $ss->get_result()->fetch_assoc();
            $ss->close();
            if (!$settlement) throw new RuntimeException('Settlement not found.');

            $rs = $db->prepare("
                SELECT ride_id, ride_reference, completed_at, ride_amount, invoice_amount,
                       commission_rate, commission_base, commission_amount,
                       eligibility_status, exception_code, exception_notes
                FROM platform_finance_ledger
                WHERE parent_id = ? AND record_type='ride'
                ORDER BY completed_at ASC
            ");
            $rs->bind_param('i', $settlementId);
            $rs->execute();
            $rides = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
            $rs->close();

            $ps = $db->prepare("
                SELECT id, payment_reference, payment_method, payment_amount,
                       payment_received_at, payment_status, notes, created_at
                FROM platform_finance_ledger
                WHERE parent_id = ? AND record_type='payment'
                ORDER BY payment_received_at ASC
            ");
            $ps->bind_param('i', $settlementId);
            $ps->execute();
            $payments = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
            $ps->close();

            jsonResponse(true, 'Settlement loaded.', [
                'settlement' => $settlement,
                'rides' => $rides,
                'payments' => $payments,
            ]);
        }

        if ($action === 'finance_finalize' && $method === 'POST') {
            requireSettingsCsrf($data);
            $settlementId = (int)($data['settlement_id'] ?? 0);
            if ($settlementId <= 0) throw new RuntimeException('Invalid settlement.');

            $chk = $db->prepare("SELECT settlement_status FROM platform_finance_ledger WHERE id = ? AND record_type='settlement' LIMIT 1");
            $chk->bind_param('i', $settlementId);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$row) throw new RuntimeException('Settlement not found.');
            if (in_array($row['settlement_status'], ['finalized','invoiced','overdue','partially_paid','paid','void'], true)) {
                throw new RuntimeException('Settlement is already finalized.');
            }

            $totals = financeRecalcSettlementTotals($db, $settlementId);

            $upd = $db->prepare("
                UPDATE platform_finance_ledger
                SET settlement_status = 'finalized',
                    finalized_at = NOW(),
                    finalized_by_user_id = ?,
                    locked_at = NOW(),
                    total_commission = ?,
                    total_paid = ?,
                    balance_due = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $upd->bind_param(
                'idddi',
                $userId,
                $totals['total_commission'],
                $totals['total_paid'],
                $totals['balance_due'],
                $settlementId
            );
            $upd->execute();
            $upd->close();

            jsonResponse(true, 'Settlement finalized and locked.');
        }

        if ($action === 'finance_record_payment' && $method === 'POST') {
            requireSettingsCsrf($data);
            $settlementId = (int)($data['settlement_id'] ?? 0);
            $reference = trim((string)($data['reference'] ?? ''));
            $method = (string)($data['method'] ?? 'bank_transfer');
            $amount = (float)($data['amount'] ?? 0);
            $receivedAt = trim((string)($data['received_at'] ?? date('Y-m-d H:i:s')));
            $notes = trim((string)($data['notes'] ?? ''));

            if ($settlementId <= 0) throw new RuntimeException('Invalid settlement.');
            if ($reference === '') throw new RuntimeException('Payment reference is required.');
            if ($amount <= 0) throw new RuntimeException('Payment amount must be greater than zero.');
            if (!in_array($method, ['cash','bank_transfer','ecocash','lumicash','other'], true)) {
                throw new RuntimeException('Invalid payment method.');
            }
            if (strtotime($receivedAt) === false) $receivedAt = date('Y-m-d H:i:s');

            $chk = $db->prepare("SELECT id, company_id, period_start, period_end, settlement_year, settlement_month, commission_rate, balance_due, settlement_status FROM platform_finance_ledger WHERE id = ? AND record_type='settlement' LIMIT 1");
            $chk->bind_param('i', $settlementId);
            $chk->execute();
            $settlement = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$settlement) throw new RuntimeException('Settlement not found.');
            if (in_array($settlement['settlement_status'], ['void','disputed'], true)) {
                throw new RuntimeException('Cannot record payment against a ' . $settlement['settlement_status'] . ' settlement.');
            }

            $dup = $db->prepare("SELECT id FROM platform_finance_ledger WHERE payment_reference = ? LIMIT 1");
            $dup->bind_param('s', $reference);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $dup->close();
                throw new RuntimeException('A payment with this reference already exists.');
            }
            $dup->close();

            $db->begin_transaction();

            $ins = $db->prepare("
                INSERT INTO platform_finance_ledger
                    (record_type, parent_id, company_id,
                     period_start, period_end, settlement_year, settlement_month,
                     commission_rate,
                     payment_reference, payment_method, payment_amount, payment_received_at, payment_status,
                     notes, created_by_user_id, created_at, updated_at)
                VALUES
                    ('payment', ?, ?,
                     ?, ?, ?, ?,
                     ?,
                     ?, ?, ?, ?, 'confirmed',
                     ?, ?, NOW(), NOW())
            ");
            $ins->bind_param(
                'iisiiidsssssi',
                $settlementId,
                $settlement['company_id'],
                $settlement['period_start'],
                $settlement['period_end'],
                $settlement['settlement_year'],
                $settlement['settlement_month'],
                $settlement['commission_rate'],
                $reference,
                $method,
                $amount,
                $receivedAt,
                $notes,
                $userId
            );
            $ins->execute();
            $ins->close();

            $totals = financeRecalcSettlementTotals($db, $settlementId);
            $newStatus = financeSettlementStatusFromTotals(
                (string)$settlement['settlement_status'],
                $totals['balance_due'],
                $totals['total_commission'],
                $totals['total_paid']
            );
            $reactivatedAt = $newStatus === 'paid' ? date('Y-m-d H:i:s') : null;

            $upd = $db->prepare("
                UPDATE platform_finance_ledger
                SET total_commission = ?,
                    total_paid = ?,
                    balance_due = ?,
                    settlement_status = ?,
                    reactivated_at = COALESCE(?, reactivated_at),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $upd->bind_param(
                'dddssi',
                $totals['total_commission'],
                $totals['total_paid'],
                $totals['balance_due'],
                $newStatus,
                $reactivatedAt,
                $settlementId
            );
            $upd->execute();
            $upd->close();

            $reactivateMsg = '';
            if ($newStatus === 'paid') {
                $co = $db->prepare("
                    UPDATE companies
                    SET status = COALESCE(status_before_nonpayment_suspension, 'approved'),
                        suspended_for_nonpayment = 0,
                        status_before_nonpayment_suspension = NULL,
                        suspension_started_at = NULL
                    WHERE id = ? AND suspended_for_nonpayment = 1
                ");
                $co->bind_param('i', $settlement['company_id']);
                $co->execute();
                if ($co->affected_rows > 0) $reactivateMsg = ' Company reactivated.';
                $co->close();
            }

            $db->commit();

            jsonResponse(true, 'Payment recorded. New status: ' . $newStatus . '.' . $reactivateMsg);
        }

        if ($action === 'finance_export_csv' && $method === 'GET') {
            $year = (int)($_GET['year'] ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));
            $detail = isset($_GET['detail']) && $_GET['detail'] === '1';
            if ($month < 1 || $month > 12) throw new RuntimeException('Invalid month.');

            [$periodStart, $periodEnd] = financeMonthRange($year, $month);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="karyride-finance-' . $periodStart . ($detail ? '-detail' : '') . '.csv"');

            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            if (!$detail) {
                fputcsv($out, ['Company','Period','Rate %','Completed rides','Eligible rides','Gross revenue','Platform commission','Paid','Balance','Status']);
                $ss = $db->prepare("
                    SELECT c.company_name, l.period_start, l.period_end, l.commission_rate,
                           l.completed_rides_count, l.eligible_rides_count,
                           l.gross_revenue, l.total_commission, l.total_paid, l.balance_due,
                           l.settlement_status
                    FROM platform_finance_ledger l
                    INNER JOIN companies c ON c.id = l.company_id
                    WHERE l.record_type='settlement' AND l.period_start = ?
                    ORDER BY c.company_name ASC
                ");
                $ss->bind_param('s', $periodStart);
                $ss->execute();
                $rows = $ss->get_result()->fetch_all(MYSQLI_ASSOC);
                $ss->close();
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['company_name'],
                        $r['period_start'] . ' to ' . $r['period_end'],
                        number_format((float)$r['commission_rate'], 4, '.', ''),
                        (int)$r['completed_rides_count'],
                        (int)$r['eligible_rides_count'],
                        number_format((float)$r['gross_revenue'], 2, '.', ''),
                        number_format((float)$r['total_commission'], 2, '.', ''),
                        number_format((float)$r['total_paid'], 2, '.', ''),
                        number_format((float)$r['balance_due'], 2, '.', ''),
                        $r['settlement_status'],
                    ]);
                }
            } else {
                fputcsv($out, ['Company','Ride reference','Completed at','Ride amount','Invoice amount','Commission rate','Commission base','Commission amount','Eligibility','Exception code','Exception notes']);
                $ds = $db->prepare("
                    SELECT c.company_name, l.ride_reference, l.completed_at,
                           l.ride_amount, l.invoice_amount, l.commission_rate,
                           l.commission_base, l.commission_amount,
                           l.eligibility_status, l.exception_code, l.exception_notes
                    FROM platform_finance_ledger l
                    INNER JOIN companies c ON c.id = l.company_id
                    WHERE l.record_type='ride' AND l.period_start = ?
                    ORDER BY c.company_name ASC, l.completed_at ASC
                ");
                $ds->bind_param('s', $periodStart);
                $ds->execute();
                $rows = $ds->get_result()->fetch_all(MYSQLI_ASSOC);
                $ds->close();
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['company_name'],
                        $r['ride_reference'],
                        $r['completed_at'],
                        $r['ride_amount'] !== null ? number_format((float)$r['ride_amount'], 2, '.', '') : '',
                        $r['invoice_amount'] !== null ? number_format((float)$r['invoice_amount'], 2, '.', '') : '',
                        number_format((float)$r['commission_rate'], 4, '.', ''),
                        $r['commission_base'] !== null ? number_format((float)$r['commission_base'], 2, '.', '') : '',
                        $r['commission_amount'] !== null ? number_format((float)$r['commission_amount'], 2, '.', '') : '',
                        $r['eligibility_status'],
                        $r['exception_code'],
                        $r['exception_notes'],
                    ]);
                }
            }
            fclose($out);
            exit;
        }

        if ($action === 'finance_void' && $method === 'POST') {
            requireSettingsCsrf($data);
            $settlementId = (int)($data['settlement_id'] ?? 0);
            $reason = trim((string)($data['reason'] ?? ''));
            if ($settlementId <= 0) throw new RuntimeException('Invalid settlement.');
            if ($reason === '') throw new RuntimeException('A reason is required to void a settlement.');

            $chk = $db->prepare("SELECT settlement_status FROM platform_finance_ledger WHERE id = ? AND record_type='settlement' LIMIT 1");
            $chk->bind_param('i', $settlementId);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$row) throw new RuntimeException('Settlement not found.');
            if ($row['settlement_status'] === 'paid') throw new RuntimeException('Cannot void a paid settlement.');

            $upd = $db->prepare("UPDATE platform_finance_ledger SET settlement_status='void', notes=CONCAT(COALESCE(notes,''), '\n[VOID] ', ?), updated_at=NOW() WHERE id = ?");
            $upd->bind_param('si', $reason, $settlementId);
            $upd->execute();
            $upd->close();

            jsonResponse(true, 'Settlement voided.');
        }

        jsonResponse(false, 'Invalid settings action.');
    } catch (Throwable $e) {
        if ($db instanceof mysqli && $db->errno === 0) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
        }
        error_log('[' . date('Y-m-d H:i:s') . '] settings action: ' . $e->getMessage() . PHP_EOL, 3, __DIR__ . '/../logs/errors.log');
        jsonResponse(false, $e->getMessage());
    }
}

/* ============================================================
   VIEW HELPERS
   ============================================================ */
$categoryCounts = [];
foreach ($settingGroups as $key => $group) {
    $categoryCounts[$key] = count($group['settings']);
}
$updatedAt = $_SESSION['settings_updated_at'] ?? null;

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#f5f7fb" id="themeMeta">
<title>Admin Settings — KaryRide</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{
    --bg:#f5f7fb;--surface:#fff;--surface-2:#f0f3f7;--surface-3:#e9edf3;
    --text:#111827;--muted:#667085;--faint:#94a3b8;--border:#e4e8ef;--border-strong:#cfd6e1;
    --primary:#111827;--accent:#2563eb;--accent-hover:#1d4ed8;--accent-soft:#eaf1ff;
    --success:#16a34a;--success-soft:rgba(22,163,74,.10);--danger:#dc2626;--danger-soft:rgba(220,38,38,.10);
    --warning:#f59e0b;--warning-soft:rgba(245,158,11,.12);--purple:#7c3aed;--purple-soft:rgba(124,58,237,.10);
    --radius:16px;--radius-lg:10px;--radius-sm:8px;--radius-pill:999px;
    --shadow:0 2px 4px rgba(15,23,42,.04),0 6px 16px rgba(15,23,42,.06),0 20px 40px rgba(15,23,42,.05);
    --shadow-lg:0 4px 8px rgba(15,23,42,.05),0 12px 28px rgba(15,23,42,.08),0 32px 64px rgba(15,23,42,.10);
    --gap:20px;--topbar-h:60px;--ease:cubic-bezier(.2,.8,.2,1);--dur:220ms;
    --font:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
body.dark{
    --bg:#07101a;--surface:#0d1723;--surface-2:#142131;--surface-3:#1a2a3d;--text:#f5f7fa;--muted:#9aa8b9;--faint:#6b788e;
    --border:#1f2e42;--border-strong:#2c3f57;--primary:#f5f7fa;--accent:#60a5fa;--accent-hover:#93c5fd;--accent-soft:rgba(96,165,250,.13);
    --success:#4ade80;--success-soft:rgba(74,222,128,.10);--danger:#f87171;--danger-soft:rgba(248,113,113,.10);
    --warning:#fbbf24;--warning-soft:rgba(251,191,36,.10);--purple:#a78bfa;--purple-soft:rgba(167,139,250,.10);
    --shadow:0 2px 4px rgba(0,0,0,.24),0 6px 16px rgba(0,0,0,.32),0 20px 40px rgba(0,0,0,.30);
    --shadow-lg:0 4px 8px rgba(0,0,0,.28),0 12px 28px rgba(0,0,0,.40),0 32px 64px rgba(0,0,0,.48);
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
body{min-height:100vh;background:var(--bg);color:var(--text);font-family:var(--font);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;overflow-x:hidden}
body.no-scroll{overflow:hidden}
button,input,select,textarea{font:inherit;color:inherit}button{cursor:pointer;border:0;background:none}a{color:inherit;text-decoration:none}
button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:6px}
.scrollless{scrollbar-width:none;-ms-overflow-style:none}.scrollless::-webkit-scrollbar{display:none}

.app-shell{min-height:100vh;display:grid;grid-template-columns:minmax(0,1fr);gap:var(--gap);padding:var(--gap);overflow:hidden}
.main{height:calc(100vh - (var(--gap)*2));min-width:0;min-height:0;background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow-lg);overflow:hidden;display:flex;flex-direction:column}
.topbar{height:var(--topbar-h);min-height:var(--topbar-h);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 18px;gap:12px;background:var(--surface);z-index:20}
.top-left{display:flex;align-items:center;gap:12px;min-width:0}
.page-heading{min-width:0}
.page-heading h1{font-size:15px;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.page-heading p{font-size:11px;color:var(--muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.top-actions{display:flex;align-items:center;gap:4px}
.top-btn{width:36px;height:36px;border-radius:var(--radius-sm);color:var(--muted);display:grid;place-items:center;transition:.18s}
.top-btn:hover{background:var(--surface-2);color:var(--text)}
.profile-wrap{position:relative}
.profile-btn{height:40px;padding:0 8px;border:1px solid var(--border);background:var(--surface);border-radius:var(--radius-sm);display:flex;align-items:center;gap:8px}
.profile-btn:hover{border-color:var(--accent)}
.profile-avatar{width:30px;height:30px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-size:10px;font-weight:800}
.profile-menu{position:absolute;right:0;top:48px;width:230px;padding:7px;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--surface);box-shadow:var(--shadow-lg);display:none;z-index:500}
.profile-menu.show{display:block}
.profile-head{padding:10px;border-bottom:1px solid var(--border);margin-bottom:5px}
.profile-head strong{display:block;font-size:13px}
.profile-head span{display:block;margin-top:2px;color:var(--muted);font-size:11px;word-break:break-all}
.profile-item{display:flex;align-items:center;gap:10px;min-height:38px;padding:0 10px;border-radius:var(--radius-sm);font-size:12.5px}
.profile-item:hover{background:var(--surface-2)}
.profile-item i{width:18px;color:var(--muted)}
.profile-item.danger{color:var(--danger)}

.content{padding:22px 26px 42px;overflow:auto;flex:1;min-height:0}
.section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.section-title h2{font-size:22px;font-weight:800;letter-spacing:-.5px}
.section-title p{margin-top:4px;color:var(--muted);font-size:13px}
.actions-row{display:flex;gap:8px;flex-wrap:wrap}

.btn{display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 14px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;border:1px solid transparent;transition:.18s;white-space:nowrap}
.btn i{font-size:14px}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-hover)}
.btn-ghost{background:var(--surface);border-color:var(--border);color:var(--text)}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent)}
.btn-danger{background:var(--danger-soft);color:var(--danger);border-color:transparent}
.btn-danger:hover{background:var(--danger);color:#fff}
.btn-sm{height:32px;padding:0 10px;font-size:12px}
.btn:disabled{opacity:.5;cursor:not-allowed}

.layout{display:grid;grid-template-columns:230px minmax(0,1fr);gap:22px;align-items:start}
@media (max-width:900px){.layout{grid-template-columns:1fr}}

.side-nav{position:sticky;top:8px;display:flex;flex-direction:column;gap:2px;padding:8px;background:var(--surface-2);border:1px solid var(--border);border-radius:14px;max-height:calc(100vh - 140px);overflow:auto}
.side-nav button{display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border-radius:var(--radius-sm);font-size:12.5px;font-weight:600;color:var(--muted);text-align:left;transition:.15s}
.side-nav button:hover{background:var(--surface);color:var(--text)}
.side-nav button.active{background:var(--surface);color:var(--accent);box-shadow:var(--shadow)}
.side-nav button i{font-size:15px;width:18px;text-align:center}
.side-nav button .count{margin-left:auto;font-size:10px;padding:2px 7px;background:var(--surface-3);border-radius:var(--radius-pill);color:var(--muted);font-weight:700}
.side-nav button.active .count{background:var(--accent-soft);color:var(--accent)}

.panel{display:none}
.panel.active{display:block;animation:fade .25s var(--ease)}
@keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

.card{background:var(--surface-2);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:16px}
.card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:16px;flex-wrap:wrap}
.card-head h3{font-size:15px;font-weight:750;letter-spacing:-.2px}
.card-head p{margin-top:3px;color:var(--muted);font-size:12px;max-width:640px}

.field-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:12.5px;font-weight:600;color:var(--text)}
.field .help{font-size:11px;color:var(--muted);line-height:1.4}
.field input[type=text],.field input[type=email],.field input[type=number],.field input[type=password],.field select,.field textarea{
    height:40px;padding:0 12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;transition:.15s;width:100%
}
.field textarea{height:auto;padding:10px 12px;resize:vertical;min-height:80px;line-height:1.5}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px var(--accent-soft)}

.switch{display:flex;align-items:center;gap:12px;padding:10px 12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);transition:.15s}
.switch:hover{border-color:var(--border-strong)}
.switch input{display:none}
.switch .track{position:relative;width:40px;height:22px;background:var(--surface-3);border-radius:var(--radius-pill);flex-shrink:0;transition:.2s}
.switch .track::after{content:"";position:absolute;top:2px;left:2px;width:18px;height:18px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.switch input:checked + .track{background:var(--accent)}
.switch input:checked + .track::after{transform:translateX(18px)}
.switch .switch-label{display:flex;flex-direction:column;gap:2px;min-width:0}
.switch .switch-label strong{font-size:12.5px;font-weight:600}
.switch .switch-label span{font-size:11px;color:var(--muted);line-height:1.35}

.toast-wrap{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:380px}
.toast{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--accent);border-radius:var(--radius-sm);box-shadow:var(--shadow-lg);font-size:12.5px;animation:slideIn .25s var(--ease)}
.toast.success{border-left-color:var(--success)}
.toast.error{border-left-color:var(--danger)}
.toast.warning{border-left-color:var(--warning)}
.toast i{font-size:16px;flex-shrink:0;margin-top:1px}
.toast.success i{color:var(--success)}.toast.error i{color:var(--danger)}.toast.warning i{color:var(--warning)}.toast.info i{color:var(--accent)}
.toast-body{flex:1;min-width:0;word-break:break-word}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}

.sticky-actions{position:sticky;bottom:-22px;margin:20px -26px -42px;padding:14px 26px;background:var(--surface);border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;z-index:10}
.sticky-actions .meta{font-size:11.5px;color:var(--muted)}
.sticky-actions .btns{display:flex;gap:8px;flex-wrap:wrap}

.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}
.kpi{background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:14px}
.kpi .kpi-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
.kpi .kpi-value{margin-top:6px;font-size:22px;font-weight:800;letter-spacing:-.6px;color:var(--text)}
.kpi .kpi-sub{margin-top:3px;font-size:11px;color:var(--faint)}
.kpi.accent{background:var(--accent-soft);border-color:transparent}
.kpi.accent .kpi-value{color:var(--accent)}
.kpi.warn{background:var(--warning-soft);border-color:transparent}
.kpi.warn .kpi-value{color:var(--warning)}
.kpi.danger{background:var(--danger-soft);border-color:transparent}
.kpi.danger .kpi-value{color:var(--danger)}
.kpi.success{background:var(--success-soft);border-color:transparent}
.kpi.success .kpi-value{color:var(--success)}

.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}
table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:720px}
thead th{padding:11px 12px;background:var(--surface-3);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
tbody td{padding:11px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--surface)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px}

.pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:var(--radius-pill);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.pill.gray{background:var(--surface-3);color:var(--muted)}
.pill.blue{background:var(--accent-soft);color:var(--accent)}
.pill.green{background:var(--success-soft);color:var(--success)}
.pill.red{background:var(--danger-soft);color:var(--danger)}
.pill.orange{background:var(--warning-soft);color:var(--warning)}
.pill.purple{background:var(--purple-soft);color:var(--purple)}

.empty{padding:36px 20px;text-align:center;color:var(--muted)}
.empty i{font-size:32px;color:var(--faint);margin-bottom:8px;display:block}
.empty p{font-size:13px}

.modal-bg{position:fixed;inset:0;background:rgba(9,15,25,.55);backdrop-filter:blur(3px);z-index:800;display:none;align-items:center;justify-content:center;padding:20px}
.modal-bg.show{display:flex}
.modal{width:100%;max-width:720px;max-height:88vh;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow-lg);display:flex;flex-direction:column;overflow:hidden;animation:pop .22s var(--ease)}
.modal.wide{max-width:980px}
@keyframes pop{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:none}}
.modal-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)}
.modal-head h3{font-size:15px;font-weight:750}
.modal-head p{margin-top:2px;font-size:11.5px;color:var(--muted)}
.modal-body{padding:20px;overflow:auto;flex:1}
.modal-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;background:var(--surface-2)}
.icon-close{width:32px;height:32px;border-radius:var(--radius-sm);display:grid;place-items:center;color:var(--muted)}
.icon-close:hover{background:var(--surface-2);color:var(--text)}

.month-picker{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.month-picker select{height:38px;padding:0 12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px}

.diag-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 14px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:8px;font-size:12.5px}
.diag-row .lbl{font-weight:600;display:flex;align-items:center;gap:8px}
.diag-row .det{color:var(--muted);font-size:11.5px;text-align:right;max-width:60%}
.diag-row.ok{border-left:3px solid var(--success)}
.diag-row.bad{border-left:3px solid var(--danger)}

@media (max-width:640px){
    .content{padding:16px 16px 32px}
    .sticky-actions{margin:16px -16px -32px;padding:12px 16px}
    .section-title h2{font-size:18px}
    .topbar{padding:0 12px}
    .page-heading p{display:none}
}
</style>
</head>
<body>

<div class="app-shell">
  <main class="main">

    <header class="topbar">
      <div class="top-left">
        <div class="page-heading">
          <h1>Settings Center</h1>
          <p>Platform configuration · <span id="updatedStamp"><?= $updatedAt ? 'Last saved ' . e($updatedAt) : 'Live synced with DB' ?></span></p>
        </div>
      </div>
      <div class="top-actions">
        <button class="top-btn" id="themeToggle" title="Toggle theme"><i class="bi bi-moon-stars"></i></button>
        <button class="top-btn" id="refreshBtn" title="Reload"><i class="bi bi-arrow-clockwise"></i></button>
        <div class="profile-wrap">
          <button class="profile-btn" id="profileBtn">
            <span class="profile-avatar"><?= e($initials) ?></span>
            <i class="bi bi-chevron-down" style="font-size:11px;color:var(--muted)"></i>
          </button>
          <div class="profile-menu" id="profileMenu">
            <div class="profile-head">
              <strong><?= e($displayName) ?></strong>
              <span>Super Administrator</span>
            </div>
            <a class="profile-item" href="admin.php"><i class="bi bi-speedometer2"></i> Admin Dashboard</a>
            <a class="profile-item" href="?lang=en"><i class="bi bi-translate"></i> English</a>
            <a class="profile-item" href="?lang=rn"><i class="bi bi-translate"></i> Kirundi</a>
            <a class="profile-item danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign out</a>
          </div>
        </div>
      </div>
    </header>

    <div class="content scrollless" id="contentArea">

      <div class="section-head">
        <div class="section-title">
          <h2>Platform Settings</h2>
          <p>Every setting is stored in <span class="mono">system_settings</span> and read live by the pages that use it.</p>
        </div>
        <div class="actions-row">
          <button class="btn btn-ghost btn-sm" id="diagBtn"><i class="bi bi-activity"></i> Diagnostics</button>
          <button class="btn btn-ghost btn-sm" id="exportBtn"><i class="bi bi-download"></i> Export</button>
          <button class="btn btn-ghost btn-sm" id="importBtn"><i class="bi bi-upload"></i> Import</button>
          <button class="btn btn-ghost btn-sm" id="cleanupSessionsBtn"><i class="bi bi-clock-history"></i> Clean sessions</button>
        </div>
      </div>

      <div class="layout">

        <nav class="side-nav scrollless" id="sideNav">
          <?php foreach ($settingGroups as $gk => $g): ?>
            <button data-panel="<?= e($gk) ?>" class="<?= $gk === 'general' ? 'active' : '' ?>">
              <i class="bi <?= e($g['icon']) ?>"></i>
              <span><?= e($g['label']) ?></span>
              <span class="count"><?= (int)$categoryCounts[$gk] ?></span>
            </button>
          <?php endforeach; ?>
          <button data-panel="finance">
            <i class="bi bi-cash-stack"></i>
            <span>Platform Finance</span>
            <span class="count">⚡</span>
          </button>
        </nav>

        <div id="panelsWrap">

          <?php foreach ($settingGroups as $gk => $g): ?>
            <section class="panel <?= $gk === 'general' ? 'active' : '' ?>" data-panel="<?= e($gk) ?>">
              <div class="card">
                <div class="card-head">
                  <div>
                    <h3><?= e($g['label']) ?></h3>
                    <p><?= e($g['description']) ?></p>
                  </div>
                  <button class="btn btn-ghost btn-sm reset-group-btn" data-group="<?= e($gk) ?>">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset group
                  </button>
                </div>

                <div class="field-grid">
                  <?php foreach ($g['settings'] as $s):
                    $key = $s['key'];
                    $val = (string)($settings[$key] ?? $s['default'] ?? '');
                    $type = $s['type'];
                  ?>
                    <div class="field">
                      <?php if ($type === 'toggle'): ?>
                        <label class="switch">
                          <input type="checkbox" data-key="<?= e($key) ?>" <?= $val === '1' ? 'checked' : '' ?>>
                          <span class="track"></span>
                          <span class="switch-label">
                            <strong><?= e($s['label']) ?></strong>
                            <?php if (!empty($s['help'])): ?><span><?= e($s['help']) ?></span><?php endif; ?>
                          </span>
                        </label>

                      <?php elseif ($type === 'select'): ?>
                        <label for="f_<?= e($key) ?>"><?= e($s['label']) ?></label>
                        <select id="f_<?= e($key) ?>" data-key="<?= e($key) ?>">
                          <?php foreach (($s['options'] ?? []) as $ov => $ol): ?>
                            <option value="<?= e((string)$ov) ?>" <?= ((string)$ov === $val) ? 'selected' : '' ?>><?= e($ol) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <?php if (!empty($s['help'])): ?><span class="help"><?= e($s['help']) ?></span><?php endif; ?>

                      <?php elseif ($type === 'number'): ?>
                        <label for="f_<?= e($key) ?>"><?= e($s['label']) ?></label>
                        <input type="number" id="f_<?= e($key) ?>" data-key="<?= e($key) ?>" value="<?= e($val) ?>"
                          <?= isset($s['min']) ? 'min="' . e((string)$s['min']) . '"' : '' ?>
                          <?= isset($s['max']) ? 'max="' . e((string)$s['max']) . '"' : '' ?>
                          <?= isset($s['step']) ? 'step="' . e((string)$s['step']) . '"' : 'step="1"' ?>>
                        <?php if (!empty($s['help'])): ?><span class="help"><?= e($s['help']) ?></span><?php endif; ?>

                      <?php elseif ($type === 'email'): ?>
                        <label for="f_<?= e($key) ?>"><?= e($s['label']) ?></label>
                        <input type="email" id="f_<?= e($key) ?>" data-key="<?= e($key) ?>" value="<?= e($val) ?>">
                        <?php if (!empty($s['help'])): ?><span class="help"><?= e($s['help']) ?></span><?php endif; ?>

                      <?php else: ?>
                        <label for="f_<?= e($key) ?>"><?= e($s['label']) ?></label>
                        <input type="text" id="f_<?= e($key) ?>" data-key="<?= e($key) ?>" value="<?= e($val) ?>">
                        <?php if (!empty($s['help'])): ?><span class="help"><?= e($s['help']) ?></span><?php endif; ?>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </section>
          <?php endforeach; ?>

          <section class="panel" data-panel="finance">
            <div class="card">
              <div class="card-head">
                <div>
                  <h3>Platform Finance &amp; Settlements</h3>
                  <p>Set the monthly commission rate, calculate settlements per company, finalize, and record payments. Paid settlements auto-reactivate suspended companies.</p>
                </div>
              </div>

              <div class="month-picker" style="margin-bottom:16px">
                <label style="font-size:12.5px;font-weight:600">Period:</label>
                <select id="finMonth">
                  <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $currentMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                  <?php endfor; ?>
                </select>
                <select id="finYear">
                  <?php for ($y = $currentYear + 1; $y >= $currentYear - 3; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endfor; ?>
                </select>
                <button class="btn btn-primary btn-sm" id="finLoadBtn"><i class="bi bi-arrow-repeat"></i> Load</button>
              </div>

              <div class="kpi-grid" id="finKpis">
                <div class="kpi accent"><div class="kpi-label">Commission rate</div><div class="kpi-value" id="kpiRate">—</div><div class="kpi-sub" id="kpiBasis">not set</div></div>
                <div class="kpi"><div class="kpi-label">Settlements</div><div class="kpi-value" id="kpiCount">0</div><div class="kpi-sub" id="kpiRides">0 rides</div></div>
                <div class="kpi"><div class="kpi-label">Commission due</div><div class="kpi-value" id="kpiCommission">0</div><div class="kpi-sub">BIF</div></div>
                <div class="kpi success"><div class="kpi-label">Paid</div><div class="kpi-value" id="kpiPaid">0</div><div class="kpi-sub">BIF</div></div>
                <div class="kpi warn"><div class="kpi-label">Outstanding</div><div class="kpi-value" id="kpiBalance">0</div><div class="kpi-sub" id="kpiSuspended">0 suspended</div></div>
              </div>

              <div class="actions-row" style="margin-bottom:14px">
                <button class="btn btn-ghost btn-sm" id="finSetRateBtn"><i class="bi bi-percent"></i> Set rate</button>
                <button class="btn btn-primary btn-sm" id="finCalcBtn"><i class="bi bi-calculator"></i> Calculate settlements</button>
                <button class="btn btn-ghost btn-sm" id="finExportBtn"><i class="bi bi-filetype-csv"></i> Export summary</button>
                <button class="btn btn-ghost btn-sm" id="finExportDetailBtn"><i class="bi bi-list-columns"></i> Export detail</button>
              </div>

              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Company</th><th>Status</th><th>Rides</th>
                      <th>Gross</th><th>Commission</th><th>Paid</th><th>Balance</th><th style="width:180px">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="finTableBody">
                    <tr><td colspan="8"><div class="empty"><i class="bi bi-inbox"></i><p>Load a period to see settlements.</p></div></td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </section>

        </div>
      </div>

      <div class="sticky-actions" id="stickyActions">
        <div class="meta" id="dirtyMeta">No unsaved changes.</div>
        <div class="btns">
          <button class="btn btn-ghost btn-sm" id="resetAllBtn"><i class="bi bi-arrow-counterclockwise"></i> Restore all defaults</button>
          <button class="btn btn-primary" id="saveBtn" disabled><i class="bi bi-check2"></i> Save changes</button>
        </div>
      </div>

    </div>
  </main>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<div class="modal-bg" id="importModal">
  <div class="modal">
    <div class="modal-head">
      <div><h3>Import settings</h3><p>JSON file exported from this page.</p></div>
      <button class="icon-close" data-close="importModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label for="importFile">Settings JSON</label>
        <input type="file" id="importFile" accept="application/json,.json">
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost btn-sm" data-close="importModal">Cancel</button>
      <button class="btn btn-primary btn-sm" id="importConfirm"><i class="bi bi-upload"></i> Import</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="rateModal">
  <div class="modal">
    <div class="modal-head">
      <div><h3>Set commission rate</h3><p id="rateModalPeriod">—</p></div>
      <button class="icon-close" data-close="rateModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <div class="field-grid">
        <div class="field">
          <label for="ratePercent">Rate (%)</label>
          <input type="number" id="ratePercent" min="0" max="100" step="0.01" value="<?= e((string)($settings['platform_commission_percent'] ?? '5')) ?>">
        </div>
        <div class="field">
          <label for="rateBasis">Settlement basis</label>
          <select id="rateBasis">
            <option value="completed_rides" <?= ($settings['commission_settlement_basis'] ?? '') === 'completed_rides' ? 'selected' : '' ?>>Completed rides</option>
            <option value="paid_rides" <?= ($settings['commission_settlement_basis'] ?? '') === 'paid_rides' ? 'selected' : '' ?>>Paid rides</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost btn-sm" data-close="rateModal">Cancel</button>
      <button class="btn btn-primary btn-sm" id="rateSaveBtn"><i class="bi bi-check2"></i> Save rate</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="detailModal">
  <div class="modal wide">
    <div class="modal-head">
      <div><h3 id="detailTitle">Settlement</h3><p id="detailSub">—</p></div>
      <button class="icon-close" data-close="detailModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body" id="detailBody"><div class="empty"><i class="bi bi-hourglass-split"></i><p>Loading…</p></div></div>
    <div class="modal-foot">
      <button class="btn btn-ghost btn-sm" id="detailRecordPayment"><i class="bi bi-cash"></i> Record payment</button>
      <button class="btn btn-ghost btn-sm" id="detailFinalizeBtn"><i class="bi bi-lock"></i> Finalize</button>
      <button class="btn btn-danger btn-sm" id="detailVoidBtn"><i class="bi bi-x-circle"></i> Void</button>
      <button class="btn btn-primary btn-sm" data-close="detailModal">Close</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="payModal">
  <div class="modal">
    <div class="modal-head">
      <div><h3>Record payment</h3><p id="payModalSub">—</p></div>
      <button class="icon-close" data-close="payModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <div class="field-grid">
        <div class="field"><label for="payRef">Reference</label><input type="text" id="payRef" placeholder="e.g. BANK-2025-0001"></div>
        <div class="field"><label for="payMethod">Method</label>
          <select id="payMethod">
            <option value="bank_transfer">Bank transfer</option>
            <option value="cash">Cash</option>
            <option value="ecocash">EcoCash</option>
            <option value="lumicash">Lumicash</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="field"><label for="payAmount">Amount (BIF)</label><input type="number" id="payAmount" min="0" step="0.01"></div>
        <div class="field"><label for="payReceived">Received at</label><input type="text" id="payReceived" value="<?= e(date('Y-m-d H:i:s')) ?>"></div>
        <div class="field" style="grid-column:1/-1"><label for="payNotes">Notes (optional)</label><textarea id="payNotes"></textarea></div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost btn-sm" data-close="payModal">Cancel</button>
      <button class="btn btn-primary btn-sm" id="paySaveBtn"><i class="bi bi-check2"></i> Record</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="diagModal">
  <div class="modal">
    <div class="modal-head">
      <div><h3>Diagnostics</h3><p>Schema, settings sync, and writable paths.</p></div>
      <button class="icon-close" data-close="diagModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body" id="diagBody"><div class="empty"><i class="bi bi-hourglass-split"></i><p>Running checks…</p></div></div>
    <div class="modal-foot"><button class="btn btn-primary btn-sm" data-close="diagModal">Close</button></div>
  </div>
</div>

<script>
(function(){
'use strict';

const CSRF = <?= json_encode($csrf) ?>;
const SETTINGS_GROUPS = <?= json_encode(array_map(fn($g)=>array_map(fn($s)=>['key'=>$s['key'],'type'=>$s['type']],$g['settings']), $settingGroups), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const CURRENT = { year: <?= (int)$currentYear ?>, month: <?= (int)$currentMonth ?>, settlementId: null, settlementRow: null };

const $  = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const nf = (n, d=2) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });

function toast(type, msg){
  const wrap = $('#toastWrap');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  const icons = {success:'bi-check-circle-fill', error:'bi-x-circle-fill', warning:'bi-exclamation-triangle-fill', info:'bi-info-circle-fill'};
  el.innerHTML = `<i class="bi ${icons[type] || icons.info}"></i><div class="toast-body">${esc(msg)}</div>`;
  wrap.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateX(20px)'; setTimeout(() => el.remove(), 250); }, 4000);
}

/* ── API helper with params support (fixes encoded &) ── */
async function api(action, { method='GET', body=null, formData=null, params=null } = {}){
  const url = new URL(window.location.href);
  url.searchParams.set('action', action);
  if (params) Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, String(v)));
  const opts = { method, headers: {} };
  if (formData){ opts.body = formData; }
  else if (body){
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(Object.assign({ csrf: CSRF }, body));
  }
  const res = await fetch(url.toString(), opts);
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch(e){ throw new Error('Server returned invalid JSON: ' + text.slice(0, 200)); }
  if (!data.success) throw new Error(data.message || 'Request failed');
  return data;
}

(function initTheme(){
  const saved = localStorage.getItem('kr_theme') || 'light';
  if (saved === 'dark') document.body.classList.add('dark');
  const meta = $('#themeMeta');
  if (meta) meta.setAttribute('content', saved === 'dark' ? '#07101a' : '#f5f7fb');
})();
$('#themeToggle')?.addEventListener('click', () => {
  document.body.classList.toggle('dark');
  const dark = document.body.classList.contains('dark');
  localStorage.setItem('kr_theme', dark ? 'dark' : 'light');
  const meta = $('#themeMeta');
  if (meta) meta.setAttribute('content', dark ? '#07101a' : '#f5f7fb');
  const icon = $('#themeToggle i');
  if (icon) icon.className = dark ? 'bi bi-sun' : 'bi bi-moon-stars';
});

$('#profileBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  $('#profileMenu')?.classList.toggle('show');
});
document.addEventListener('click', (e) => {
  if (!e.target.closest('.profile-wrap')) $('#profileMenu')?.classList.remove('show');
});

$$('#sideNav button').forEach(btn => {
  btn.addEventListener('click', () => {
    const panel = btn.dataset.panel;
    $$('#sideNav button').forEach(b => b.classList.toggle('active', b === btn));
    $$('.panel').forEach(p => p.classList.toggle('active', p.dataset.panel === panel));
    if (panel === 'finance') loadFinance();
  });
});

let dirty = false;
function markDirty(){
  dirty = true;
  $('#saveBtn').disabled = false;
  $('#dirtyMeta').textContent = 'Unsaved changes — click Save to apply.';
}

function collectSettings(){
  const out = {};
  $$('[data-key]').forEach(el => {
    const key = el.dataset.key;
    if (el.type === 'checkbox') out[key] = el.checked ? '1' : '0';
    else out[key] = el.value;
  });
  return out;
}

document.addEventListener('input', (e) => {
  if (e.target.closest('[data-key]')) markDirty();
});
document.addEventListener('change', (e) => {
  if (e.target.closest('[data-key]')) markDirty();
});

$('#saveBtn')?.addEventListener('click', async () => {
  const btn = $('#saveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving…';
  try {
    const payload = { settings: collectSettings() };
    const res = await api('save_settings', { method: 'POST', body: payload });
    toast('success', res.message || 'Settings saved.');
    dirty = false;
    $('#dirtyMeta').textContent = 'All changes saved.';
    $('#updatedStamp').textContent = 'Last saved ' + new Date().toLocaleTimeString();
  } catch(err){
    toast('error', err.message);
  } finally {
    btn.disabled = !dirty;
    btn.innerHTML = '<i class="bi bi-check2"></i> Save changes';
  }
});

$$('.reset-group-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const group = btn.dataset.group;
    if (!confirm('Reset this group to defaults?')) return;
    try {
      const res = await api('reset_group', { method: 'POST', body: { group } });
      toast('success', res.message);
      applySettingsToForm(res.settings);
    } catch(err){ toast('error', err.message); }
  });
});

$('#resetAllBtn')?.addEventListener('click', async () => {
  if (!confirm('Restore ALL platform settings to their defaults? This affects every group.')) return;
  try {
    const res = await api('reset_all', { method: 'POST' });
    toast('success', res.message);
    applySettingsToForm(res.settings);
  } catch(err){ toast('error', err.message); }
});

function applySettingsToForm(settings){
  $$('[data-key]').forEach(el => {
    const key = el.dataset.key;
    if (!(key in settings)) return;
    const v = String(settings[key]);
    if (el.type === 'checkbox') el.checked = (v === '1');
    else el.value = v;
  });
  dirty = false;
  $('#saveBtn').disabled = true;
  $('#dirtyMeta').textContent = 'No unsaved changes.';
}

$('#exportBtn')?.addEventListener('click', () => {
  window.location.href = '?action=export_settings';
});

$('#importBtn')?.addEventListener('click', () => {
  $('#importModal').classList.add('show');
});

$('#importConfirm')?.addEventListener('click', async () => {
  const file = $('#importFile').files[0];
  if (!file){ toast('warning', 'Choose a JSON file first.'); return; }
  const fd = new FormData();
  fd.append('settings_file', file);
  fd.append('csrf', CSRF);
  const btn = $('#importConfirm');
  btn.disabled = true;
  try {
    const url = new URL(window.location.href);
    url.searchParams.set('action', 'import_settings');
    const res = await fetch(url.toString(), { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) throw new Error(data.message);
    toast('success', data.message);
    applySettingsToForm(data.settings);
    $('#importModal').classList.remove('show');
    $('#importFile').value = '';
  } catch(err){
    toast('error', err.message);
  } finally { btn.disabled = false; }
});

$('#cleanupSessionsBtn')?.addEventListener('click', async () => {
  if (!confirm('Deactivate all expired sessions now?')) return;
  try {
    const res = await api('cleanup_sessions', { method: 'POST' });
    toast('success', res.message);
  } catch(err){ toast('error', err.message); }
});

$('#diagBtn')?.addEventListener('click', async () => {
  $('#diagModal').classList.add('show');
  $('#diagBody').innerHTML = '<div class="empty"><i class="bi bi-hourglass-split"></i><p>Running checks…</p></div>';
  try {
    const res = await api('diagnostics');
    renderDiagnostics(res.checks || {}, res.sync || {});
  } catch(err){
    $('#diagBody').innerHTML = `<div class="diag-row bad"><span class="lbl"><i class="bi bi-x-circle-fill" style="color:var(--danger)"></i> Error</span><span class="det">${esc(err.message)}</span></div>`;
  }
});

function renderDiagnostics(checks, sync){
  let html = '';
  Object.entries(checks).forEach(([key, c]) => {
    const label = key.replace(/_/g,' ').replace(/\b\w/g, m => m.toUpperCase());
    html += `<div class="diag-row ${c.ok ? 'ok' : 'bad'}">
      <span class="lbl"><i class="bi ${c.ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}" style="color:var(--${c.ok ? 'success' : 'danger'})"></i> ${esc(label)}</span>
      <span class="det">${esc(c.detail || '')}</span>
    </div>`;
  });
  if (sync.inserted > 0){
    html += `<div class="diag-row ok"><span class="lbl"><i class="bi bi-plus-circle-fill" style="color:var(--success)"></i> Auto-seeded</span><span class="det">${sync.inserted} missing key(s) written to system_settings.</span></div>`;
  }
  $('#diagBody').innerHTML = html || '<div class="empty"><p>No checks returned.</p></div>';
}

async function loadFinance(){
  try {
    const y = +$('#finYear').value, m = +$('#finMonth').value;
    CURRENT.year = y; CURRENT.month = m;
    const res = await api('finance_state', { params: { year: y, month: m } });
    renderFinance(res);
  } catch(err){ toast('error', err.message); }
}

function renderFinance(res){
  const s = res.summary || {};
  $('#kpiRate').textContent = res.rate ? (Number(res.rate.rate_percent).toFixed(2) + '%') : '—';
  $('#kpiBasis').textContent = res.rate ? String(res.rate.settlement_basis).replace('_',' ') : 'not set';
  $('#kpiCount').textContent = nf(s.total_settlements, 0);
  $('#kpiRides').textContent = nf(s.total_rides, 0) + ' rides';
  $('#kpiCommission').textContent = nf(s.total_commission, 0);
  $('#kpiPaid').textContent = nf(s.total_paid, 0);
  $('#kpiBalance').textContent = nf(s.total_balance, 0);
  $('#kpiSuspended').textContent = nf(s.suspended_companies, 0) + ' suspended';

  const tbody = $('#finTableBody');
  const list = res.settlements || [];
  if (!list.length){
    tbody.innerHTML = `<tr><td colspan="8"><div class="empty"><i class="bi bi-inbox"></i><p>No settlements for this period. Click <strong>Calculate settlements</strong> to generate.</p></div></td></tr>`;
    return;
  }
  tbody.innerHTML = list.map(r => {
    const st = String(r.settlement_status || 'draft');
    const cls = {paid:'green', partially_paid:'orange', overdue:'red', finalized:'blue', calculated:'gray', void:'gray', disputed:'red'}[st] || 'gray';
    return `<tr data-id="${r.id}">
      <td><strong>${esc(r.company_name)}</strong>${Number(r.suspended_for_nonpayment) === 1 ? ' <span class="pill red">suspended</span>' : ''}</td>
      <td><span class="pill ${cls}">${esc(st.replace('_',' '))}</span></td>
      <td>${nf(r.eligible_rides_count || 0, 0)} / ${nf(r.completed_rides_count || 0, 0)}</td>
      <td class="mono">${nf(r.gross_revenue, 0)}</td>
      <td class="mono">${nf(r.total_commission, 0)}</td>
      <td class="mono">${nf(r.total_paid, 0)}</td>
      <td class="mono"><strong>${nf(r.balance_due, 0)}</strong></td>
      <td><button class="btn btn-ghost btn-sm view-settlement" data-id="${r.id}"><i class="bi bi-eye"></i> Open</button></td>
    </tr>`;
  }).join('');

  $$('.view-settlement').forEach(b => b.addEventListener('click', () => openSettlement(+b.dataset.id)));
}

$('#finLoadBtn')?.addEventListener('click', loadFinance);
$('#finYear')?.addEventListener('change', loadFinance);
$('#finMonth')?.addEventListener('change', loadFinance);

$('#finSetRateBtn')?.addEventListener('click', () => {
  const y = +$('#finYear').value, m = +$('#finMonth').value;
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  $('#rateModalPeriod').textContent = `${months[m-1]} ${y}`;
  $('#rateModal').classList.add('show');
});

$('#rateSaveBtn')?.addEventListener('click', async () => {
  const y = +$('#finYear').value, m = +$('#finMonth').value;
  const rate = parseFloat($('#ratePercent').value);
  const basis = $('#rateBasis').value;
  if (isNaN(rate) || rate < 0 || rate > 100){ toast('warning', 'Rate must be 0–100.'); return; }
  try {
    const res = await api('finance_set_rate', { method: 'POST', body: { year: y, month: m, rate, basis } });
    toast('success', res.message);
    $('#rateModal').classList.remove('show');
    loadFinance();
  } catch(err){ toast('error', err.message); }
});

$('#finCalcBtn')?.addEventListener('click', async () => {
  const y = +$('#finYear').value, m = +$('#finMonth').value;
  if (!confirm(`Calculate settlements for ${y}-${String(m).padStart(2,'0')}?`)) return;
  const btn = $('#finCalcBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Calculating…';
  try {
    const res = await api('finance_calculate', { method: 'POST', body: { year: y, month: m } });
    toast('success', res.message);
    loadFinance();
  } catch(err){ toast('error', err.message); }
  finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-calculator"></i> Calculate settlements';
  }
});

$('#finExportBtn')?.addEventListener('click', () => {
  const y = +$('#finYear').value, m = +$('#finMonth').value;
  window.location.href = `?action=finance_export_csv&year=${y}&month=${m}`;
});
$('#finExportDetailBtn')?.addEventListener('click', () => {
  const y = +$('#finYear').value, m = +$('#finMonth').value;
  window.location.href = `?action=finance_export_csv&year=${y}&month=${m}&detail=1`;
});

async function openSettlement(id){
  CURRENT.settlementId = id;
  $('#detailModal').classList.add('show');
  $('#detailTitle').textContent = 'Settlement #' + id;
  $('#detailSub').textContent = 'Loading…';
  $('#detailBody').innerHTML = '<div class="empty"><i class="bi bi-hourglass-split"></i><p>Loading…</p></div>';
  try {
    const res = await api('finance_settlement_detail', { params: { settlement_id: id } });
    renderSettlementDetail(res);
  } catch(err){
    $('#detailBody').innerHTML = `<div class="empty"><i class="bi bi-x-circle"></i><p>${esc(err.message)}</p></div>`;
  }
}

function renderSettlementDetail(res){
  const s = res.settlement, rides = res.rides || [], payments = res.payments || [];
  CURRENT.settlementRow = s;
  $('#detailTitle').textContent = s.company_name + ' — ' + s.period_start;
  $('#detailSub').innerHTML = `Rate <strong>${nf(s.commission_rate, 2)}%</strong> · Status <strong>${esc(s.settlement_status)}</strong> · Balance <strong>${nf(s.balance_due, 0)} BIF</strong>`;

  let html = '<div class="kpi-grid" style="margin-bottom:14px">';
  html += `<div class="kpi"><div class="kpi-label">Rides</div><div class="kpi-value">${nf(s.completed_rides_count, 0)}</div><div class="kpi-sub">${nf(s.eligible_rides_count, 0)} eligible</div></div>`;
  html += `<div class="kpi"><div class="kpi-label">Gross</div><div class="kpi-value">${nf(s.gross_revenue, 0)}</div></div>`;
  html += `<div class="kpi accent"><div class="kpi-label">Commission</div><div class="kpi-value">${nf(s.total_commission, 0)}</div></div>`;
  html += `<div class="kpi success"><div class="kpi-label">Paid</div><div class="kpi-value">${nf(s.total_paid, 0)}</div></div>`;
  html += `<div class="kpi warn"><div class="kpi-label">Balance</div><div class="kpi-value">${nf(s.balance_due, 0)}</div></div>`;
  html += '</div>';

  if (rides.length){
    html += '<h4 style="font-size:12px;font-weight:700;margin:14px 0 8px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Rides</h4>';
    html += '<div class="table-wrap" style="margin-bottom:14px"><table><thead><tr><th>Ref</th><th>Completed</th><th>Ride</th><th>Invoice</th><th>Commission</th><th>Status</th></tr></thead><tbody>';
    rides.forEach(r => {
      const cls = r.eligibility_status === 'exception' ? 'red' : 'green';
      html += `<tr><td class="mono">${esc(r.ride_reference)}</td><td class="mono">${esc((r.completed_at||'').slice(0,16))}</td><td class="mono">${nf(r.ride_amount,0)}</td><td class="mono">${r.invoice_amount != null ? nf(r.invoice_amount,0) : '—'}</td><td class="mono">${nf(r.commission_amount,0)}</td><td><span class="pill ${cls}">${esc(r.eligibility_status)}</span></td></tr>`;
    });
    html += '</tbody></table></div>';
  }

  if (payments.length){
    html += '<h4 style="font-size:12px;font-weight:700;margin:14px 0 8px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Payments</h4>';
    html += '<div class="table-wrap"><table><thead><tr><th>Reference</th><th>Method</th><th>Received</th><th>Amount</th></tr></thead><tbody>';
    payments.forEach(p => {
      html += `<tr><td class="mono">${esc(p.payment_reference)}</td><td>${esc(p.payment_method)}</td><td class="mono">${esc((p.payment_received_at||'').slice(0,16))}</td><td class="mono">${nf(p.payment_amount,0)}</td></tr>`;
    });
    html += '</tbody></table></div>';
  }

  $('#detailBody').innerHTML = html;
}

$('#detailFinalizeBtn')?.addEventListener('click', async () => {
  if (!CURRENT.settlementId) return;
  if (!confirm('Finalize and lock this settlement? No further rate changes or ride edits will be possible.')) return;
  try {
    const res = await api('finance_finalize', { method: 'POST', body: { settlement_id: CURRENT.settlementId } });
    toast('success', res.message);
    openSettlement(CURRENT.settlementId);
    loadFinance();
  } catch(err){ toast('error', err.message); }
});

$('#detailVoidBtn')?.addEventListener('click', async () => {
  if (!CURRENT.settlementId) return;
  const reason = prompt('Reason for voiding this settlement?');
  if (!reason) return;
  try {
    const res = await api('finance_void', { method: 'POST', body: { settlement_id: CURRENT.settlementId, reason } });
    toast('success', res.message);
    openSettlement(CURRENT.settlementId);
    loadFinance();
  } catch(err){ toast('error', err.message); }
});

$('#detailRecordPayment')?.addEventListener('click', () => {
  if (!CURRENT.settlementRow) return;
  const s = CURRENT.settlementRow;
  $('#payModalSub').textContent = `${s.company_name} — ${s.period_start} · balance ${nf(s.balance_due,0)} BIF`;
  $('#payAmount').value = Number(s.balance_due || 0).toFixed(2);
  $('#payRef').value = '';
  $('#payNotes').value = '';
  $('#payModal').classList.add('show');
});

$('#paySaveBtn')?.addEventListener('click', async () => {
  if (!CURRENT.settlementId) return;
  const reference = $('#payRef').value.trim();
  const method = $('#payMethod').value;
  const amount = parseFloat($('#payAmount').value);
  const received_at = $('#payReceived').value.trim();
  const notes = $('#payNotes').value.trim();
  if (!reference){ toast('warning', 'Reference is required.'); return; }
  if (!(amount > 0)){ toast('warning', 'Amount must be greater than zero.'); return; }
  try {
    const res = await api('finance_record_payment', { method: 'POST', body: {
      settlement_id: CURRENT.settlementId, reference, method, amount, received_at, notes
    }});
    toast('success', res.message);
    $('#payModal').classList.remove('show');
    openSettlement(CURRENT.settlementId);
    loadFinance();
  } catch(err){ toast('error', err.message); }
});

$$('[data-close]').forEach(el => {
  el.addEventListener('click', () => {
    const id = el.dataset.close;
    $('#' + id)?.classList.remove('show');
  });
});
$$('.modal-bg').forEach(bg => {
  bg.addEventListener('click', (e) => {
    if (e.target === bg) bg.classList.remove('show');
  });
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') $$('.modal-bg.show').forEach(bg => bg.classList.remove('show'));
});

$('#refreshBtn')?.addEventListener('click', () => {
  if (dirty && !confirm('You have unsaved changes. Reload anyway?')) return;
  window.location.reload();
});

window.addEventListener('beforeunload', (e) => {
  if (dirty){ e.preventDefault(); e.returnValue = ''; }
});

(function init(){
  const icon = $('#themeToggle i');
  if (document.body.classList.contains('dark') && icon) icon.className = 'bi bi-sun';
})();

})();
</script>
</body>
</html>