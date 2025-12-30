<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../admin_notifications_lib.php';

require_admin();

$pdo = db();
$cfg = require __DIR__ . '/../config.php';

// Generate a few key admin alerts on-demand (login-triggered).
admin_notifications_generate_sub_expiry($pdo, is_array($cfg) ? $cfg : []);

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$count = admin_notifications_unread_count($pdo, $adminId);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['unread' => $count], JSON_UNESCAPED_SLASHES);
exit;
