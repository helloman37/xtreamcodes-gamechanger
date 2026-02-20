<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../email_lib.php';
require_admin();

$pdo = db();
// Keep stored subscription statuses in sync with ends_at.
iptv_expire_due_subscriptions($pdo);

// Ensure system_settings exists (older installs safety)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (\n" .
    "  setting_key VARCHAR(190) PRIMARY KEY,\n" .
    "  setting_value TEXT NULL,\n" .
    "  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n" .
    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  // ignore
}

// Trial enable/disable (global storefront trial button + trial_start)
if (isset($_POST['trial_settings_save'])) {
  $enabled = isset($_POST['trial_enabled']) ? '1' : '0';
  system_setting_set($pdo, 'trial_enabled', $enabled);
  flash_set('Trial settings updated', 'success');
  header('Location: plans_subs.php');
  exit;
}

$trial_enabled = system_setting_get($pdo, 'trial_enabled', '1');

/* ---------------------------
   CREATE PLAN
---------------------------- */
if (isset($_POST['plan_create'])) {
  $pdo->prepare("INSERT INTO plans (name, price, duration_days, max_streams, max_devices, reseller_credits_cost)
                 VALUES (?,?,?,?,?,?)")
      ->execute([
        trim($_POST['name'] ?? ''),
        (float)($_POST['price'] ?? 0),
        (int)($_POST['duration_days'] ?? 30),
        (int)($_POST['max_streams'] ?? 1),
        (int)($_POST['max_devices'] ?? 2),
        (int)($_POST['reseller_credits_cost'] ?? 1)
      ]);
  flash_set("Plan created", "success");
  header("Location: plans_subs.php"); exit;
}

/* ---------------------------
   UPDATE PLAN (EDIT)
---------------------------- */
if (isset($_POST['plan_update'])) {
  $plan_id = (int)$_POST['plan_id'];
  $pdo->prepare("UPDATE plans
                 SET name=?, price=?, duration_days=?, max_streams=?, max_devices=?, reseller_credits_cost=?
                 WHERE id=?")
      ->execute([
        trim($_POST['name'] ?? ''),
        (float)($_POST['price'] ?? 0),
        (int)($_POST['duration_days'] ?? 30),
        (int)($_POST['max_streams'] ?? 1),
        (int)($_POST['max_devices'] ?? 2),
        (int)($_POST['reseller_credits_cost'] ?? 1),
        $plan_id
      ]);

  flash_set("Plan updated", "success");
  header("Location: plans_subs.php"); exit;
}

/* ---------------------------
   DELETE PLAN
---------------------------- */
if (isset($_GET['plan_delete'])) {
  $plan_id = (int)$_GET['plan_delete'];
  $pdo->prepare("DELETE FROM plans WHERE id=?")->execute([$plan_id]);
  flash_set("Plan deleted", "success");
  header("Location: plans_subs.php"); exit;
}

/* ---------------------------
   ASSIGN SUBSCRIPTION
---------------------------- */
if (isset($_POST['sub_create'])) {
  $user_id = (int)$_POST['user_id'];
  $plan_id = (int)$_POST['plan_id'];

  $st = $pdo->prepare("SELECT * FROM plans WHERE id=?");
  $st->execute([$plan_id]);
  $plan = $st->fetch();

  if (!$plan) {
    flash_set("Plan not found", "error");
    header("Location: plans_subs.php"); exit;
  }

  // Enforce: one active subscription per user at a time.
  $active = iptv_active_subscription($pdo, $user_id);
  if ($active) {
    $until = (string)($active['ends_at'] ?? '');
    $untilPretty = $until ? date('M j, Y H:i', strtotime($until)) : 'never';
    $pname = (string)($active['plan_name'] ?? 'current plan');
    flash_set("User already has an active subscription ({$pname}) until {$untilPretty}. Expire/cancel it first.", "error");
    header("Location: plans_subs.php"); exit;
  }

  $starts = new DateTime();
  $unlimited = isset($_POST['unlimited']) && (string)$_POST['unlimited'] === '1';
  if ($unlimited) {
    // Keep ends_at within DATETIME range without needing schema changes
    $ends = new DateTime('9999-12-31 23:59:59');
  } else {
    $ends = (new DateTime())->modify("+" . (int)$plan['duration_days'] . " days");
  }

  $pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, starts_at, ends_at, status)
                 VALUES (?,?,?,?, 'active')")
      ->execute([
        $user_id,
        $plan_id,
        $starts->format('Y-m-d H:i:s'),
        $ends->format('Y-m-d H:i:s')
      ]);

  // Email subscriber
  try {
    gc_email_send_subscription($pdo, $user_id, (string)($plan['name'] ?? 'Plan'), $ends->format('Y-m-d H:i:s'));
  } catch (Throwable $t) {}

  flash_set("Subscription assigned", "success");
  header("Location: plans_subs.php"); exit;
}

/* ---------------------------
   LOAD DATA
---------------------------- */
$plans = $pdo->query("SELECT * FROM plans ORDER BY created_at DESC")->fetchAll();
$users = $pdo->query("SELECT id,username FROM users WHERE status='active'")->fetchAll();
$subs  = $pdo->query("
  SELECT s.*, u.username, p.name AS plan_name, p.max_streams, p.max_devices
  FROM subscriptions s
  JOIN users u ON u.id=s.user_id
  JOIN plans p ON p.id=s.plan_id
  ORDER BY s.ends_at DESC
")->fetchAll();

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Plans & Subscriptions</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Trial Settings</h2>
  <form method="post">
    <input type="hidden" name="trial_settings_save" value="1">

    <div class="row" style="align-items:center;">
      <div style="display:flex;align-items:center;gap:10px;">
        <input type="checkbox" id="trial_enabled" name="trial_enabled" value="1" <?= ($trial_enabled === '1') ? 'checked' : '' ?>>
        <label for="trial_enabled" style="margin:0;">Enable 7‑day trial for customers</label>
      </div>
    </div>

    <div class="muted" style="font-size:12px;margin-top:8px;">
      When disabled, the storefront trial button is hidden and <code>/trial_start.php</code> is blocked.
    </div>

    <div style="margin-top:12px;">
      <button>Save Trial Settings</button>
    </div>
  </form>
</div>

<br>

<div class="card">
  <h2>Create Plan</h2>
  <?php flash_show(); ?>

  <form method="post">
    <input type="hidden" name="plan_create" value="1">

    <div class="row">
      <div>
        <label>Name</label>
        <input name="name" required>
      </div>
      <div>
        <label>Price</label>
        <input name="price" type="number" step="0.01" value="0.00">
      </div>
    </div>

    <div class="row">
      <div>
        <label>Duration Days</label>
        <input name="duration_days" type="number" value="30">
      </div>
      <div>
        <label>Max Streams</label>
        <input name="max_streams" type="number" value="1" min="1">
      </div>
      <div>
        <label>Max Devices</label>
        <input name="max_devices" type="number" value="2" min="1">
      </div>
      <div>
        <label>Reseller Credits Cost</label>
        <input name="reseller_credits_cost" type="number" value="1" min="0">
      </div>
    </div>

    <div style="margin-top:12px;">
      <button>Create Plan</button>
    </div>
  </form>
</div>

<br>

<div class="card">
  <h2>Existing Plans (edit device limit here)</h2>

  <table>
    <tr>
      <th>Name</th>
      <th>Price</th>
      <th>Days</th>
      <th>Max Streams</th><th>Max Devices</th><th>Reseller Cost</th>
      <th>Actions</th>
    </tr>

    <?php foreach($plans as $p): ?>
    <tr>
      <form method="post">
        <input type="hidden" name="plan_update" value="1">
        <input type="hidden" name="plan_id" value="<?=$p['id']?>">

        <td><input name="name" value="<?=e($p['name'])?>" required></td>
        <td><input name="price" type="number" step="0.01" value="<?=e($p['price'])?>"></td>
        <td><input name="duration_days" type="number" value="<?=e($p['duration_days'])?>" min="1"></td>
        <td><input name="max_streams" type="number" value="<?=e($p['max_streams'])?>" min="1"></td>
        <td><input name="max_devices" type="number" value="<?=e($p['max_devices'] ?? 2)?>" min="1"></td>
        <td><input name="reseller_credits_cost" type="number" value="<?=e($p['reseller_credits_cost'] ?? 1)?>" min="0"></td>

        <td style="white-space:nowrap;">
          <button type="submit">Save</button>
          <a href="plans_subs.php?plan_delete=<?=$p['id']?>"
             onclick="return confirm('Delete this plan? (subs using it will break)')"
             style="margin-left:8px;color:#ff5571;">Delete</a>
        </td>
      </form>
    </tr>
    <?php endforeach; ?>

  </table>
</div>

<hr>

<div class="card">
  <h2>Assign Subscription</h2>
  <form method="post">
    <input type="hidden" name="sub_create" value="1">

    <div class="row">
      <div>
        <label>User</label>
        <select name="user_id">
          <?php foreach($users as $u): ?>
          <option value="<?=$u['id']?>"><?=e($u['username'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Plan</label>
        <select name="plan_id">
          <?php foreach($plans as $p): ?>
          <option value="<?=$p['id']?>"><?=e($p['name'])?> (<?=e($p['max_streams'])?> streams / <?=e($p['max_devices'] ?? 2)?> devices)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row" style="margin-top:12px;">
      <div style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" id="unlimited" name="unlimited" value="1">
        <label for="unlimited" style="margin:0;"></label>
      </div>
      <div class="muted" style="font-size:12px;margin-top:4px;">
        If checked, the subscription never expires. Leave unchecked for normal plan duration (e.g., monthly).
      </div>
    </div>

    <div style="margin-top:12px;">
      <button>Assign</button>
    </div>
  </form>
</div>

<br>

<div class="card">
  <h2>Subscriptions</h2>
  <table>
    <tr>
      <th>User</th>
      <th>Plan</th>
      <th>Max Streams</th><th>Max Devices</th>
      <th>Starts</th>
      <th>Ends</th>
      <th>Status</th>
    </tr>
    <?php foreach($subs as $s): ?>
    <tr>
      <td><?=e($s['username'])?></td>
      <td><?=e($s['plan_name'])?></td>
      <td><?=e($s['max_streams'])?></td><td><?=e($s['max_devices'] ?? 2)?></td>
      <td><?=e($s['starts_at'])?></td>
      <td><?php
  $is_unlimited = !$s['ends_at'] || str_starts_with((string)$s['ends_at'], '9999-');
  echo $is_unlimited ? 'Unlimited' : e($s['ends_at']);
?></td>
      <td><?=e($s['status'])?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
