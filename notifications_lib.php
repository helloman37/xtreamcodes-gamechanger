<?php
declare(strict_types=1);

// notifications_lib.php
// Lightweight in-app notifications for users (portal bell, support replies, expiry warnings).

require_once __DIR__ . '/helpers.php';

function notifications_now(): string {
  return date('Y-m-d H:i:s');
}

function notifications_add(PDO $pdo, int $userId, string $type, string $title, string $message = '', string $link = '', ?string $uniqKey = null): bool {
  if ($userId < 1) return false;
  $type = trim($type);
  if ($type === '') $type = 'info';
  $title = trim($title);
  if ($title === '') return false;

  $message = trim($message);
  $link = trim($link);

  // Dedupe using (user_id, uniq_key) unique index. NULL uniqKey means no dedupe.
  try {
    $st = $pdo->prepare("INSERT IGNORE INTO notifications (user_id, type, title, message, link, uniq_key, is_read, created_at)
                         VALUES (?,?,?,?,?,?,0,?)");
    $st->execute([$userId, $type, $title, $message, $link !== '' ? $link : null, $uniqKey, notifications_now()]);
    return ($st->rowCount() > 0);
  } catch (Throwable $t) {
    return false;
  }
}

function notifications_unread_count(PDO $pdo, int $userId): int {
  if ($userId < 1) return 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
  } catch (Throwable $t) {
    return 0;
  }
}

function notifications_list(PDO $pdo, int $userId, int $limit = 50): array {
  if ($userId < 1) return [];
  $limit = max(1, min(200, $limit));
  try {
    $st = $pdo->prepare("SELECT id, type, title, message, link, is_read, created_at, read_at
                         FROM notifications
                         WHERE user_id=?
                         AND is_read=0
                         ORDER BY created_at DESC, id DESC
                         LIMIT {$limit}");
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $t) {
    return [];
  }
}

function notifications_mark_read(PDO $pdo, int $userId, int $notifId): void {
  if ($userId < 1 || $notifId < 1) return;
  try {
    $st = $pdo->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?");
    $st->execute([$notifId, $userId]);
  } catch (Throwable $t) {}
}

function notifications_mark_all_read(PDO $pdo, int $userId): void {
  if ($userId < 1) return;
  try {
    $st = $pdo->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=? AND is_read=0");
    $st->execute([$userId]);
  } catch (Throwable $t) {}
}

/**
 * Create a single "about to expire" notification per subscription period.
 * Default threshold: 3 days (override via config['notify_sub_expire_days']).
 */
function notifications_maybe_add_sub_expiry(PDO $pdo, int $userId, $sub, array $config = []): void {
  // $sub can be array, null, or false depending on PDO fetch result.
  if ($userId < 1 || !is_array($sub) || empty($sub['ends_at'])) return;

  $days = (int)($config['notify_sub_expire_days'] ?? 3);
  if ($days < 1) $days = 1;

  $endsAt = (string)$sub['ends_at'];
  $endsTs = strtotime($endsAt);
  if (!$endsTs) return;

  $now = time();
  $diff = $endsTs - $now;
  if ($diff <= 0) return;

  $daysLeft = (int)floor($diff / 86400);
  if ($daysLeft > $days) return;

  $subId = (int)($sub['id'] ?? 0);
  $uniqKey = 'subexp:' . ($subId > 0 ? (string)$subId : md5($endsAt)) . ':' . date('Ymd', $endsTs);

  $dateNice = date('M j, Y g:ia', $endsTs);
  $title = 'Subscription expiring soon';
  $msg = 'Your subscription expires on ' . $dateNice . '.';

  if ($daysLeft <= 0) {
    $msg = 'Your subscription expires today (' . $dateNice . ').';
  } elseif ($daysLeft === 1) {
    $msg = 'Your subscription expires tomorrow (' . $dateNice . ').';
  } else {
    $msg = 'Your subscription expires in ' . $daysLeft . ' days (' . $dateNice . ').';
  }

  notifications_add($pdo, $userId, 'subscription', $title, $msg, '/dashboard.php', $uniqKey);
}
