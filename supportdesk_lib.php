<?php
// supportdesk_lib.php
// Shared helpers for native Support Desk (no plugins folder required).

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function supportdesk_db_init(PDO $pdo): void {
  // Keep schema lightweight and safe (no foreign keys to avoid install edge cases).
  $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_message_at DATETIME NOT NULL,
    last_author VARCHAR(20) NOT NULL DEFAULT 'user'
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    author_type VARCHAR(10) NOT NULL,
    author_ref VARCHAR(64) NOT NULL,
    message MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX(ticket_id),
    INDEX(created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function supportdesk_now(): string { return date('Y-m-d H:i:s'); }

function supportdesk_setting(PDO $pdo, string $k, $default=null) {
  // Stored in system_settings (so no plugins system is required).
  $map = [
    'portal_enabled' => 'supportdesk_portal_enabled',
    'default_priority' => 'supportdesk_default_priority',
  ];
  $key = $map[$k] ?? ('supportdesk_' . $k);
  $val = system_setting_get($pdo, $key, null);
  if ($val === null) return $default;

  // Mirror default type when possible.
  if (is_int($default)) return (int)$val;
  if (is_bool($default)) return ((string)$val === '1' || strtolower((string)$val) === 'true');
  return $val;
}
function supportdesk_setting_set(PDO $pdo, string $k, $v): void {
  $map = [
    'portal_enabled' => 'supportdesk_portal_enabled',
    'default_priority' => 'supportdesk_default_priority',
  ];
  $key = $map[$k] ?? ('supportdesk_' . $k);
  if (is_array($v) || is_object($v)) {
    system_setting_set($pdo, $key, json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  } else {
    system_setting_set($pdo, $key, $v === null ? null : (string)$v);
  }
}


function supportdesk_require_portal_enabled(PDO $pdo): void {
  $enabled = (int) supportdesk_setting($pdo, 'portal_enabled', 1);
  if ($enabled !== 1) {
    http_response_code(404);
    echo "Support Desk is disabled.";
    exit;
  }
}

function supportdesk_admin_status_badge(string $status): string {
  $s = strtolower(trim($status));
  if ($s === 'open') return "<span class='pill good'>Open</span>";
  if ($s === 'pending') return "<span class='pill'>Pending</span>";
  if ($s === 'answered') return "<span class='pill'>Answered</span>";
  if ($s === 'closed') return "<span class='pill bad'>Closed</span>";
  return "<span class='pill'>".e($status)."</span>";
}

function supportdesk_portal_status_badge(string $status): string {
  $s = strtolower(trim($status));
  if ($s === 'open') return "<span class='badge good'>Open</span>";
  if ($s === 'pending') return "<span class='badge'>Pending</span>";
  if ($s === 'answered') return "<span class='badge'>Answered</span>";
  if ($s === 'closed') return "<span class='badge bad'>Closed</span>";
  return "<span class='badge'>".e($status)."</span>";
}

function supportdesk_clean_subject(string $s): string {
  $s = trim($s);
  $s = preg_replace('~\s+~', ' ', $s);
  if (function_exists('mb_substr')) return mb_substr($s, 0, 255);
  return substr($s, 0, 255);
}
function supportdesk_clean_message(string $s): string {
  $s = trim($s);
  // Hard cap (keeps DB sane)
  if (function_exists('mb_substr')) return mb_substr($s, 0, 5000);
  return substr($s, 0, 5000);
}

function supportdesk_render_message(string $msg): string {
  return nl2br(e($msg));
}
