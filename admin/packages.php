<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_admin();

$pdo = db();
ensure_categories($pdo);

$package_id = (int)($_GET['package_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['create_package'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
      $pdo->prepare("INSERT IGNORE INTO packages (name) VALUES (?)")->execute([$name]);
      flash_set("Package created", "success");
    }
    header("Location: packages.php");
    exit;
  }

  if (isset($_POST['save_package_channels'])) {
    $pid = (int)($_POST['package_id'] ?? 0);
    $ids = $_POST['channel_ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));

    $pdo->prepare("DELETE FROM package_channels WHERE package_id=?")->execute([$pid]);
    if ($ids) {
      $ins = $pdo->prepare("INSERT INTO package_channels (package_id, channel_id) VALUES (?,?)");
      foreach ($ids as $cid) $ins->execute([$pid, $cid]);
    }
    flash_set("Package channels saved", "success");
    header("Location: packages.php?package_id=".$pid);
    exit;
  }

  if (isset($_POST['assign_user_packages'])) {
    $uid = (int)($_POST['user_id'] ?? 0);
    $pids = $_POST['package_ids'] ?? [];
    if (!is_array($pids)) $pids = [];
    $pids = array_values(array_unique(array_map('intval', $pids)));

    $pdo->prepare("DELETE FROM user_packages WHERE user_id=?")->execute([$uid]);
    if ($pids) {
      $ins = $pdo->prepare("INSERT INTO user_packages (user_id, package_id) VALUES (?,?)");
      foreach ($pids as $pid) $ins->execute([$uid, $pid]);
    }
    flash_set("User packages saved", "success");
    header("Location: packages.php?package_id=".$package_id);
    exit;
  }

  if (isset($_POST['save_package_movies'])) {
    $pid = (int)($_POST['package_id'] ?? 0);
    $ids = $_POST['movie_ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));

    $pdo->prepare("DELETE FROM package_movies WHERE package_id=?")->execute([$pid]);
    if ($ids) {
      $ins = $pdo->prepare("INSERT INTO package_movies (package_id, movie_id) VALUES (?,?)");
      foreach ($ids as $mid) $ins->execute([$pid, $mid]);
    }
    flash_set("Package movies saved", "success");
    header("Location: packages.php?package_id=".$pid);
    exit;
  }

  if (isset($_POST['save_package_series'])) {
    $pid = (int)($_POST['package_id'] ?? 0);
    $ids = $_POST['series_ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));

    $pdo->prepare("DELETE FROM package_series WHERE package_id=?")->execute([$pid]);
    if ($ids) {
      $ins = $pdo->prepare("INSERT INTO package_series (package_id, series_id) VALUES (?,?)");
      foreach ($ids as $sid) $ins->execute([$pid, $sid]);
    }
    flash_set("Package series saved", "success");
    header("Location: packages.php?package_id=".$pid);
    exit;
  }
}

$packages = $pdo->query("
  SELECT p.*, 
    (SELECT COUNT(*) FROM package_channels pc WHERE pc.package_id=p.id) AS chan_count,
    (SELECT COUNT(*) FROM package_movies pm WHERE pm.package_id=p.id) AS movie_count,
    (SELECT COUNT(*) FROM package_series ps WHERE ps.package_id=p.id) AS series_count,
    (SELECT COUNT(*) FROM user_packages up WHERE up.package_id=p.id) AS user_count
  FROM packages p
  ORDER BY p.name
")->fetchAll();

$selected = null;
$selected_ids = [];
$selected_movie_ids = [];
$selected_series_ids = [];
if ($package_id > 0) {
  $st = $pdo->prepare("SELECT * FROM packages WHERE id=?");
  $st->execute([$package_id]);
  $selected = $st->fetch();

  if ($selected) {
    $st = $pdo->prepare("SELECT channel_id FROM package_channels WHERE package_id=?");
    $st->execute([$package_id]);
    $selected_ids = array_map(fn($r)=>(int)$r['channel_id'], $st->fetchAll());

    $st = $pdo->prepare("SELECT movie_id FROM package_movies WHERE package_id=?");
    $st->execute([$package_id]);
    $selected_movie_ids = array_map(fn($r)=>(int)$r['movie_id'], $st->fetchAll());

    $st = $pdo->prepare("SELECT series_id FROM package_series WHERE package_id=?");
    $st->execute([$package_id]);
    $selected_series_ids = array_map(fn($r)=>(int)$r['series_id'], $st->fetchAll());
  }
}

$channels = $pdo->query("SELECT c.id,c.name,IFNULL(c.group_title,'Uncategorized') AS grp, IFNULL(c.is_adult,0) AS is_adult,
  IFNULL(cat.sort_order, 999999) AS cat_sort, IFNULL(c.sort_order, c.id) AS ch_sort
  FROM channels c
  LEFT JOIN categories cat ON cat.id=c.category_id
  ORDER BY cat_sort, ch_sort, c.id")->fetchAll();
$movies = [];
$series_list = [];
try {
  $movies = $pdo->query("SELECT m.id,m.name,IFNULL(vc.name,'VOD') AS cat_name, IFNULL(m.is_adult,0) AS is_adult FROM movies m LEFT JOIN vod_categories vc ON vc.id=m.category_id ORDER BY cat_name, m.name")->fetchAll();
} catch (Throwable $e) { $movies = []; }
try {
  $series_list = $pdo->query("SELECT s.id,s.name,IFNULL(sc.name,'Series') AS cat_name, IFNULL(s.is_adult,0) AS is_adult FROM series s LEFT JOIN series_categories sc ON sc.id=s.category_id ORDER BY cat_name, s.name")->fetchAll();
} catch (Throwable $e) { $series_list = []; }
$users = $pdo->query("SELECT id,username,status FROM users ORDER BY username")->fetchAll();

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
  <title>Packages</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Packages (Bouquets)</h2>
  <?php flash_show(); ?>

  <form method="post" style="display:flex;gap:10px;align-items:flex-end;">
    <input type="hidden" name="create_package" value="1">
    <div style="flex:1;">
      <label>New package name</label>
      <input name="name" placeholder="USA / Sports / Adult..." required>
    </div>
    <button>Create</button>
  </form>
</div>

<br>

<div class="card">
  <table>
    <tr><th>Package</th><th>Live</th><th>Movies</th><th>Series</th><th>Users</th><th></th></tr>
    <?php foreach($packages as $p): ?>
      <tr>
        <td><?=e($p['name'])?></td>
        <td><?= (int)$p['chan_count'] ?></td>
        <td><?= (int)$p['movie_count'] ?></td>
        <td><?= (int)$p['series_count'] ?></td>
        <td><?= (int)$p['user_count'] ?></td>
        <td><a class="btn gray" href="packages.php?package_id=<?=$p['id']?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if($selected): ?>
<br>
<div class="card">
  <h2>Edit Package: <?=e($selected['name'])?></h2>
  <p class="muted">Users with NO packages assigned can see ALL content (default open). As soon as you assign 1+ packages to a user, they only see items included in those packages (Live + Movies + Series).</p>

  <form method="post">
    <input type="hidden" name="save_package_channels" value="1">
    <input type="hidden" name="package_id" value="<?=$selected['id']?>">

    <div style="max-height:520px;overflow:auto;border:1px solid #1f2937;border-radius:12px;padding:10px;">
      <?php foreach($channels as $c): ?>
        <label style="display:flex;gap:10px;align-items:center;padding:6px;border-bottom:1px solid #0b1220;">
          <input type="checkbox" name="channel_ids[]" value="<?=$c['id']?>" <?= in_array((int)$c['id'],$selected_ids,true) ? 'checked' : '' ?>>
          <span class="code" style="min-width:50px;opacity:.8;">#<?=$c['id']?></span>
          <span style="flex:1;"><?=e($c['name'])?></span>
          <span class="pill <?= $c['is_adult'] ? 'bad':'good' ?>"><?= $c['is_adult'] ? 'ADULT':'OK' ?></span>
          <span class="pill"><?=e($c['grp'])?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:12px;">
      <button>Save Channels</button>
    </div>
  </form>

  <hr style="border:0;border-top:1px solid #1f2937;margin:20px 0;">

  <h3>Movies (VOD)</h3>
  <form method="post">
    <input type="hidden" name="save_package_movies" value="1">
    <input type="hidden" name="package_id" value="<?=$selected['id']?>">

    <?php if (!$movies): ?>
      <p class="muted">No movies table/data found (or VOD module not installed).</p>
    <?php else: ?>
      <div style="max-height:520px;overflow:auto;border:1px solid #1f2937;border-radius:12px;padding:10px;">
        <?php foreach($movies as $m): ?>
          <label style="display:flex;gap:10px;align-items:center;padding:6px;border-bottom:1px solid #0b1220;">
            <input type="checkbox" name="movie_ids[]" value="<?=$m['id']?>" <?= in_array((int)$m['id'],$selected_movie_ids,true) ? 'checked' : '' ?>>
            <span class="code" style="min-width:50px;opacity:.8;">#<?=$m['id']?></span>
            <span style="flex:1;"><?=e($m['name'])?></span>
            <span class="pill <?= $m['is_adult'] ? 'bad':'good' ?>"><?= $m['is_adult'] ? 'ADULT':'OK' ?></span>
            <span class="pill"><?=e($m['cat_name'])?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px;">
        <button>Save Movies</button>
      </div>
    <?php endif; ?>
  </form>

  <hr style="border:0;border-top:1px solid #1f2937;margin:20px 0;">

  <h3>Series</h3>
  <form method="post">
    <input type="hidden" name="save_package_series" value="1">
    <input type="hidden" name="package_id" value="<?=$selected['id']?>">

    <?php if (!$series_list): ?>
      <p class="muted">No series table/data found (or Series module not installed).</p>
    <?php else: ?>
      <div style="max-height:520px;overflow:auto;border:1px solid #1f2937;border-radius:12px;padding:10px;">
        <?php foreach($series_list as $s): ?>
          <label style="display:flex;gap:10px;align-items:center;padding:6px;border-bottom:1px solid #0b1220;">
            <input type="checkbox" name="series_ids[]" value="<?=$s['id']?>" <?= in_array((int)$s['id'],$selected_series_ids,true) ? 'checked' : '' ?>>
            <span class="code" style="min-width:50px;opacity:.8;">#<?=$s['id']?></span>
            <span style="flex:1;"><?=e($s['name'])?></span>
            <span class="pill <?= $s['is_adult'] ? 'bad':'good' ?>"><?= $s['is_adult'] ? 'ADULT':'OK' ?></span>
            <span class="pill"><?=e($s['cat_name'])?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px;">
        <button>Save Series</button>
      </div>
    <?php endif; ?>
  </form>
</div>

<br>
<div class="card">
  <h2>Assign Packages to Users</h2>
  <form method="post">
    <input type="hidden" name="assign_user_packages" value="1">
    <div class="row">
      <div>
        <label>User</label>
        <select name="user_id" required>
          <?php foreach($users as $u): ?>
            <option value="<?=$u['id']?>"><?=e($u['username'])?> (<?=$u['status']?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:2;">
        <label>Packages (multi-select)</label>
        <select name="package_ids[]" multiple size="6" style="width:100%;">
          <?php foreach($packages as $p): ?>
            <option value="<?=$p['id']?>"><?=e($p['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:0;">
        <label style="visibility:hidden">Save</label>
        <button>Save</button>
      </div>
    </div>
    <p class="muted" style="margin-top:10px;">Tip: to give a user full access, leave them with NO packages assigned (default). To restrict, assign 1+ packages.</p>
  </form>
</div>
<?php endif; ?>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
