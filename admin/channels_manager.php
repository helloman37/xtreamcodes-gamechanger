<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

// Ensure categories exist (for Uncategorized purge)
try { ensure_categories($pdo); } catch (Throwable $e) {}
$pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)")->execute(['Uncategorized']);
$uncat_id = (int)($pdo->query("SELECT id FROM categories WHERE name='Uncategorized' LIMIT 1")->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);


/* ---------------------------
   CREATE / UPDATE CHANNEL
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Delete ALL Uncategorized channels (bulk)
  if (isset($_POST['purge_uncat'])) {
    try {
      $pdo->beginTransaction();
      // Category-based uncategorized
      if ($uncat_id > 0) {
        $pdo->prepare("DELETE FROM channels WHERE category_id=?")->execute([$uncat_id]);
      }
      // Legacy uncategorized (no category_id + empty/Uncategorized group_title)
      $pdo->prepare("DELETE FROM channels WHERE (category_id IS NULL OR category_id=0) AND IFNULL(group_title,'') IN ('','Uncategorized')")->execute([]);
      $pdo->commit();
      flash_set("All Uncategorized channels deleted.", "success");
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set("Failed to delete Uncategorized channels.", "error");
    }
    header('Location: channels_manager.php?group=' . urlencode(''));
    exit;
  }

  $id = $_POST['id'] ?? null;

  $data = [
    'name' => trim($_POST['name'] ?? ''),
    'group_title' => trim($_POST['group_title'] ?? '') ?: null,
    'tvg_id' => trim($_POST['tvg_id'] ?? '') ?: null,
    'tvg_name' => trim($_POST['tvg_name'] ?? '') ?: null,
    'tvg_logo' => trim($_POST['tvg_logo'] ?? '') ?: null,
    'stream_url' => trim($_POST['stream_url'] ?? ''),
    'epg_url' => trim($_POST['epg_url'] ?? '') ?: null,
    'is_adult' => isset($_POST['is_adult']) ? 1 : 0,
    'direct_play' => isset($_POST['direct_play']) ? 1 : 0,
    'container_ext' => trim($_POST['container_ext'] ?? '') ?: null
  ];

  if ($data['name']==='' || $data['stream_url']==='') {
    flash_set("Name and Stream URL are required.", "error");
    header("Location: channels_manager.php"); exit;
  }

  if ($id) {
    $stmt = $pdo->prepare("UPDATE channels SET
      name=:name, group_title=:group_title, tvg_id=:tvg_id, tvg_name=:tvg_name,
      tvg_logo=:tvg_logo, stream_url=:stream_url, epg_url=:epg_url,
      is_adult=:is_adult,
      direct_play=:direct_play, container_ext=:container_ext
      WHERE id=:id");
    $data['id'] = (int)$id;
    $stmt->execute($data);
    flash_set("Channel updated", "success");
  } else {
    $stmt = $pdo->prepare("INSERT INTO channels
      (name,group_title,tvg_id,tvg_name,tvg_logo,stream_url,epg_url,is_adult,direct_play,container_ext)
      VALUES (:name,:group_title,:tvg_id,:tvg_name,:tvg_logo,:stream_url,:epg_url,:is_adult,:direct_play,:container_ext)");
    $stmt->execute($data);
    flash_set("Channel created", "success");
  }

  header("Location: channels_manager.php"); exit;
}

/* ---------------------------
   DELETE CHANNEL
---------------------------- */
if (isset($_GET['delete'])) {
  $pdo->prepare("DELETE FROM channels WHERE id=?")->execute([(int)$_GET['delete']]);
  flash_set("Channel deleted", "success");
  header("Location: channels_manager.php"); exit;
}

/* ---------------------------
   LOAD EDIT TARGET (optional)
---------------------------- */
$edit = null;
if (isset($_GET['edit'])) {
  $st = $pdo->prepare("SELECT * FROM channels WHERE id=?");
  $st->execute([(int)$_GET['edit']]);
  $edit = $st->fetch();
}

/* ---------------------------
   SEARCH / FILTER
---------------------------- */
$q = trim($_GET['q'] ?? '');
$group = trim($_GET['group'] ?? '');
$direct = $_GET['direct'] ?? '';

$where = [];
$params = [];

if ($q !== '') {
  $where[] = "(c.name LIKE ? OR c.tvg_name LIKE ? OR c.tvg_id LIKE ? OR c.group_title LIKE ?)";
  for ($i=0;$i<4;$i++) $params[] = "%$q%";
}
if ($group !== '') {
  $where[] = "IFNULL(c.group_title,'') = ?";
  $params[] = $group;
}
if ($direct !== '') {
  $where[] = "c.direct_play = ?";
  $params[] = (int)$direct;
}

$sql = "SELECT c.*, IFNULL(cat.sort_order, 999999) AS cat_sort, IFNULL(c.sort_order, c.id) AS ch_sort
        FROM channels c
        LEFT JOIN categories cat ON cat.id=c.category_id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY cat_sort, ch_sort, c.id";

$st = $pdo->prepare($sql);
$st->execute($params);
$channels = $st->fetchAll();

/* group list for dropdown */
$groups = $pdo->query("SELECT DISTINCT IFNULL(c.group_title,'') AS grp, IFNULL(cat.sort_order, 999999) AS s
  FROM channels c
  LEFT JOIN categories cat ON cat.id=c.category_id
  ORDER BY s, grp")->fetchAll();

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
  <title>Channels</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>Channels</h2>
  <?php if($q!==''): ?>
    <p class="muted">Showing results for <b><?=e($q)?></b> (<?=count($channels)?> found)</p>
  <?php endif; ?>

  <?php flash_show(); ?>

  <h3><?= $edit ? "Edit Channel #".(int)$edit['id'] : "Add Channel" ?></h3>
  <form method="post">
    <?php if($edit): ?><input type="hidden" name="id" value="<?=$edit['id']?>"><?php endif; ?>

    <div class="row">
      <div>
        <label>Name</label>
        <input name="name" value="<?=e($edit['name'] ?? '')?>" required>
      </div>
      <div>
        <label>Group</label>
        <input name="group_title" value="<?=e($edit['group_title'] ?? '')?>">
      </div>
    </div>

    <div class="row">
      <div>
        <label>TVG ID</label>
        <input name="tvg_id" value="<?=e($edit['tvg_id'] ?? '')?>">
      </div>
      <div>
        <label>TVG Name</label>
        <input name="tvg_name" value="<?=e($edit['tvg_name'] ?? '')?>">
      </div>
    </div>

    <label>Logo URL</label>
    <input name="tvg_logo" value="<?=e($edit['tvg_logo'] ?? '')?>">

    <label>Stream URL</label>
    <input name="stream_url" value="<?=e($edit['stream_url'] ?? '')?>" required>

    <label>EPG URL override (optional)</label>
    <input name="epg_url" value="<?=e($edit['epg_url'] ?? '')?>">

    
    <label style="margin-top:10px; display:block;">
      <input type="checkbox" name="is_adult" value="1" <?=(!empty($edit['is_adult'])?'checked':'')?> >
      Adult channel (mark as 18+ / hidden unless allowed)
    </label>
<label style="margin-top:10px;">
      <input type="checkbox" name="direct_play" value="1" <?=(!empty($edit['direct_play'])?'checked':'')?> >
      Direct play (bypass proxy / show source)
    </label>

    <label style="margin-top:10px;">Container Extension</label>
    <?php $ce = $edit['container_ext'] ?? ''; ?>
    <select name="container_ext">
      <option value="" <?=$ce===''?'selected':''?>>Auto-detect</option>
      <option value="m3u8" <?=$ce==='m3u8'?'selected':''?>>m3u8 (HLS)</option>
      <option value="ts" <?=$ce==='ts'?'selected':''?>>ts (Xtream TS)</option>
    </select>

    <div style="margin-top:10px;">
      <button type="submit"><?= $edit ? "Update" : "Create" ?></button>
      <?php if($edit): ?>
        <a href="channels_manager.php" style="margin-left:10px;">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<br>

<div class="card">
  <h3>Bulk Delete</h3>
  <p class="muted">These actions are permanent. Use with caution.</p>
  <form method="post" action="bulk_delete.php" onsubmit="return confirm('This will permanently delete data. Continue?')">
    <div class="row">
      <div><button name="action" value="delete_channels" type="submit">Delete ALL Channels</button></div>
      <div><button name="action" value="delete_categories" type="submit">Clear ALL Categories</button></div>
      <div><button name="action" value="delete_series" type="submit">Delete ALL Series</button></div>
      <div><button name="action" value="delete_movies" type="submit">Delete ALL Movies</button></div>
      <div><button name="action" value="delete_all" type="submit">Delete ALL</button></div>
    </div>
  </form>
</div>

<br>

<div class="card">
  <h3>Search / Filter Channels</h3>
  <form method="get" class="row">
    <div>
      <label>Search (name / tvg / group / id)</label>
      <input name="q" value="<?=e($q)?>" placeholder="type part of a channel name…">
    </div>
    <div>
      <label>Group</label>
      <select name="group">
        <option value="">All groups</option>
        <?php foreach($groups as $g): $val=$g['grp']; ?>
          <option value="<?=e($val)?>" <?=$val===$group?'selected':''?>><?= $val===''?'Uncategorized':e($val) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Direct Play</label>
      <select name="direct">
        <option value="" <?=$direct===''?'selected':''?>>All</option>
        <option value="0" <?=$direct==='0'?'selected':''?>>Proxy (hidden)</option>
        <option value="1" <?=$direct==='1'?'selected':''?>>Direct</option>
      </select>
    </div>
    <div style="align-self:flex-end;">
      <button type="submit">Apply</button>
      <a href="channels_manager.php" style="margin-left:10px;">Reset</a>
    </div>
  </form>

  <p class="muted" style="margin-top:8px;">
    Showing <?=count($channels)?> channel(s)
    <?php if($q!==''||$group!==''||$direct!==''): ?> (filtered)<?php endif; ?>
  </p>
</div>

<br>

<div class="card">
  <form method="get" style="margin-bottom:10px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
    <div style="flex:1;min-width:220px;">
      <label>Search channels</label>
      <input name="q" value="<?=e($q)?>" placeholder="name, group, tvg-id...">
    </div>
    <div>
      <button type="submit">Search</button>
      <?php if($q!==''): ?>
        <a href="channels_manager.php" style="margin-left:8px;" class="muted">Clear</a>
      <?php endif; ?>
    </div>
  </form>
  <table>
    <tr>
      <th>ID</th><th>Name</th><th>Group</th><th>Direct?</th><th>Ext</th><th>Works?</th>
      <th>Code</th><th>Last Checked</th><th>Actions</th>
    </tr>
    <?php foreach($channels as $c): ?>
      <tr>
        <td><?=$c['id']?></td>
        <td><?=e($c['name'])?></td>
        <td><?=e($c['group_title'] ?: 'Uncategorized')?></td>
        <td><?=$c['direct_play']?'<span class="pill good">YES</span>':'<span class="pill bad">NO</span>'?></td>
        <td><?=e($c['container_ext'] ?: 'auto')?></td>
        <td><?=$c['works']?'<span class="pill good">OK</span>':'<span class="pill bad">DEAD</span>'?></td>
        <td><?=e($c['last_status_code'])?></td>
        <td><?=e($c['last_checked_at'])?></td>
        <td style="white-space:nowrap;">
          <a href="channels_manager.php?edit=<?=$c['id']?>">Edit</a> |
          <a href="channels_manager.php?delete=<?=$c['id']?>" onclick="return confirm('Delete channel?')">Delete</a>
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