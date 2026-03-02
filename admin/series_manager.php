<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['add_cat'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
      $pdo->prepare("INSERT IGNORE INTO series_categories (name) VALUES (?)")->execute([$name]);
      flash_set("Category added.", "success");
    }
    header("Location: series_manager.php"); exit;
  }
  if (isset($_POST['del_cat'])) {
    $cid = (int)($_POST['category_id'] ?? 0);
    if ($cid>0) {
      $pdo->prepare("UPDATE series SET category_id=NULL WHERE category_id=?")->execute([$cid]);
      $pdo->prepare("DELETE FROM series_categories WHERE id=?")->execute([$cid]);
      flash_set("Category deleted.", "success");
    }
    header("Location: series_manager.php"); exit;
  }
  if (isset($_POST['add_series'])) {
    $name = trim($_POST['series_name'] ?? '');
    $cid  = (int)($_POST['category_id'] ?? 0);
    $cover = trim($_POST['cover_url'] ?? '');
    $adult = !empty($_POST['is_adult']) ? 1 : 0;
    if ($name !== '') {
      $pdo->prepare("INSERT INTO series (category_id, name, cover_url, is_adult) VALUES (?,?,?,?)")
          ->execute([$cid?:null, $name, $cover?:null, $adult]);
      flash_set("Series added.", "success");
    } else {
      flash_set("Series name required.", "error");
    }
    header("Location: series_manager.php"); exit;
  }

  if (isset($_POST['update_series'])) {
    $sid = (int)($_POST['series_id'] ?? 0);
    $name = trim($_POST['series_name'] ?? '');
    $cid  = (int)($_POST['category_id'] ?? 0);
    $cover = trim($_POST['cover_url'] ?? '');
    $adult = !empty($_POST['is_adult']) ? 1 : 0;
    if ($sid>0 && $name !== '') {
      $pdo->prepare("UPDATE series SET category_id=?, name=?, cover_url=?, is_adult=? WHERE id=?")
          ->execute([$cid?:null, $name, $cover?:null, $adult, $sid]);
      flash_set("Series updated.", "success");
    } else {
      flash_set("Series name required.", "error");
    }
    header("Location: series_manager.php"); exit;
  }
  if (isset($_POST['del_series'])) {
    $sid = (int)($_POST['series_id'] ?? 0);
    if ($sid>0) {
      $pdo->prepare("DELETE FROM series WHERE id=?")->execute([$sid]);
      flash_set("Series deleted.", "success");
    }
    header("Location: series_manager.php"); exit;
  }
  if (isset($_POST['add_episode'])) {
    $series_id = (int)($_POST['series_id'] ?? 0);
    $season = (int)($_POST['season_num'] ?? 1);
    $epnum = (int)($_POST['episode_num'] ?? 1);
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['stream_url'] ?? '');
    $ext = trim($_POST['container_ext'] ?? '');
    if ($series_id>0 && $title !== '' && $url !== '') {
      $pdo->prepare("INSERT INTO series_episodes (series_id, season_num, episode_num, title, stream_url, container_ext) VALUES (?,?,?,?,?,?)")
          ->execute([$series_id, max(1,$season), max(1,$epnum), $title, $url, $ext?:null]);
      flash_set("Episode added.", "success");
    } else {
      flash_set("Series + title + URL required.", "error");
    }
    header("Location: series_manager.php"); exit;
  }

  if (isset($_POST['update_episode'])) {
    $eid = (int)($_POST['episode_id'] ?? 0);
    $series_id = (int)($_POST['series_id'] ?? 0);
    $season = (int)($_POST['season_num'] ?? 1);
    $epnum = (int)($_POST['episode_num'] ?? 1);
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['stream_url'] ?? '');
    $ext = trim($_POST['container_ext'] ?? '');
    if ($eid>0 && $series_id>0 && $title !== '' && $url !== '') {
      $pdo->prepare("UPDATE series_episodes SET series_id=?, season_num=?, episode_num=?, title=?, stream_url=?, container_ext=? WHERE id=?")
          ->execute([$series_id, max(1,$season), max(1,$epnum), $title, $url, $ext?:null, $eid]);
      flash_set("Episode updated.", "success");
    } else {
      flash_set("Series + title + URL required.", "error");
    }
    header("Location: series_manager.php"); exit;
  }
  if (isset($_POST['del_episode'])) {
    $eid = (int)($_POST['episode_id'] ?? 0);
    if ($eid>0) {
      $pdo->prepare("DELETE FROM series_episodes WHERE id=?")->execute([$eid]);
      flash_set("Episode deleted.", "success");
    }
    header("Location: series_manager.php"); exit;
  }
}

$cats = $pdo->query("SELECT id,name FROM series_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$all_series = $pdo->query("SELECT id,name FROM series ORDER BY name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);

$edit_series_id = (int)($_GET['edit_series'] ?? 0);
$edit_series = null;
if ($edit_series_id>0) {
  $st = $pdo->prepare("SELECT id,category_id,name,cover_url,is_adult FROM series WHERE id=?");
  $st->execute([$edit_series_id]);
  $edit_series = $st->fetch(PDO::FETCH_ASSOC);
  if (!$edit_series) { $edit_series_id = 0; }
}

$edit_episode_id = (int)($_GET['edit_episode'] ?? 0);
$edit_episode = null;
if ($edit_episode_id>0) {
  $st = $pdo->prepare("SELECT id,series_id,season_num,episode_num,title,stream_url,container_ext FROM series_episodes WHERE id=?");
  $st->execute([$edit_episode_id]);
  $edit_episode = $st->fetch(PDO::FETCH_ASSOC);
  if (!$edit_episode) { $edit_episode_id = 0; }
}
$series = $pdo->query("
  SELECT s.id,s.name,s.cover_url,s.is_adult,s.created_at, c.name AS cat_name,
         (SELECT COUNT(*) FROM series_episodes e WHERE e.series_id=s.id) AS eps
  FROM series s
  LEFT JOIN series_categories c ON c.id=s.category_id
  ORDER BY s.id DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$episodes = $pdo->query("
  SELECT e.id,e.series_id,e.season_num,e.episode_num,e.title,e.container_ext,e.created_at, s.name AS series_name
  FROM series_episodes e
  JOIN series s ON s.id=e.series_id
  ORDER BY e.id DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

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
  <title>Series Manager</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>
<div class="card">
  <h2>Series Manager</h2>
  <?php flash_show(); ?>
</div>

<br>

<div class="card">
  <h3>Categories</h3>
  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="text" name="name" placeholder="New category name" required>
    <button class="btn" name="add_cat" value="1">Add</button>
  </form>
  <div style="margin-top:10px;">
    <?php foreach($cats as $c): ?>
      <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #1f2a44;">
        <span><?=e($c['name'])?></span>
        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this category? Series will be uncategorized.');">
          <input type="hidden" name="category_id" value="<?=$c['id']?>">
          <button class="btn red" name="del_cat" value="1">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<br>

<?php if (!empty($edit_series)): ?>
<div class="card">
  <h3>Edit Series (ID <?=$edit_series['id']?>)</h3>
  <form method="post">
    <input type="hidden" name="series_id" value="<?=$edit_series['id']?>">
    <div class="row">
      <label>Name</label>
      <input name="series_name" value="<?=e($edit_series['name'])?>" required>
    </div>
    <div class="row">
      <label>Cover URL</label>
      <input name="cover_url" value="<?=e($edit_series['cover_url'] ?? '')?>" placeholder="http(s)://...">
    </div>
    <div class="row">
      <label>Category</label>
      <select name="category_id">
        <option value="0" <?= (int)($edit_series['category_id'] ?? 0)===0 ? 'selected' : '' ?>>Uncategorized</option>
        <?php foreach($cats as $c): ?>
          <option value="<?=$c['id']?>" <?= (int)($edit_series['category_id'] ?? 0)===(int)$c['id'] ? 'selected' : '' ?>><?=e($c['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <label>Adult</label>
      <input type="checkbox" name="is_adult" value="1" <?= (int)($edit_series['is_adult'] ?? 0) ? 'checked' : '' ?>>
    </div>
    <button class="btn" name="update_series" value="1">Save Changes</button>
    <a class="btn gray" href="series_manager.php">Cancel</a>
  </form>
</div>

<br>
<?php endif; ?>

<div class="card">
  <h3>Add Series</h3>
  <form method="post">
    <div class="row">
      <label>Name</label>
      <input name="series_name" required>
    </div>
    <div class="row">
      <label>Cover URL</label>
      <input name="cover_url" placeholder="http(s)://...">
    </div>
    <div class="row">
      <label>Category</label>
      <select name="category_id">
        <option value="0">Uncategorized</option>
        <?php foreach($cats as $c): ?>
          <option value="<?=$c['id']?>"><?=e($c['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <label>Adult</label>
      <input type="checkbox" name="is_adult" value="1">
    </div>
    <button class="btn" name="add_series" value="1">Add Series</button>
  </form>
</div>

<br>

<?php if (!empty($edit_episode)): ?>
<div class="card">
  <h3>Edit Episode (ID <?=$edit_episode['id']?>)</h3>
  <form method="post">
    <input type="hidden" name="episode_id" value="<?=$edit_episode['id']?>">
    <div class="row">
      <label>Series</label>
      <select name="series_id" required>
        <option value="">Choose…</option>
        <?php foreach($all_series as $s2): ?>
          <option value="<?=$s2['id']?>" <?= (int)($edit_episode['series_id'] ?? 0)===(int)$s2['id'] ? 'selected' : '' ?>><?=e($s2['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <label>Season</label>
      <input name="season_num" type="number" value="<?= (int)($edit_episode['season_num'] ?? 1) ?>" min="1">
    </div>
    <div class="row">
      <label>Episode</label>
      <input name="episode_num" type="number" value="<?= (int)($edit_episode['episode_num'] ?? 1) ?>" min="1">
    </div>
    <div class="row">
      <label>Title</label>
      <input name="title" value="<?=e($edit_episode['title'])?>" required>
    </div>
    <div class="row">
      <label>Stream URL</label>
      <input name="stream_url" value="<?=e($edit_episode['stream_url'])?>" required>
    </div>
    <div class="row">
      <label>Container Ext</label>
      <input name="container_ext" value="<?=e($edit_episode['container_ext'] ?? '')?>" placeholder="mp4 / mkv / m3u8">
    </div>
    <button class="btn" name="update_episode" value="1">Save Changes</button>
    <a class="btn gray" href="series_manager.php">Cancel</a>
  </form>
</div>

<br>
<?php endif; ?>

<div class="card">
  <h3>Add Episode</h3>
  <form method="post">
    <div class="row">
      <label>Series</label>
      <select name="series_id" required>
        <option value="">Choose…</option>
        <?php foreach($series as $s): ?>
          <option value="<?=$s['id']?>"><?=e($s['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <label>Season</label>
      <input name="season_num" type="number" value="1" min="1">
    </div>
    <div class="row">
      <label>Episode</label>
      <input name="episode_num" type="number" value="1" min="1">
    </div>
    <div class="row">
      <label>Title</label>
      <input name="title" required>
    </div>
    <div class="row">
      <label>Stream URL</label>
      <input name="stream_url" required>
    </div>
    <div class="row">
      <label>Container Ext</label>
      <input name="container_ext" placeholder="mp4 / mkv / m3u8">
    </div>
    <button class="btn" name="add_episode" value="1">Add Episode</button>
  </form>
</div>

<br>

<div class="card">
  <h3>Series (latest 200)</h3>
  <table>
    <tr><th>ID</th><th>Name</th><th>Category</th><th>Adult</th><th>Episodes</th><th>Added</th><th>Actions</th></tr>
    <?php foreach($series as $s): ?>
      <tr>
        <td><?=$s['id']?></td>
        <td><?=e($s['name'])?></td>
        <td><?=e($s['cat_name'] ?: 'Uncategorized')?></td>
        <td><?= (int)$s['is_adult'] ? 'Yes' : 'No' ?></td>
        <td><?= (int)$s['eps'] ?></td>
        <td><?=e($s['created_at'])?></td>
        <td>
          <a class="btn" href="series_manager.php?edit_series=<?=$s['id']?>">Edit</a>
          <form method="post" style="margin:0; display:inline-block;" onsubmit="return confirm('Delete this series (and episodes)?');">
            <input type="hidden" name="series_id" value="<?=$s['id']?>">
            <button class="btn red" name="del_series" value="1">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<br>

<div class="card">
  <h3>Episodes (latest 200)</h3>
  <table>
    <tr><th>ID</th><th>Series</th><th>S/E</th><th>Title</th><th>Ext</th><th>Added</th><th>Actions</th></tr>
    <?php foreach($episodes as $e): ?>
      <tr>
        <td><?=$e['id']?></td>
        <td><?=e($e['series_name'])?></td>
        <td class="code">S<?=str_pad((string)$e['season_num'],2,'0',STR_PAD_LEFT)?>E<?=str_pad((string)$e['episode_num'],2,'0',STR_PAD_LEFT)?></td>
        <td><?=e($e['title'])?></td>
        <td class="code"><?=e($e['container_ext'] ?: '-')?></td>
        <td><?=e($e['created_at'])?></td>
        <td>
          <a class="btn" href="series_manager.php?edit_episode=<?=$e['id']?>">Edit</a>
          <form method="post" style="margin:0; display:inline-block;" onsubmit="return confirm('Delete this episode?');">
            <input type="hidden" name="episode_id" value="<?=$e['id']?>">
            <button class="btn red" name="del_episode" value="1">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
