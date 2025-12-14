<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();
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

$users=$pdo->query("
  SELECT u.*, r.username AS reseller_name,
         (SELECT COUNT(*) FROM user_devices ud WHERE ud.user_id=u.id) AS device_count
  FROM users u
  LEFT JOIN resellers r ON r.id=u.reseller_id
  ORDER BY u.created_at DESC
")->fetchAll();
$topbar = file_get_contents(__DIR__ . '/topbar.html');
?>
<!doctype html>
<html>
<head>
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
    <?php $plain = iptv_decrypt($edit['password_enc'] ?? ''); $m3u = $plain ? iptv_m3u_link((string)$edit['username'], $plain) : ''; ?>
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
            <div class="muted" style="margin-top:6px;">Not available (was created before password storage). Use “Generate New Password”.</div>
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
</script>
</body>
</html>
