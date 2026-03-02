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


/**
 * Generate (deduped) admin notifications for suspicious account activity based on recent request_logs.
 * This is intentionally login/poll-triggered (via the bell count endpoint) and throttled via system_settings.
 *
 * Heuristics (defaults; override via config):
 *  - window_minutes: 30
 *  - ip_threshold: 3 (unique IPs within window)
 *  - device_fp_threshold: 4 (unique device fingerprints within window)
 *  - error_threshold: 5 (HTTP status >= 400 within window)
 *  - cooldown_sec: 60 (minimum seconds between generator runs)
 *  - limit: 25 (max users flagged per run)
 */
function admin_notifications_generate_suspicious_activity(PDO $pdo, array $config = []): void {
  $windowMin = (int)($config['admin_notify_suspicious_window_minutes'] ?? 30);
  if ($windowMin < 5) $windowMin = 5;
  if ($windowMin > 360) $windowMin = 360;

  $ipThr = (int)($config['admin_notify_suspicious_ip_threshold'] ?? 3);
  if ($ipThr < 2) $ipThr = 2;
  if ($ipThr > 20) $ipThr = 20;

  $fpThr = (int)($config['admin_notify_suspicious_device_fp_threshold'] ?? 4);
  if ($fpThr < 2) $fpThr = 2;
  if ($fpThr > 50) $fpThr = 50;

  $errThr = (int)($config['admin_notify_suspicious_error_threshold'] ?? 5);
  if ($errThr < 1) $errThr = 1;
  if ($errThr > 200) $errThr = 200;

  $limit = (int)($config['admin_notify_suspicious_limit'] ?? 25);
  if ($limit < 1) $limit = 1;
  if ($limit > 200) $limit = 200;

  $cooldown = (int)($config['admin_notify_suspicious_cooldown_sec'] ?? 60);
  if ($cooldown < 10) $cooldown = 10;
  if ($cooldown > 3600) $cooldown = 3600;

  // Throttle generator runs (notif_count.php may be polled frequently).
  $now = time();
  $last = (int)(system_setting_get($pdo, 'admin_notify_suspicious_last_run', '0') ?? '0');
  if (($now - $last) < $cooldown) return;
  system_setting_set($pdo, 'admin_notify_suspicious_last_run', (string)$now);

  try {
    $st = $pdo->prepare("
      SELECT rl.user_id,
             MAX(rl.username) AS username,
             COUNT(*) AS hits,
             COUNT(DISTINCT rl.ip) AS ip_cnt,
             COUNT(DISTINCT rl.device_fp) AS fp_cnt,
             SUM(CASE WHEN rl.status_code >= 400 THEN 1 ELSE 0 END) AS err_cnt,
             MAX(rl.created_at) AS last_at
      FROM request_logs rl
      WHERE rl.created_at >= (NOW() - INTERVAL ? MINUTE)
        AND rl.user_id IS NOT NULL
        AND rl.user_id > 0
        AND rl.endpoint IN ('get','player_api','stream')
      GROUP BY rl.user_id
      HAVING ip_cnt >= ? OR fp_cnt >= ? OR err_cnt >= ?
      ORDER BY last_at DESC
      LIMIT {$limit}
    ");
    $st->execute([$windowMin, $ipThr, $fpThr, $errThr]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Dedup bucket (30-minute buckets by default). Keeps notifications from spamming.
    $bucketSize = (int)($config['admin_notify_suspicious_bucket_sec'] ?? 1800);
    if ($bucketSize < 300) $bucketSize = 300;
    if ($bucketSize > 86400) $bucketSize = 86400;
    $bucket = (int)(floor($now / $bucketSize) * $bucketSize);
    $bucketKey = date('YmdHi', $bucket);

    foreach ($rows as $r) {
      $uid = (int)($r['user_id'] ?? 0);
      if ($uid < 1) continue;
      $uname = trim((string)($r['username'] ?? ''));
      if ($uname === '') $uname = 'user#' . $uid;

      $ipCnt = (int)($r['ip_cnt'] ?? 0);
      $fpCnt = (int)($r['fp_cnt'] ?? 0);
      $errCnt = (int)($r['err_cnt'] ?? 0);
      $hits = (int)($r['hits'] ?? 0);

      $reasons = [];
      if ($ipCnt >= $ipThr) $reasons[] = "{$ipCnt} IPs";
      if ($fpCnt >= $fpThr) $reasons[] = "{$fpCnt} devices";
      if ($errCnt >= $errThr) $reasons[] = "{$errCnt} errors";
      if (!$reasons) continue;

      $title = 'Suspicious activity: ' . $uname;
      $msg = "Flagged in last {$windowMin} min: " . implode(', ', $reasons) . " (hits: {$hits}).";
      $link = '/admin/user_accounts.php?edit=' . $uid;

      $uniq = 'sus:' . $uid . ':' . $bucketKey;
      admin_notifications_broadcast($pdo, 'security', $title, $msg, $link, $uniq);
    }
  } catch (Throwable $t) {
    // ignore
  }
}
