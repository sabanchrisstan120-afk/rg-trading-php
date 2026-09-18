<?php
require_once __DIR__ . '/includes/config.php';

// Tell the API to invalidate the refresh token
if (isset($_SESSION['refresh_token'])) {
    api_request('POST', '/auth/logout', ['refresh_token' => $_SESSION['refresh_token']]);
}

// Destroy the local session
session_destroy();

header('Location: /rg-trading-php/index.php');
exit;
