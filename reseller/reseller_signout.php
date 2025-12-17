<?php
require_once __DIR__ . '/../auth.php';
reseller_logout();
header("Location: reseller_signin.php");
exit;
