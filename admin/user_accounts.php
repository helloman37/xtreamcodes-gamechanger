<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../admin_notifications_lib.php';
require_admin();

$pdo = db();
iptv_expire_due_subscriptions($pdo);
$resellers = $pdo->query("SELECT id, username FROM resellers WHERE status='active' ORDER BY username")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $op = $_POST['op'] ?? 'save_user';
  $id = (int)($_POST['id'] ?? 0);

  if ($op === 'reset_devices' && $id > 0) {
    $pdo->prepare("DELETE FROM user_devices WHERE user_id=?")->execute([$id]);
    $pdo->prepare("UPDATE users SET device_lock=0 WHERE id=?")->execute([$id]);
    flash_set("Device fingerprints cleared + device lock disabled","success");
    header("Location: user_accounts.php?edit=".$id);
    exit;
  }

  if ($op === 'kill_sessions' && $id > 0) {
    try {
      $pdo->prepare("UPDATE stream_sessions SET killed_at=NOW() WHERE user_id=? AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')")->execute([$id]);
    } catch (Throwable $e) {
      $pdo->prepare("DELETE FROM stream_sessions WHERE user_id=?")->execute([$id]);
    }
    flash_set("Active sessions killed","success");
    header("Location: user_accounts.php?edit=".$id);
    exit;
  }

  if ($op === 'gen_password' && $id > 0) {
    $new_pass = iptv_numeric_string(10);
    $hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $enc  = iptv_encrypt($new_pass);
    $pdo->prepare("UPDATE users SET password_hash=?, password_enc=? WHERE id=?")->execute([$hash,$enc,$id]);
    flash_set("New numeric password: ".$new_pass, "success");
    header("Location: user_accounts.php?edit=".$id);
    exit;
  }




  if ($op === 'delete_sub' && $id > 0) {
    $sub_id = (int)($_POST['sub_id'] ?? 0);
    if ($sub_id <= 0) {
      flash_set("Invalid subscription", "error");
      header("Location: user_accounts.php?edit=".$id);
      exit;
    }

    $st = $pdo->prepare("SELECT id,status FROM subscriptions WHERE id=? AND user_id=? LIMIT 1");
    $st->execute([$sub_id, $id]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
      flash_set("Subscription not found for this user", "error");
      header("Location: user_accounts.php?edit=".$id);
      exit;
    }

    // Hard delete: removes the subscription record entirely.
    $pdo->prepare("DELETE FROM subscriptions WHERE id=? AND user_id=?")->execute([$sub_id, $id]);
    flash_set("Subscription deleted", "success");
    header("Location: user_accounts.php?edit=".$id);
    exit;
  }

  if ($op === 'update_sub' && $id > 0) {
    $sub_id = (int)($_POST['sub_id'] ?? 0);
    if ($sub_id <= 0) {
      flash_set("Invalid subscription", "error");
      header("Location: user_accounts.php?edit=".$id);
      exit;
    }

    $st = $pdo->prepare("SELECT * FROM subscriptions WHERE id=? AND user_id=? LIMIT 1");
    $st->execute([$sub_id, $id]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
      flash_set("Subscription not found for this user", "error");
      header("Location: user_accounts.php?edit=".$id);
      exit;
    }

    $starts_in = trim((string)($_POST['sub_starts'] ?? ''));
    $ends_in   = trim((string)($_POST['sub_ends'] ?? ''));
    $unlimited = isset($_POST['sub_unlimited']) ? 1 : 0;

    $plan_id_in = (int)($_POST['sub_plan_id'] ?? 0);
    $plan_id_new = $plan_id_in > 0 ? $plan_id_in : (int)($sub['plan_id'] ?? 0);

    $status_in = strtolower(trim((string)($_POST['sub_status'] ?? '')));
    $allowed_status = ['active','cancelled','expired'];
    if (!in_array($status_in, $allowed_status, true)) $status_in = (string)($sub['status'] ?? 'active');

    $reset_end = !empty($_POST['sub_reset_end']) ? 1 : 0;

    // Validate plan if changing (or if reset_end requested)
    $plan = null;
    if ($plan_id_new > 0 && ($reset_end || $plan_id_new !== (int)($sub['plan_id'] ?? 0))) {
      $pst = $pdo->prepare("SELECT * FROM plans WHERE id=? LIMIT 1");
      $pst->execute([$plan_id_new]);
      $plan = $pst->fetch(PDO::FETCH_ASSOC);
      if (!$plan) {
        flash_set("Plan not found", "error");
        header("Location: user_accounts.php?edit=".$id);
        exit;
      }
    }

    $starts_at = (string)$sub['starts_at'];
    $ends_at   = (string)$sub['ends_at'];

    try {
      if ($starts_in !== '') {
        $starts_dt = new DateTime($starts_in);
        $starts_at = $starts_dt->format('Y-m-d H:i:s');
      } else {
        $starts_dt = new DateTime($starts_at);
      }

      if ($unlimited) {
        $ends_at = '9999-12-31 23:59:59';
        $ends_dt = new DateTime($ends_at);
      } else {
        if ($reset_end && $plan) {
          $dur = (int)($plan['duration_days'] ?? 0);
          if ($dur < 1) $dur = 30;
          $ends_dt = (clone $starts_dt)->modify('+' . $dur . ' days');
          $ends_at = $ends_dt->format('Y-m-d H:i:s');
        } elseif ($ends_in !== '') {
          $ends_dt = new DateTime($ends_in);
          $ends_at = $ends_dt->format('Y-m-d H:i:s');
        } else {
          $ends_dt = new DateTime($ends_at);
        }
      }

      if (!$unlimited && $starts_dt > $ends_dt) {
        flash_set("Subscription start cannot be after end", "error");
        header("Location: user_accounts.php?edit=".$id);
        exit;
      }

      // Status: allow explicit cancel/expire, otherwise compute from end date.
      if ($status_in === 'cancelled') {
        $status_new = 'cancelled';
      } elseif ($status_in === 'expired') {
        $status_new = 'expired';
      } else {
        $is_unlimited = str_starts_with((string)$ends_at, '9999-');
        $now = new DateTime();
        $status_new = ($is_unlimited || $ends_dt > $now) ? 'active' : 'expired';
      }

      $pdo->prepare("UPDATE subscriptions SET plan_id=?, starts_at=?, ends_at=?, status=? WHERE id=? AND user_id=?")
          ->execute([$plan_id_new, $starts_at, $ends_at, $status_new, $sub_id, $id]);

      // Enforce: only one active subscription at a time per user.
      if ($status_new === 'active') {
        iptv_cancel_other_active_subscriptions($pdo, $id, $sub_id);
      }

      flash_set("Subscription updated", "success");
    } catch (Throwable $e) {
      flash_set("Invalid date/time format", "error");
    }

    header("Location: user_accounts.php?edit=".$id);
    exit;
  }

  // Save user
  $username = trim($_POST['username'] ?? '');
  $name = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('Invalid email address','error');
    $dest = 'user_accounts.php' . ($id > 0 ? '?edit=' . $id : '');
    header('Location: ' . $dest);
    exit;
  }
  $password = (string)($_POST['password'] ?? '');
  $status = $_POST['status'] ?? 'active';
  $allow_adult = isset($_POST['allow_adult']) ? 1 : 0;
  $reseller_id = (int)($_POST['reseller_id'] ?? 0) ?: null;
  $device_lock = isset($_POST['device_lock']) ? 1 : 0;
  $ip_allowlist = trim((string)($_POST['ip_allowlist'] ?? ''));
  $ip_denylist  = trim((string)($_POST['ip_denylist'] ?? ''));
  $max_ip_changes = (int)($_POST['max_ip_changes'] ?? 0);
  $max_ip_window  = (int)($_POST['max_ip_window'] ?? 0);
  $tmdb_api_key   = trim((string)($_POST['tmdb_api_key'] ?? ''));
  $tmdb_region    = trim((string)($_POST['tmdb_region'] ?? ''));
  $app_logo_url   = trim((string)($_POST['app_logo_url'] ?? ''));
  $auto_gen = !empty($_POST['auto_gen']) ? 1 : 0;

  if ($id > 0) {
    if ($password !== '') {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $enc  = iptv_encrypt($password);
      $pdo->prepare("UPDATE users SET username=?, name=?, email=?, password_hash=?, password_enc=?, status=?, allow_adult=?, reseller_id=?, device_lock=?, ip_allowlist=?, ip_denylist=?, max_ip_changes=?, max_ip_window=?, tmdb_api_key=?, tmdb_region=?, app_logo_url=? WHERE id=?")
          ->execute([$username,$name,$email,$hash,$enc,$status,$allow_adult,$reseller_id,$device_lock,$ip_allowlist,$ip_denylist,$max_ip_changes,$max_ip_window,$tmdb_api_key,$tmdb_region,$app_logo_url,$id]);
    } else {
      $pdo->prepare("UPDATE users SET username=?, name=?, email=?, status=?, allow_adult=?, reseller_id=?, device_lock=?, ip_allowlist=?, ip_denylist=?, max_ip_changes=?, max_ip_window=?, tmdb_api_key=?, tmdb_region=?, app_logo_url=? WHERE id=?")
          ->execute([$username,$name,$email,$status,$allow_adult,$reseller_id,$device_lock,$ip_allowlist,$ip_denylist,$max_ip_changes,$max_ip_window,$tmdb_api_key,$tmdb_region,$app_logo_url,$id]);
    }
    flash_set("User updated","success");
  } else {
    if ($auto_gen) {
      $username = iptv_unique_numeric_username($pdo, 10);
      $password = iptv_numeric_string(10);
    }

    if ($password === '') {
      flash_set("Password is required for new users","error");
      header("Location: user_accounts.php");
      exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $enc  = iptv_encrypt($password);
    $pdo->prepare("INSERT INTO users (username,name,email,password_hash,password_enc,status,allow_adult,reseller_id,device_lock,ip_allowlist,ip_denylist,max_ip_changes,max_ip_window,tmdb_api_key,tmdb_region,app_logo_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$username,$name,$email,$hash,$enc,$status,$allow_adult,$reseller_id,$device_lock,$ip_allowlist,$ip_denylist,$max_ip_changes,$max_ip_window,$tmdb_api_key,$tmdb_region,$app_logo_url]);
    $newId = (int)$pdo->lastInsertId();

    // Admin notify: new user created
    admin_notifications_broadcast($pdo, 'user', 'New user joined', 'Admin created user ' . $username . '.', '/admin/user_accounts.php?edit=' . $newId, 'newuser:' . $newId);
    flash_set("User created (numeric credentials generated).","success");
    header("Location: user_accounts.php?edit=".$newId);
    exit;
  }
  header("Location: user_accounts.php");
    exit;
  }

if (isset($_GET['delete'])) {
  $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$_GET['delete']]);
  flash_set("User deleted","success");
  header("Location: user_accounts.php"); exit;
}

$edit=null;
$prefill_username = '';
$prefill_password = '';

if (isset($_GET['edit'])) {
  $st=$pdo->prepare("SELECT * FROM users WHERE id=?");
  $st->execute([$_GET['edit']]);
  $edit=$st->fetch();
}

if (!$edit) {
  // Defaults for Add User form
  $prefill_username = iptv_numeric_string(10);
  $prefill_password = iptv_numeric_string(10);
}


$subs_edit = [];
if ($edit) {
  $st = $pdo->prepare("
    SELECT s.*, p.name AS plan_name
    FROM subscriptions s
    JOIN plans p ON p.id=s.plan_id
    WHERE s.user_id=?
    ORDER BY s.ends_at DESC, s.id DESC
  ");
  $st->execute([$edit['id']]);
  $subs_edit = $st->fetchAll(PDO::FETCH_ASSOC);

  // Recent per-user request logs (get.php loads, stream starts, etc.)
  $user_logs = [];
  // Suspicious-activity summary for admin prompt (computed from recent logs)
  $user_suspicious = [
    'flag' => false,
    'reasons' => [],
    'unique_ips' => 0,
    'unique_fps' => 0,
    'unique_device_ids' => 0,
    'error_hits' => 0,
    'window' => '',
  ];
  try {
    $st = $pdo->prepare("\n      SELECT rl.created_at, rl.endpoint, rl.action, rl.ip, rl.device_fp, rl.user_agent, rl.status_code, rl.reason\n      FROM request_logs rl\n      WHERE rl.user_id=?\n      ORDER BY rl.id DESC\n      LIMIT 20\n    ");
    $st->execute([(int)$edit['id']]);
    $user_logs = $st->fetchAll(PDO::FETCH_ASSOC);

    // Compute simple suspicious activity indicators (based on last N logs).
    // Goal: lightweight prompt for admins without heavy queries.
    $ips = [];
    $fps = [];
    $devs = [];
    $min_ts = null;
    $max_ts = null;
    $err = 0;
    foreach ($user_logs as $r) {
      $ipx = (string)($r['ip'] ?? '');
      if ($ipx !== '') $ips[$ipx] = true;
      $fpx = (string)($r['device_fp'] ?? '');
      if ($fpx !== '') $fps[$fpx] = true;
      $ua = (string)($r['user_agent'] ?? '');
      if ($ua !== '' && preg_match('/device_id=([a-zA-Z0-9\-]{8,})/i', $ua, $m)) {
        $devs[$m[1]] = true;
      }
      $sc = (int)($r['status_code'] ?? 0);
      if ($sc >= 400) $err++;
      $t = $r['created_at'] ?? null;
      if ($t) {
        $ts = strtotime((string)$t);
        if ($ts !== false) {
          if ($min_ts === null || $ts < $min_ts) $min_ts = $ts;
          if ($max_ts === null || $ts > $max_ts) $max_ts = $ts;
        }
      }
    }

    $user_suspicious['unique_ips'] = count($ips);
    $user_suspicious['unique_fps'] = count($fps);
    $user_suspicious['unique_device_ids'] = count($devs);
    $user_suspicious['error_hits'] = $err;
    if ($min_ts !== null && $max_ts !== null) {
      $user_suspicious['window'] = date('Y-m-d H:i', $min_ts) . ' → ' . date('Y-m-d H:i', $max_ts);
    }

    // Heuristics (tuned for low false positives):
    // - 3+ different IPs in recent window
    // - 3+ different device_ids OR fingerprints
    // - Many error hits (auth_fail / banned / ip_not_allowed)
    if ($user_suspicious['unique_ips'] >= 3) {
      $user_suspicious['flag'] = true;
      $user_suspicious['reasons'][] = 'Multiple IPs detected (' . $user_suspicious['unique_ips'] . ')';
    }
    if ($user_suspicious['unique_device_ids'] >= 3) {
      $user_suspicious['flag'] = true;
      $user_suspicious['reasons'][] = 'Multiple device IDs detected (' . $user_suspicious['unique_device_ids'] . ')';
    } elseif ($user_suspicious['unique_fps'] >= 4) {
      $user_suspicious['flag'] = true;
      $user_suspicious['reasons'][] = 'Multiple device fingerprints detected (' . $user_suspicious['unique_fps'] . ')';
    }
    if ($user_suspicious['error_hits'] >= 5) {
      $user_suspicious['flag'] = true;
      $user_suspicious['reasons'][] = 'High error rate in recent activity (' . $user_suspicious['error_hits'] . ' errors)';
    }
  } catch (Throwable $e) {
    $user_logs = [];
  }

$plans_all = [];
try {
  $plans_all = $pdo->query("SELECT id,name,duration_days,max_streams,IFNULL(max_devices,2) AS max_devices, IFNULL(price,0) AS price FROM plans ORDER BY price ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $plans_all = $pdo->query("SELECT id,name,duration_days,max_streams,IFNULL(max_devices,2) AS max_devices FROM plans ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

}

$users=$pdo->query("
  SELECT u.*, r.username AS reseller_name,
         (SELECT COUNT(*) FROM user_devices ud WHERE ud.user_id=u.id) AS device_count
  FROM users u
  LEFT JOIN resellers r ON r.id=u.reseller_id
  ORDER BY u.created_at DESC
")->fetchAll();
$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
if (!function_exists('iptv_dt_local')) {
  function iptv_dt_local($dt) {
    if (!$dt) return '';
    try {
      $d = new DateTime((string)$dt);
      return $d->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
      return '';
    }
  }
}

?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Users</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>Users</h2>
  <?php flash_show(); ?>

  <h3><?= $edit ? "Edit User" : "Add User" ?></h3>
  <form method="post">
    <input type="hidden" name="op" value="save_user">
    <?php if($edit): ?><input type="hidden" name="id" value="<?=$edit['id']?>"><?php endif; ?>
    <label>Username</label>
    <input id="username" name="username" value="<?=e($edit['username'] ?? $prefill_username)?>" required>
    <label>Name</label>
    <input name="name" value="<?=e($edit['name'] ?? '')?>" placeholder="John Doe">
    <label>Email</label>
    <input name="email" type="email" value="<?=e($edit['email'] ?? '')?>" placeholder="user@example.com">
    <label>Password (leave blank to keep)</label>
    <input id="password" type="text" name="password" value="<?= $edit ? '' : e($prefill_password) ?>" <?= $edit ? '' : 'required' ?>>

    <?php if(!$edit): ?>
      <label style="margin-top:10px; display:block;">
        <input type="checkbox" id="auto_gen" name="auto_gen" value="1" checked>
        Auto-generate numeric username &amp; password
      </label>
      <button type="button" id="regen_btn" class="btn gray" style="margin-top:8px;">Regenerate</button>
    <?php endif; ?>
    <label>Status</label>
    <select name="status">
      <option value="active" <?=($edit['status']??'')==='active'?'selected':''?>>active</option>
      <option value="suspended" <?=($edit['status']??'')==='suspended'?'selected':''?>>suspended</option>
    </select>

    <label style="margin-top:10px;">
      <input type="checkbox" name="allow_adult" value="1" <?= !empty($edit["allow_adult"]) ? "checked" : "" ?>>
      Allow adult content for this user
    </label>

    <label style="margin-top:10px;">
      <input type="checkbox" name="device_lock" value="1" <?= !empty($edit["device_lock"]) ? "checked" : "" ?>>
      Device lock (bind to first device)
    </label>

    <div class="row" style="margin-top:12px;">
      <div>
        <label>Reseller (optional)</label>
        <select name="reseller_id">
          <option value="0">-- none --</option>
          <?php foreach($resellers as $r): ?>
            <option value="<?=$r['id']?>" <?= ((int)($edit['reseller_id'] ?? 0) === (int)$r['id']) ? 'selected' : '' ?>><?=e($r['username'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Max IP changes</label>
        <input name="max_ip_changes" type="number" min="0" value="<?=e($edit['max_ip_changes'] ?? 0)?>">
      </div>
      <div>
        <label>IP window (seconds)</label>
        <input name="max_ip_window" type="number" min="0" value="<?=e($edit['max_ip_window'] ?? 0)?>">
      </div>
    </div>

    <div class="row" style="margin-top:12px;">
      <div style="flex:1;">
        <label>IP allowlist (comma/space/newline)</label>
        <textarea name="ip_allowlist" rows="2" placeholder="1.2.3.4, 5.6.0.0/16"><?=e($edit['ip_allowlist'] ?? '')?></textarea>
      </div>
      <div style="flex:1;">
        <label>IP denylist (comma/space/newline)</label>
        <textarea name="ip_denylist" rows="2" placeholder="8.8.8.8, 10.0.0.0/8"><?=e($edit['ip_denylist'] ?? '')?></textarea>
      </div>
    </div>

    <h4 style="margin-top:18px;margin-bottom:6px;">App / TMDB Overrides (optional)</h4>
    <div class="row">
      <div>
        <label>TMDB API Key</label>
        <input name="tmdb_api_key" value="<?=e($edit['tmdb_api_key'] ?? '')?>" placeholder="leave blank to use global">
      </div>
      <div>
        <label>TMDB Region</label>
        <input name="tmdb_region" value="<?=e($edit['tmdb_region'] ?? '')?>" placeholder="US">
      </div>
      <div>
        <label>App Logo URL</label>
        <input name="app_logo_url" value="<?=e($edit['app_logo_url'] ?? '')?>" placeholder="https://...">
      </div>
    </div>

    <div style="margin-top:12px;">
      <button type="submit"><?= $edit ? "Update" : "Create" ?></button>
    </div>
  </form>

  <?php if($edit): ?>
    <div style="margin-top:14px; margin-bottom:6px;">
      <a class="btn" href="user_notes.php?user_id=<?= (int)$edit['id'] ?>">Notes / Tags</a>
    </div>
    <div class="card" style="margin-top:14px;">
      <h4 style="margin-top:0;">Subscription Dates (Admin)</h4>

      <?php if (empty($subs_edit)): ?>
        <div class="muted">No subscriptions found for this user. Assign one in <a href="plans_subs.php">Plans &amp; Subscriptions</a>.</div>
      <?php else: ?>
        <div class="muted" style="margin-bottom:8px;">Edit the start/end. Check “Unlimited” to set end date to 9999-12-31.</div>
        <?php foreach ($subs_edit as $s): ?>
          <?php $is_unlimited = !$s['ends_at'] || str_starts_with((string)$s['ends_at'], '9999-'); $end_id = 'end_'.$s['id']; ?>
          <form method="post" class="sub-form iptv-sub-form">
  <input type="hidden" name="id" value="<?=$edit['id']?>">
  <input type="hidden" name="sub_id" value="<?=$s['id']?>">

  <div class="sub-plan">
    <label>Plan</label>
    <select name="sub_plan_id" class="iptv-sub-plan">
      <?php foreach ($plans_all as $pp): ?>
        <option value="<?=$pp['id']?>" data-days="<?= (int)($pp['duration_days'] ?? 0) ?>" <?= ((int)$pp['id'] === (int)$s['plan_id']) ? 'selected' : '' ?>>
          <?=e($pp['name'])?> (<?= (int)($pp['duration_days'] ?? 0) ?>d / <?= (int)($pp['max_streams'] ?? 1) ?> streams / <?= (int)($pp['max_devices'] ?? 2) ?> dev)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="sub-status">
    <label>Status</label>
    <?php $stval = (string)($s['status'] ?? 'active'); ?>
    <select name="sub_status">
      <option value="active" <?= $stval==='active'?'selected':'' ?>>active</option>
      <option value="cancelled" <?= $stval==='cancelled'?'selected':'' ?>>cancelled</option>
      <option value="expired" <?= $stval==='expired'?'selected':'' ?>>expired</option>
    </select>
  </div>

  <div class="sub-begins">
    <label>Begins</label>
    <input type="datetime-local" name="sub_starts" class="iptv-sub-begins" value="<?=e(iptv_dt_local($s['starts_at'] ?? ''))?>" required>
  </div>

  <div class="sub-ends">
    <label>Ends</label>
    <input id="<?=e($end_id)?>" type="datetime-local" name="sub_ends" class="iptv-sub-ends"
      value="<?= $is_unlimited ? '' : e(iptv_dt_local($s['ends_at'] ?? '')) ?>"
      <?= $is_unlimited ? 'disabled' : '' ?> <?= $is_unlimited ? '' : 'required' ?>>
  </div>

  <div class="sub-check sub-reset">
    <label>Reset end</label>
    <label class="checkline">
      <input type="checkbox" name="sub_reset_end" value="1" class="iptv-sub-reset" <?= $is_unlimited ? 'disabled' : '' ?>>
      <span>begins + plan days</span>
    </label>
  </div>

  <div class="sub-check sub-unlimited">
    <label>Unlimited</label>
    <label class="checkline">
      <input type="checkbox" name="sub_unlimited" value="1" class="iptv-sub-unlimited" <?= $is_unlimited ? 'checked' : '' ?>>
      <span>never expires</span>
    </label>
  </div>

  <div class="sub-save">
    <div style="display:flex; gap:8px;">
      <button type="submit" name="op" value="update_sub">Save</button>
      <button type="submit" name="op" value="delete_sub" class="danger" onclick="return confirm('Delete this subscription? This cannot be undone.');">Delete</button>
    </div>
  </div>
</form>
<?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php
      $plain = iptv_decrypt($edit['password_enc'] ?? '');
      // Legacy recovery: if passwords were username-based (common in older installs),
      // we can safely recover by verifying against the hash.
      if ($plain === '') {
        $u = (string)($edit['username'] ?? '');
        $h = (string)($edit['password_hash'] ?? '');
        if ($u !== '' && $h !== '' && password_verify($u, $h)) {
          $plain = $u;
          // Backfill password_enc so it shows normally next time.
          try {
            $pdo->prepare("UPDATE users SET password_enc=? WHERE id=?")->execute([iptv_encrypt($plain), (int)$edit['id']]);
            $edit['password_enc'] = iptv_encrypt($plain);
          } catch (Throwable $e) { /* ignore */ }
        }
      }
      $m3u = $plain ? iptv_m3u_link((string)$edit['username'], $plain) : '';
    ?>
    <div class="card" style="margin-top:14px;">
      <h4 style="margin-top:0;">Account Info (Admin View)</h4>
      <div class="form-row" style="margin-bottom:6px;">
        <div>
          <label>Username</label>
          <div style="display:flex; gap:8px; align-items:center;">
            <input readonly value="<?=e($edit['username'])?>" onclick="this.select();">
            <button type="button" class="btn btn-small" style="box-shadow:none; background:#334155;" onclick="iptvCopyPrev(this)">Copy</button>
          </div>
        </div>
        <div>
          <label>Password</label>
          <?php if($plain): ?>
            <div style="display:flex; gap:8px; align-items:center;">
              <input readonly value="<?=e($plain)?>" onclick="this.select();">
              <button type="button" class="btn btn-small" style="box-shadow:none; background:#334155;" onclick="iptvCopyPrev(this)">Copy</button>
            </div>
          <?php else: ?>
            <div class="muted" style="margin-top:6px;">Not available (missing stored password OR secret-key mismatch after a reinstall). Use “Generate New Password”.</div>
          <?php endif; ?>
        </div>
      </div>

      <script>
      function iptvCopyPrev(btn){
        var input = btn && btn.parentElement ? btn.parentElement.querySelector('input') : null;
        if(!input) return;
        input.focus();
        input.select();
        try{ document.execCommand('copy'); }catch(e){}
      }
      </script>

      <div style="margin-top:10px;">
        <div class="muted">M3U Link</div>
        <?php if($m3u): ?>
          <a href="<?=e($m3u)?>" target="_blank"><?=e($m3u)?></a>
          <input class="input" style="margin-top:8px;" readonly value="<?=e($m3u)?>" onclick="this.select();">
        <?php else: ?>
          <span class="muted">Generate a new password to create a full clickable M3U link.</span>
        <?php endif; ?>
      </div>

      <form method="post" style="margin-top:12px;" onsubmit="return confirm('Generate a new numeric password for this user? This will change their login.');">
        <input type="hidden" name="op" value="gen_password">
        <input type="hidden" name="id" value="<?=$edit['id']?>">
        <button class="btn red" type="submit">Generate New Password</button>
      </form>
    </div>


    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
      <form method="post" onsubmit="return confirm('Clear device fingerprints + disable device lock?');" style="margin:0;">
        <input type="hidden" name="op" value="reset_devices">
        <input type="hidden" name="id" value="<?=$edit['id']?>">
        <button class="btn gray" type="submit">Reset Devices</button>
      </form>
      <form method="post" onsubmit="return confirm('Kill active sessions for this user?');" style="margin:0;">
        <input type="hidden" name="op" value="kill_sessions">
        <input type="hidden" name="id" value="<?=$edit['id']?>">
        <button class="btn red" type="submit">Kill Sessions</button>
      </form>
    </div>

    <div class="card" style="margin-top:14px;">
      <h4 style="margin-top:0;">Recent API / App Activity</h4>
      <div class="muted" style="margin-bottom:8px;">Shows recent requests tied to this user (e.g. <code>get</code> loads, stream starts). Device ID is parsed from the User-Agent if present.</div>
      <?php if (!empty($user_suspicious['flag'])): ?>
        <div style="border:1px solid #b91c1c; background:#fee2e2; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin:10px 0;">
          <strong>Suspicious activity detected</strong>
          <?php if (!empty($user_suspicious['window'])): ?>
            <span class="muted" style="color:#7f1d1d;">(window: <?=e($user_suspicious['window'])?>)</span>
          <?php endif; ?>
          <div style="margin-top:6px;">
            <?=e(implode(' • ', (array)$user_suspicious['reasons']))?>
          </div>
          <div class="muted" style="margin-top:6px; color:#7f1d1d;">
            Unique IPs: <?= (int)$user_suspicious['unique_ips'] ?>,
            Device IDs: <?= (int)$user_suspicious['unique_device_ids'] ?>,
            Device FPs: <?= (int)$user_suspicious['unique_fps'] ?>,
            Errors: <?= (int)$user_suspicious['error_hits'] ?>
          </div>
        </div>
      <?php endif; ?>
      <div style="overflow:auto;">
        <table>
          <tr>
            <th>Time</th>
            <th>Endpoint</th>
            <th>IP</th>
            <th>Device ID</th>
            <th>Device FP</th>
            <th>User-Agent</th>
            <th>Status</th>
            <th>Reason</th>
          </tr>
          <?php if(!empty($user_logs)): ?>
            <?php foreach($user_logs as $rl):
              $ua = (string)($rl['user_agent'] ?? '');
              $device_id = '';
              if ($ua !== '' && preg_match('/device_id=([a-f0-9\-]{8,64})/i', $ua, $m)) {
                $device_id = $m[1];
              }
            ?>
              <tr>
                <td><?=e($rl['created_at'] ?? '')?></td>
                <td><?=e($rl['endpoint'] ?? '')?></td>
                <td><?=e($rl['ip'] ?? '')?></td>
                <td style="font-family:monospace; font-size:12px;"><?=e($device_id)?></td>
                <td style="font-family:monospace; font-size:12px;"><?=e($rl['device_fp'] ?? '')?></td>
                <td style="max-width:520px; word-break:break-word;"><?=e($ua)?></td>
                <td><?=e($rl['status_code'] ?? '')?></td>
                <td><?=e($rl['reason'] ?? '')?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="8" class="muted">No request logs yet for this user.</td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<br>

<div class="card">
  <table>
    <tr><th>ID</th><th>User</th><th>Name</th><th>Email</th><th>Status</th><th>Adult</th><th>Created</th><th>Actions</th></tr>
    <?php foreach($users as $u): ?>
    <tr>
      <td><?=$u['id']?></td>
      <td><?=e($u['username'])?></td>
      <td><?=e($u['name'] ?? '')?></td>
      <td><?=e($u['email'] ?? '')?></td>
      <td><?=e($u['status'])?></td><td><?= !empty($u['allow_adult']) ? 'yes' : 'no' ?></td>
      <td><?=e($u['created_at'])?></td>
      <td>
        <a href="user_accounts.php?edit=<?=$u['id']?>">Edit</a> |
        <a href="abuse_bans.php?type=user&user_id=<?=$u['id']?>">Ban</a> |
        <a href="user_accounts.php?delete=<?=$u['id']?>" onclick="return confirm('Delete user?')">Delete</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
<script>
(function(){
  var auto = document.getElementById('auto_gen');
  var btn  = document.getElementById('regen_btn');
  var uEl  = document.getElementById('username');
  var pEl  = document.getElementById('password');
  if(!btn || !uEl || !pEl) return;
  function genDigits(n){
    var s='';
    for(var i=0;i<n;i++){ s += Math.floor(Math.random()*10); }
    return s;
  }
  function regen(){
    if(auto && !auto.checked) return;
    uEl.value = genDigits(10);
    pEl.value = genDigits(10);
  }
  btn.addEventListener('click', function(){ if(auto) auto.checked = true; regen(); });
})();

(function(){
  function pad2(n){ return String(n).padStart(2,'0'); }
  function parseDT(v){
    if(!v) return null;
    var d = new Date(v);
    if(isNaN(d.getTime())) return null;
    return d;
  }
  function fmtDT(d){
    return d.getFullYear() + '-' + pad2(d.getMonth()+1) + '-' + pad2(d.getDate()) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
  }
  function planDays(sel){
    var opt = sel && sel.options ? sel.options[sel.selectedIndex] : null;
    var days = opt ? parseInt(opt.getAttribute('data-days') || '0', 10) : 0;
    if(!days || days < 1) days = 30;
    return days;
  }
  function sync(form){
    var plan = form.querySelector('.iptv-sub-plan');
    var begins = form.querySelector('.iptv-sub-begins');
    var ends = form.querySelector('.iptv-sub-ends');
    var reset = form.querySelector('.iptv-sub-reset');
    var unlim = form.querySelector('.iptv-sub-unlimited');
    if(!plan || !begins || !ends || !reset || !unlim) return;

    // Unlimited overrides everything
    if(unlim.checked){
      ends.disabled = true;
      reset.disabled = true;
      return;
    }
    reset.disabled = false;

    // If "reset end" is checked, auto-calc + lock end input (backend ignores manual end anyway)
    if(reset.checked){
      var b = parseDT(begins.value);
      if(b){
        b.setDate(b.getDate() + planDays(plan));
        ends.value = fmtDT(b);
      }
      ends.disabled = true;
    } else {
      ends.disabled = false;
    }
  }

  document.querySelectorAll('form.iptv-sub-form').forEach(function(form){
    // initial cleanup
    sync(form);

    form.addEventListener('change', function(e){
      if(!e || !e.target) return;
      if(e.target.classList.contains('iptv-sub-plan') ||
         e.target.classList.contains('iptv-sub-begins') ||
         e.target.classList.contains('iptv-sub-reset') ||
         e.target.classList.contains('iptv-sub-unlimited')) {
        sync(form);
      }
    });
    form.addEventListener('input', function(e){
      if(!e || !e.target) return;
      if(e.target.classList.contains('iptv-sub-begins')) sync(form);
    });
  });
})();
</script>
</body>
</html>
