<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

// Quick actions
if (isset($_GET['unban'])) {
  $id = (int)$_GET['unban'];
  $pdo->prepare("DELETE FROM abuse_bans WHERE id=?")->execute([$id]);
  flash_set("Ban removed","success");
  header("Location: abuse_bans.php");
  exit;
}

$prefill_type = $_GET['type'] ?? '';
$prefill_ip   = $_GET['ip'] ?? '';
$prefill_user = '';
if (isset($_GET['user_id'])) {
  $uid = (int)$_GET['user_id'];
  if ($uid > 0) {
    $st = $pdo->prepare("SELECT username FROM users WHERE id=?");
    $st->execute([$uid]);
    $prefill_user = (string)($st->fetchColumn() ?: '');
    $prefill_type = $prefill_type ?: 'user';
  }
}

// Add ban
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ban_type = ($_POST['ban_type'] ?? 'ip') === 'user' ? 'user' : 'ip';
  $ip = trim((string)($_POST['ip'] ?? ''));
  $username = trim((string)($_POST['username'] ?? ''));
  $reason = trim((string)($_POST['reason'] ?? ''));
  $duration = (int)($_POST['duration'] ?? 0);
  $unit = $_POST['unit'] ?? 'days';
  $permanent = isset($_POST['permanent']);

  $expires_at = null;
  if (!$permanent && $duration > 0) {
    if ($unit === 'hours') $expires_at = date('Y-m-d H:i:s', time() + ($duration * 3600));
    else $expires_at = date('Y-m-d H:i:s', time() + ($duration * 86400));
  }

  $user_id = null;
  if ($ban_type === 'user') {
    if ($username === '') {
      flash_set("Username is required for a user ban","error");
      header("Location: abuse_bans.php");
      exit;
    }
    $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $user_id = (int)($st->fetchColumn() ?: 0);
    if ($user_id < 1) {
      flash_set("User not found","error");
      header("Location: abuse_bans.php");
      exit;
    }
  } else {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
      flash_set("Valid IP address is required for an IP ban","error");
      header("Location: abuse_bans.php");
      exit;
    }
  }

  $pdo->prepare("INSERT INTO abuse_bans (ban_type, ip, user_id, reason, expires_at, created_by) VALUES (?,?,?,?,?,?)")
      ->execute([$ban_type, $ban_type==='ip' ? $ip : null, $ban_type==='user' ? $user_id : null, $reason ?: null, $expires_at, (int)($_SESSION['admin_id'] ?? 0) ?: null]);

  audit_log('ban_create', $user_id ?: null, ['ban_type'=>$ban_type,'ip'=>$ip,'username'=>$username,'expires_at'=>$expires_at,'reason'=>$reason]);
  flash_set("Ban added","success");
  header("Location: abuse_bans.php");
  exit;
}

// List bans
$show_all = !empty($_GET['all']);
if ($show_all) {
  $bans = $pdo->query("SELECT b.*, u.username FROM abuse_bans b LEFT JOIN users u ON u.id=b.user_id ORDER BY b.created_at DESC, b.id DESC")->fetchAll();
} else {
  $bans = $pdo->query("SELECT b.*, u.username FROM abuse_bans b LEFT JOIN users u ON u.id=b.user_id WHERE (b.expires_at IS NULL OR b.expires_at > NOW()) ORDER BY (b.expires_at IS NULL) DESC, b.expires_at DESC, b.id DESC")->fetchAll();
}

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
  <title>Abuse Bans</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    .grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);font-size:12px}
    .muted{opacity:.8}
    .row-actions a{margin-right:10px}
    pre.code{white-space:pre-wrap;word-break:break-word}
    @media(max-width:900px){.grid2{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>Abuse Bans</h2>
  <?php flash_show(); ?>

  <div class="grid2">
    <div>
      <h3>Add Ban</h3>
      <form method="post">
        <label>Type</label>
        <select name="ban_type" id="ban_type" onchange="toggleBanType()">
          <option value="ip" <?=($prefill_type!=='user')?'selected':''?>>IP ban</option>
          <option value="user" <?=($prefill_type==='user')?'selected':''?>>User ban</option>
        </select>

        <div id="ip_wrap">
          <label>IP Address</label>
          <input name="ip" value="<?=e($prefill_ip)?>" placeholder="e.g. 1.2.3.4">
        </div>

        <div id="user_wrap">
          <label>Username</label>
          <input name="username" value="<?=e($prefill_user)?>" placeholder="client username">
        </div>

        <label>Reason (optional)</label>
        <input name="reason" value="" placeholder="restream / brute force / chargeback / etc">

        <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:8px">
          <div style="flex:1;min-width:160px">
            <label>Duration (optional)</label>
            <input type="number" name="duration" min="0" value="0">
          </div>
          <div style="min-width:140px">
            <label>Unit</label>
            <select name="unit">
              <option value="days">days</option>
              <option value="hours">hours</option>
            </select>
          </div>
          <label style="margin:0 0 6px 0">
            <input type="checkbox" name="permanent" value="1" checked>
            Permanent
          </label>
        </div>

        <button class="btn" type="submit" style="margin-top:12px">Add Ban</button>
        <p class="muted" style="margin-top:10px">
          Bans override user IP allow/deny rules.
        </p>
      </form>
    </div>

    <div>
      <h3>Tips</h3>
      <div class="card" style="margin:0">
        <div class="muted">
          <div><span class="pill">Ban IP</span> blocks any request from that IP before auth.</div>
          <div style="margin-top:8px"><span class="pill">Ban User</span> blocks the account even if they change IPs.</div>
          <div style="margin-top:8px">Use <span class="pill">Temporary</span> bans for spam bursts.</div>
        </div>
      </div>
      <div style="margin-top:12px">
        <a class="btn" href="abuse_bans.php?<?= $show_all ? '' : 'all=1' ?>"><?= $show_all ? 'Show active only' : 'Show all (incl. expired)' ?></a>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <h3><?= $show_all ? 'All bans' : 'Active bans' ?></h3>
  <table>
    <tr>
      <th>ID</th><th>Type</th><th>Target</th><th>Reason</th><th>Expires</th><th>Created</th><th></th>
    </tr>
    <?php foreach($bans as $b): ?>
      <tr>
        <td><?= (int)$b['id'] ?></td>
        <td><span class="pill"><?= e($b['ban_type']) ?></span></td>
        <td>
          <?php if($b['ban_type']==='ip'): ?>
            <code><?= e($b['ip'] ?? '') ?></code>
          <?php else: ?>
            <strong><?= e($b['username'] ?? ('#'.(int)$b['user_id'])) ?></strong>
          <?php endif; ?>
        </td>
        <td class="muted"><?= e($b['reason'] ?? '') ?></td>
        <td class="muted"><?= e($b['expires_at'] ?? 'never') ?></td>
        <td class="muted"><?= e($b['created_at'] ?? '') ?></td>
        <td class="row-actions">
          <a href="abuse_bans.php?unban=<?= (int)$b['id'] ?>" onclick="return confirm('Remove this ban?')">Unban</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<script>
function toggleBanType(){
  var t = document.getElementById('ban_type').value;
  document.getElementById('ip_wrap').style.display = (t==='ip') ? 'block' : 'none';
  document.getElementById('user_wrap').style.display = (t==='user') ? 'block' : 'none';
}
toggleBanType();
</script>

</body>
</html>
