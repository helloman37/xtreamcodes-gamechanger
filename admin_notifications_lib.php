<?php
declare(strict_types=1);

// admin_notifications_lib.php
// Lightweight in-app notifications for admins (admin bell + notifications page).

require_once __DIR__ . '/helpers.php';

function admin_notifications_now(): string {
  return date('Y-m-d H:i:s');
}

function admin_notifications_add(PDO $pdo, int $adminId, string $type, string $title, string $message = '', string $link = '', ?string $uniqKey = null): bool {
  if ($adminId < 1) return false;
  $type = trim($type);
  if ($type === '') $type = 'info';
  $title = trim($title);
  if ($title === '') return false;

  $message = trim($message);
  $link = trim($link);

  try {
    $st = $pdo->prepare("INSERT IGNORE INTO admin_notifications (admin_id, type, title, message, link, uniq_key, is_read, created_at)
                         VALUES (?,?,?,?,?,?,0,?)");
    $st->execute([$adminId, $type, $title, $message !== '' ? $message : null, $link !== '' ? $link : null, $uniqKey, admin_notifications_now()]);
    return ($st->rowCount() > 0);
  } catch (Throwable $t) {
    return false;
  }
}

/** Broadcast an admin notification to all admins. */
function admin_notifications_broadcast(PDO $pdo, string $type, string $title, string $message = '', string $link = '', ?string $uniqKey = null): int {
  $n = 0;
  try {
    $admins = $pdo->query("SELECT id FROM admins ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($admins as $a) {
      $aid = (int)($a['id'] ?? 0);
      if ($aid < 1) continue;
      if (admin_notifications_add($pdo, $aid, $type, $title, $message, $link, $uniqKey)) $n++;
    }
  } catch (Throwable $t) {}
  return $n;
}

function admin_notifications_unread_count(PDO $pdo, int $adminId): int {
  if ($adminId < 1) return 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM admin_notifications WHERE admin_id=? AND is_read=0");
    $st->execute([$adminId]);
    return (int)$st->fetchColumn();
  } catch (Throwable $t) {
    return 0;
  }
}

function admin_notifications_list(PDO $pdo, int $adminId, int $limit = 50): array {
  if ($adminId < 1) return [];
  $limit = max(1, min(200, $limit));
  try {
    $st = $pdo->prepare("SELECT id, type, title, message, link, is_read, created_at, read_at
                         FROM admin_notifications
                         WHERE admin_id=?
                         AND is_read=0
                         ORDER BY created_at DESC, id DESC
                         LIMIT {$limit}");
    $st->execute([$adminId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $t) {
    return [];
  }
}

function admin_notifications_mark_read(PDO $pdo, int $adminId, int $notifId): void {
  if ($adminId < 1 || $notifId < 1) return;
  try {
    $st = $pdo->prepare("UPDATE admin_notifications SET is_read=1, read_at=NOW() WHERE id=? AND admin_id=?");
    $st->execute([$notifId, $adminId]);
  } catch (Throwable $t) {}
}

function admin_notifications_mark_all_read(PDO $pdo, int $adminId): void {
  if ($adminId < 1) return;
  try {
    $st = $pdo->prepare("UPDATE admin_notifications SET is_read=1, read_at=NOW() WHERE admin_id=? AND is_read=0");
    $st->execute([$adminId]);
  } catch (Throwable $t) {}
}

/**
 * Create (deduped) admin notifications for subscriptions expiring soon.
 * This is intentionally login-triggered (via the bell count endpoint).
 */
function admin_notifications_generate_sub_expiry(PDO $pdo, array $config = []): void {
  $days = (int)($config['notify_sub_expire_days_admin'] ?? ($config['notify_sub_expire_days'] ?? 3));
  if ($days < 1) $days = 1;
  if ($days > 30) $days = 30;

  // Limit how many we create per request (prevents huge bursts).
  $limit = (int)($config['admin_notify_sub_expire_limit'] ?? 25);
  if ($limit < 1) $limit = 1;
  if ($limit > 200) $limit = 200;

  try {
    $st = $pdo->prepare("SELECT s.id AS sub_id, s.user_id, s.plan_id, s.ends_at, u.username, p.name AS plan_name
                         FROM subscriptions s
                         JOIN users u ON u.id=s.user_id
                         LEFT JOIN plans p ON p.id=s.plan_id
                         WHERE s.status='active'
                           AND s.ends_at IS NOT NULL
                           AND s.ends_at > NOW()
                           AND s.ends_at <= (NOW() + INTERVAL ? DAY)
                         ORDER BY s.ends_at ASC
                         LIMIT {$limit}");
    $st->execute([$days]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
      $subId = (int)($r['sub_id'] ?? 0);
      $userId = (int)($r['user_id'] ?? 0);
      $username = (string)($r['username'] ?? 'user');
      $planName = (string)($r['plan_name'] ?? 'Plan');
      $endsAt = (string)($r['ends_at'] ?? '');
      $endsTs = $endsAt ? strtotime($endsAt) : false;
      if (!$endsTs) continue;
      $diff = $endsTs - time();
      if ($diff <= 0) continue;
      $daysLeft = (int)floor($diff / 86400);
      $dateNice = date('M j, Y g:ia', $endsTs);

      $title = 'Subscription expiring: ' . $username;
      $msg = $username . " (" . $planName . ") expires on " . $dateNice . ".";
      if ($daysLeft <= 0) $msg = $username . " (" . $planName . ") expires today (" . $dateNice . ").";
      elseif ($daysLeft === 1) $msg = $username . " (" . $planName . ") expires tomorrow (" . $dateNice . ").";
      else $msg = $username . " (" . $planName . ") expires in " . $daysLeft . " days (" . $dateNice . ").";

      $uniq = 'subexp:' . ($subId > 0 ? (string)$subId : (string)$userId) . ':' . date('Ymd', $endsTs);
      $link = '/admin/user_accounts.php?edit=' . $userId;
      admin_notifications_broadcast($pdo, 'subscription', $title, $msg, $link, $uniq);
    }
  } catch (Throwable $t) {}
}
