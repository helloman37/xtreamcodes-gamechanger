<?php
declare(strict_types=1);

// cron_expiration_reminders.php
// Sends subscription expiration reminders (default: 3 days before end).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/email_lib.php';

date_default_timezone_set('America/Chicago');

$pdo = db();
$cfg = iptv_config();

// Optional web-access key guard
if (PHP_SAPI !== 'cli') {
  $expected = (string)($cfg['cron_key'] ?? '');
  if ($expected !== '') {
    $got = (string)($_GET['key'] ?? '');
    if (!hash_equals($expected, $got)) {
      http_response_code(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo "Forbidden";
      exit;
    }
  }
}

$s = gc_email_settings($pdo);
$days = (int)($s['toggles']['expiry_days'] ?? 3);
if ($days < 1) $days = 3;

// Only run if expiry reminders are enabled
if (empty($s['toggles']['send_expiry'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'sent'=>0,'skipped'=>'disabled']);
  exit;
}

// Expiring exactly N days from today (calendar-day based)
$sql = "
  SELECT s.id AS sub_id, s.user_id, s.ends_at, p.name AS plan_name
  FROM subscriptions s
  JOIN plans p ON p.id=s.plan_id
  WHERE s.status='active'
    AND s.ends_at IS NOT NULL
    AND s.ends_at > NOW()
    AND s.ends_at < '9999-01-01'
    AND DATEDIFF(DATE(s.ends_at), CURDATE()) = ?
";

$st = $pdo->prepare($sql);
$st->execute([$days]);

$sent = 0;
$seen = 0;
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  $seen++;
  try {
    $ok = gc_email_send_expiry_reminder(
      $pdo,
      (int)$row['user_id'],
      (int)$row['sub_id'],
      (string)($row['plan_name'] ?? 'Plan'),
      (string)$row['ends_at']
    );
    if ($ok) $sent++;
  } catch (Throwable $t) {
    // ignore
  }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'checked'=>$seen,'sent'=>$sent,'days'=>$days]);
exit;
