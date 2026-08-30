<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KaryRide V1 - Logout
|--------------------------------------------------------------------------
| Destroys the current user session and redirects to the landing page.
|--------------------------------------------------------------------------
*/

session_start();

// Remove all session variables
$_SESSION = [];

// Delete the session cookie if sessions use cookies
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Return to landing page
header('Location: ../index.php');
exit;
 
 ?>
