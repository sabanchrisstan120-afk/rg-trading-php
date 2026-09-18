<?php
require_once __DIR__ . '/includes/config.php';

// Login now happens in a modal on the homepage (with OTP verification).
if (is_logged_in()) {
    header('Location: /rg-trading-php/index.php');
} else {
    header('Location: /rg-trading-php/index.php?auth=login');
}
exit;