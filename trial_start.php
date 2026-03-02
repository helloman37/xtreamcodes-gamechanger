<?php
require_once 'db.php';
require_once 'helpers.php';
require_once 'email_lib.php';
session_start();

$pdo = db();
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Ensure system_settings exists (older installs safety)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(190) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  // ignore
}

// must be logged in to claim a trial
if (empty($_SESSION['store_user'])) {
  header("Location: login.php?next=trial_start.php");
  exit;
}

// store_user may be an array or id
$userId = is_array($_SESSION['store_user']) ? (int)$_SESSION['store_user']['id'] : (int)$_SESSION['store_user'];

// Global trial toggle (Admin -> Plans)
$trial_enabled = (system_setting_get($pdo, 'trial_enabled', '1') === '1');
if (!$trial_enabled) {
  flash_set("Trial is currently disabled.", "error");
  header("Location: dashboard.php");
  exit;
}

// Enforce: one active subscription at a time (trial only for accounts without an active sub).
$active = iptv_active_subscription($pdo, $userId);
if ($active) {
  $until = (string)($active['ends_at'] ?? '');
  $untilPretty = $until ? date('M j, Y H:i', strtotime($until)) : 'never';
  flash_set("You already have an active subscription until {$untilPretty}.", "error");
  header("Location: dashboard.php");
  exit;
}

// ---- user-based one-time trial guard ----
$usedTrial = false;

// check trial_claims by user_id
$stU = $pdo->prepare("SELECT 1 FROM trial_claims WHERE user_id=? LIMIT 1");
$stU->execute([$userId]);
if($stU->fetchColumn()) { $usedTrial = true; }

// check any past trial subscription (plans.is_trial=1)
$stS = $pdo->prepare("SELECT 1
                      FROM subscriptions s
                      JOIN plans p ON p.id=s.plan_id
                      WHERE s.user_id=? AND p.is_trial=1
                      LIMIT 1");
$stS->execute([$userId]);
if($stS->fetchColumn()) { $usedTrial = true; }

if($usedTrial){
  flash_set("Trial already used on this account.","error");
  header("Location: dashboard.php");
  exit;
}
// ---- end guard ----

// ensure trial_claims table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS trial_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  user_id INT NULL,
  claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ip (ip)
)");

// IP guard (one per IP too)
$stIp = $pdo->prepare("SELECT 1 FROM trial_claims WHERE ip=? LIMIT 1");
$stIp->execute([$ip]);
if($stIp->fetchColumn()){
  flash_set("Trial already used from this IP.","error");
  header("Location: dashboard.php");
  exit;
}

// locate trial plan
$trialPlan = $pdo->query("SELECT * FROM plans WHERE is_trial=1 LIMIT 1")->fetch();
if(!$trialPlan){
  flash_set("Trial plan not configured.","error");
  header("Location: dashboard.php");
  exit;
}

// create $0 trial order (paid)
$providerTxn = 'TRIAL-'.$userId.'-'.time();
$pdo->prepare("
  INSERT INTO orders (user_id,email,plan_id,amount,currency,provider,provider_txn,status,paid_at)
  VALUES (?,?,?,?, 'USD', 'cashapp', ?, 'paid', NOW())
")->execute([
  $userId,
  (function() use ($pdo, $userId) {
    try {
      $u = gc_email_user_row($pdo, $userId);
      $em = trim((string)($u['email'] ?? ''));
      if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) return $em;
    } catch (Throwable $e) {}
    return (string)($userId.'@trial.local');
  })(),
  $trialPlan['id'],
  0.00,
  $providerTxn
]);
$orderId = (int)$pdo->lastInsertId();

// create 7-day subscription
$starts = date('Y-m-d H:i:s');
$ends   = date('Y-m-d H:i:s', time() + (7*86400));
$pdo->prepare("
  INSERT INTO subscriptions (user_id, plan_id, starts_at, ends_at, status, order_id, source)
  VALUES (?,?,?,?, 'active', ?, 'storefront')
")->execute([$userId, $trialPlan['id'], $starts, $ends, $orderId]);

// Email notifications
try {
  gc_email_send_subscription($pdo, (int)$userId, (string)($trialPlan['name'] ?? 'Trial'), (string)$ends);
} catch (Throwable $t) {}

// lock trial by IP + user
$pdo->prepare("INSERT INTO trial_claims (ip,user_id) VALUES (?,?)")->execute([$ip,$userId]);

flash_set("Trial activated! Good for 7 days.","success");
header("Location: dashboard.php");
exit;
?>