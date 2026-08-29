<?php
require_once __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    redirect('login.php');
}

redirect(is_admin() ? 'dashboard.php' : 'sales.php');
