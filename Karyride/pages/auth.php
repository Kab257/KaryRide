<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KaryRide V1 - Authentication Guard
|--------------------------------------------------------------------------
| Usage: $session = require_once __DIR__ . '/auth.php';
|--------------------------------------------------------------------------
*/

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// Check if user is logged in
// ============================================================

if (!isset($_SESSION['user_id'])) {
    // Get the current URL for redirect after login
    $redirect = $_SERVER['REQUEST_URI'] ?? '';
    $redirect = ltrim($redirect, '/');
    
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}

// ============================================================
// Return session data
// ============================================================

return [
    'user_id' => (int) $_SESSION['user_id'],
    'role' => $_SESSION['role'] ?? 'client',
    'first_name' => $_SESSION['first_name'] ?? '',
    'last_name' => $_SESSION['last_name'] ?? '',
    'email' => $_SESSION['email'] ?? '',
    'phone' => $_SESSION['phone'] ?? '',
    'company_id' => $_SESSION['company_id'] ?? null,
];