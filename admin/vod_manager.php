<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['add_cat'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
      $st = $pdo->prepare("INSERT IGNORE INTO vod_categories (name) VALUES (?)");
      $st->execute([$name]);
      flash_set("Category added.", "success");
    }
    header("Location: vod_manager.php"); exit;
  }
  if (isset($_POST['del_cat'])) {
    $cid = (int)($_POST['category_id'] ?? 0);
    if ($cid>0) {
      $pdo->prepare("UPDATE movies SET category_id=NULL WHERE category_id=?")->execute([$cid]);
      $pdo->prepare("DELETE FROM vod_categories WHERE id=?")->execute([$cid]);
      flash_set("Category deleted.", "success");
    }
    header("Location: vod_manager.php"); exit;
  }
  if (isset($_POST['add_movie'])) {
    $name = trim($_POST['movie_name'] ?? '');
    $url  = trim($_POST['stream_url'] ?? '');
    $cid  = (int)($_POST['category_id'] ?? 0);
    $poster = trim($_POST['poster_url'] ?? '');
    $adult = !empty($_POST['is_adult']) ? 1 : 0;
    $ext = trim($_POST['container_ext'] ?? '');
    if ($name !== '' && $url !== '') {
      $st = $pdo->prepare("INSERT INTO movies (category_id, name, stream_url, poster_url, is_adult, container_ext) VALUES (?,?,?,?,?,?)");
      $st->execute([$cid?:null, $name, $url, $poster?:null, $adult, $ext?:null]);
      flash_set("Movie added.", "success");
    } else {
      flash_set("Name + URL required.", "error");
    }
    header("Location: vod_manager.php"); exit;
  }

  if (isset($_POST['update_movie'])) {
    $mid = (int)($_POST['movie_id'] ?? 0);
    $name = trim($_POST['movie_name'] ?? '');
    $url  = trim($_POST['stream_url'] ?? '');
    $cid  = (int)($_POST['category_id'] ?? 0);
    $poster = trim($_POST['poster_url'] ?? '');
    $adult = !empty($_POST['is_adult']) ? 1 : 0;
    $ext = trim($_POST['container_ext'] ?? '');
    $return_cid = (int)($_POST['return_category_id'] ?? 0);
    if ($mid>0 && $name !== '' && $url !== '') {
      $st = $pdo->prepare("UPDATE movies SET category_id=?, name=?, stream_url=?, poster_url=?, is_adult=?, container_ext=? WHERE id=?");
      $st->execute([$cid?:null, $name, $url, $poster?:null, $adult, $ext?:null, $mid]);
      flash_set("Movie updated.", "success");
    } else {
      flash_set("Name + URL required.", "error");
    }
    $redir = "vod_manager.php";
    if ($return_cid>0) { $redir .= "?category_id=".$return_cid; }
    header("Location: ".$redir); exit;
  }
  if (isset($_POST['del_movie'])) {
    $mid = (int)($_POST['movie_id'] ?? 0);
    if ($mid>0) {
      $pdo->prepare("DELETE FROM movies WHERE id=?")->execute([$mid]);
      flash_set("Movie deleted.", "success");
    }
    header("Location: vod_manager.php"); exit;
  }
}

$cats = $pdo->query("SELECT c.id,c.name, (SELECT COUNT(*) FROM movies m WHERE m.category_id=c.id) AS cnt FROM vod_categories c ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);

$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_movie = null;
if ($edit_id>0) {
  $st = $pdo->prepare("SELECT id,category_id,name,stream_url,poster_url,container_ext,is_adult FROM movies WHERE id=?");
  $st->execute([$edit_id]);
  $edit_movie = $st->fetch(PDO::FETCH_ASSOC);
  if (!$edit_movie) { $edit_id = 0; }
}

$filter = (int)($_GET['category_id'] ?? 0);
$params = [];
$where = "";
if ($filter>0) { $where = "WHERE m.category_id=?"; $params[] = $filter; }
$st = $pdo->prepare("
  SELECT m.id,m.name,m.stream_url,m.poster_url,m.container_ext,m.is_adult, c.name AS cat_name, m.created_at
  FROM movies m
  LEFT JOIN vod_categories c ON c.id=m.category_id
  $where
  ORDER BY m.id DESC
  LIMIT 300
");
$st->execute($params);
$movies = $st->fetchAll(PDO::FETCH_ASSOC);

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
  <title>VOD Manager</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>
<div class="card">
  <h2>VOD Manager</h2>
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
        <a href="vod_manager.php?category_id=<?=$c['id']?>" class="link"><?=e($c['name'])?></a>
        <span class="muted"><?=$c['cnt']?> movies</span>
        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this category? Movies will be uncategorized.');">
          <input type="hidden" name="category_id" value="<?=$c['id']?>">
          <button class="btn red" name="del_cat" value="1">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<br>


<?php if (!empty($edit_movie)): ?>
<div class="card">
  <h3>Edit Movie (ID <?=$edit_movie['id']?>)</h3>
  <form method="post">
    <input type="hidden" name="movie_id" value="<?=$edit_movie['id']?>">
    <input type="hidden" name="return_category_id" value="<?=$filter?>">
    <div class="row">
      <label>Name</label>
      <input name="movie_name" value="<?=e($edit_movie['name'])?>" required>
    </div>
    <div class="row">
      <label>Stream URL</label>
      <input name="stream_url" value="<?=e($edit_movie['stream_url'])?>" required>
    </div>
    <div class="row">
      <label>Poster URL</label>
      <input name="poster_url" value="<?=e($edit_movie['poster_url'] ?? '')?>">
    </div>
    <div class="row">
      <label>Category</label>
      <select name="category_id">
        <option value="0" <?= (int)($edit_movie['category_id'] ?? 0)===0 ? 'selected' : '' ?>>Uncategorized</option>
        <?php foreach($cats as $c): ?>
          <option value="<?=$c['id']?>" <?= (int)($edit_movie['category_id'] ?? 0)===(int)$c['id'] ? 'selected' : '' ?>><?=e($c['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <label>Container Ext</label>
      <input name="container_ext" value="<?=e($edit_movie['container_ext'] ?? '')?>" placeholder="mp4 / mkv / m3u8">
    </div>
    <div class="row">
      <label>Adult</label>
      <input type="checkbox" name="is_adult" value="1" <?= (int)($edit_movie['is_adult'] ?? 0) ? 'checked' : '' ?>>
    </div>
    <button class="btn" name="update_movie" value="1">Save Changes</button>
    <a class="btn gray" href="vod_manager.php<?= $filter>0 ? '?category_id='.$filter : '' ?>">Cancel</a>
  </form>
</div>

<br>
<?php endif; ?>

<div class="card">
  <h3>Add Movie</h3>
  <form method="post">
    <div class="row">
      <label>Name</label>
      <input name="movie_name" required>
    </div>
    <div class="row">
      <label>Stream URL</label>
      <input name="stream_url" placeholder="http(s)://... or HLS .m3u8" required>
    </div>
    <div class="row">
      <label>Poster URL</label>
      <input name="poster_url" placeholder="http(s)://...">
    </div>
    <div class="row">
      <label>Category</label>
      <select name="category_id">
        <option value="0">Uncategorized</option>
        <?php foreach($cats as $c): ?>
          <option value="<?=$c['id']?>" <?= $filter===(int)$c['id']?'selected':'' ?>><?=e($c['name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <label>Container Ext</label>
      <input name="container_ext" placeholder="mp4 / mkv / m3u8">
    </div>
    <div class="row">
      <label>Adult</label>
      <input type="checkbox" name="is_adult" value="1">
    </div>
    <button class="btn" name="add_movie" value="1">Add Movie</button>
  </form>
</div>

<br>

<div class="card">
  <h3>Movies (latest 300)</h3>
  <div style="margin-bottom:10px;">
    <a class="btn gray" href="vod_manager.php">All</a>
    <?php foreach($cats as $c): ?>
      <a class="btn gray" href="vod_manager.php?category_id=<?=$c['id']?>"><?=e($c['name'])?></a>
    <?php endforeach; ?>
  </div>

  <table>
    <tr><th>ID</th><th>Name</th><th>Category</th><th>Ext</th><th>Adult</th><th>Added</th><th>Actions</th></tr>
    <?php foreach($movies as $m): ?>
      <tr>
        <td><?=$m['id']?></td>
        <td><?=e($m['name'])?></td>
        <td><?=e($m['cat_name'] ?: 'Uncategorized')?></td>
        <td class="code"><?=e($m['container_ext'] ?: '-')?></td>
        <td><?= (int)$m['is_adult'] ? 'Yes' : 'No' ?></td>
        <td><?=e($m['created_at'])?></td>
        <td>
          <a class="btn" href="vod_manager.php?edit_id=<?=$m['id']?><?= $filter>0 ? '&category_id='.$filter : '' ?>">Edit</a>
          <form method="post" style="margin:0; display:inline-block;" onsubmit="return confirm('Delete this movie?');">
            <input type="hidden" name="movie_id" value="<?=$m['id']?>">
            <button class="btn red" name="del_movie" value="1">Delete</button>
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
