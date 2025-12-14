<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

require_admin();
$pdo = db();

// Ensure categories exist and channels.category_id is backfilled (best-effort).
try { ensure_categories($pdo); } catch (Throwable $e) {}

// Always keep an "Uncategorized" bucket.
$pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)")->execute(['Uncategorized']);
$uncat_id = (int)($pdo->query("SELECT id FROM categories WHERE name='Uncategorized' LIMIT 1")->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);

function cat_name(PDO $pdo, int $cid): ?string {
  $st = $pdo->prepare("SELECT name FROM categories WHERE id=?");
  $st->execute([$cid]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r['name'] ?? null;
}

/* ---------------------------
   POST actions
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Add category
  if (isset($_POST['add_cat'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
      $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)")->execute([$name]);
      flash_set("Category added.", "success");
    }
    header('Location: category_manager.php');
    exit;
  }

  // Rename category
  if (isset($_POST['rename_cat'])) {
    $cid = (int)($_POST['category_id'] ?? 0);
    $name = trim($_POST['new_name'] ?? '');
    if ($cid > 0 && $name !== '' && $cid !== $uncat_id) {
      $pdo->prepare("UPDATE categories SET name=? WHERE id=?")->execute([$name, $cid]);
      // Keep channels.group_title aligned with category name for M3U group-title output.
      $pdo->prepare("UPDATE channels SET group_title=? WHERE category_id=?")->execute([$name, $cid]);
      flash_set("Category renamed.", "success");
    }
    header('Location: category_manager.php?category_id=' . $cid);
    exit;
  }

  // Delete category (and all channels inside it)
  if (isset($_POST['del_cat'])) {
    $cid = (int)($_POST['category_id'] ?? 0);
    if ($cid > 0 && $cid !== $uncat_id) {
      $cname = cat_name($pdo, $cid) ?: '';
      try {
        $pdo->beginTransaction();

        // Delete all channels in this category
        $pdo->prepare("DELETE FROM channels WHERE category_id=?")->execute([$cid]);

        // Safety: delete legacy channels that never got a category_id but still match this group_title
        if ($cname !== '') {
          $pdo->prepare("DELETE FROM channels WHERE category_id IS NULL AND IFNULL(group_title,'Uncategorized')=?")->execute([$cname]);
        }

        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$cid]);

        $pdo->commit();
        flash_set("Category deleted (and all channels in it).", "success");
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set("Failed to delete category.", "error");
      }
    }
    header('Location: category_manager.php?category_id=' . $uncat_id);
    exit;
  }

  // Create / update channel
  if (isset($_POST['save_channel'])) {
    $id = (int)($_POST['channel_id'] ?? 0);
    $cid = (int)($_POST['category_id'] ?? 0);
    if ($cid <= 0) $cid = $uncat_id;
    $cname = cat_name($pdo, $cid) ?: 'Uncategorized';

    $data = [
      'name' => trim($_POST['name'] ?? ''),
      'tvg_id' => trim($_POST['tvg_id'] ?? '') ?: null,
      'tvg_name' => trim($_POST['tvg_name'] ?? '') ?: null,
      'tvg_logo' => trim($_POST['tvg_logo'] ?? '') ?: null,
      'stream_url' => trim($_POST['stream_url'] ?? ''),
      'epg_url' => trim($_POST['epg_url'] ?? '') ?: null,
      'is_adult' => !empty($_POST['is_adult']) ? 1 : 0,
      'direct_play' => !empty($_POST['direct_play']) ? 1 : 0,
      'container_ext' => trim($_POST['container_ext'] ?? '') ?: null,
      'category_id' => $cid ?: null,
      'group_title' => $cname,
    ];

    if ($data['name'] === '' || $data['stream_url'] === '') {
      flash_set('Name and Stream URL are required.', 'error');
      header('Location: category_manager.php?category_id=' . $cid);
      exit;
    }

    if ($id > 0) {
      $st = $pdo->prepare("UPDATE channels SET
        name=:name,
        category_id=:category_id,
        group_title=:group_title,
        tvg_id=:tvg_id,
        tvg_name=:tvg_name,
        tvg_logo=:tvg_logo,
        stream_url=:stream_url,
        epg_url=:epg_url,
        is_adult=:is_adult,
        direct_play=:direct_play,
        container_ext=:container_ext
        WHERE id=:id");
      $data['id'] = $id;
      $st->execute($data);
      flash_set('Channel updated.', 'success');
    } else {
      $st = $pdo->prepare("INSERT INTO channels
        (name,category_id,group_title,tvg_id,tvg_name,tvg_logo,stream_url,epg_url,is_adult,direct_play,container_ext)
        VALUES (:name,:category_id,:group_title,:tvg_id,:tvg_name,:tvg_logo,:stream_url,:epg_url,:is_adult,:direct_play,:container_ext)");
      $st->execute($data);
      flash_set('Channel created.', 'success');
    }

    header('Location: category_manager.php?category_id=' . $cid);
    exit;
  }

  // Delete channel
  if (isset($_POST['del_channel'])) {
    $cid = (int)($_POST['category_id'] ?? $uncat_id);
    $id  = (int)($_POST['channel_id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("DELETE FROM channels WHERE id=?")->execute([$id]);
      flash_set('Channel deleted.', 'success');
    }
    header('Location: category_manager.php?category_id=' . $cid);
    exit;
  }
}

/* ---------------------------
   Data load
---------------------------- */
$cats = $pdo->query("SELECT c.id,c.name,(SELECT COUNT(*) FROM channels ch WHERE ch.category_id=c.id) AS cnt FROM categories c ORDER BY c.sort_order, c.id")
  ->fetchAll(PDO::FETCH_ASSOC);

$selected = (int)($_GET['category_id'] ?? 0);
if ($selected <= 0) $selected = $uncat_id;

$q = trim($_GET['q'] ?? '');

// Edit target (optional)
$edit = null;
if (isset($_GET['edit_channel'])) {
  $st = $pdo->prepare("SELECT * FROM channels WHERE id=?");
  $st->execute([(int)$_GET['edit_channel']]);
  $edit = $st->fetch(PDO::FETCH_ASSOC);
  if ($edit && !empty($edit['category_id'])) $selected = (int)$edit['category_id'];
}

$params = [$selected];
$where = "WHERE ch.category_id=?";
if ($q !== '') {
  $where .= " AND (ch.name LIKE ? OR ch.tvg_name LIKE ? OR ch.tvg_id LIKE ?)";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
}

$st = $pdo->prepare("SELECT ch.* FROM channels ch $where ORDER BY IFNULL(ch.sort_order, ch.id), ch.id LIMIT 500");
$st->execute($params);
$channels = $st->fetchAll(PDO::FETCH_ASSOC);

$topbar = file_get_contents(__DIR__ . '/topbar.html');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Category / Channel Manager</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>Category / Channel Manager</h2>
  <?php flash_show(); ?>
  <p class="muted">Manage channel categories and quickly edit channels inside the selected category.</p>
</div>

<br>

<div class="row" style="align-items:flex-start; gap:18px;">
  <!-- Categories column -->
  <div class="card" style="flex:1; min-width:280px;">
    <h3>Categories</h3>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <input type="text" name="name" placeholder="New category name" required>
      <button class="btn" name="add_cat" value="1">Add</button>
    </form>

    <div style="margin-top:10px;">
      <?php foreach($cats as $c): ?>
        <?php $isSelected = ((int)$c['id'] === (int)$selected); ?>
        <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #1f2a44;">
          <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
            <a href="category_manager.php?category_id=<?=$c['id']?>" class="link" style="font-weight:700;<?= $isSelected ? 'text-decoration:underline;' : '' ?>">
              <?=e($c['name'])?>
            </a>
            <span class="muted"><?=$c['cnt']?> channel(s)</span>
          </div>

          <div style="display:flex; gap:8px; align-items:center;">
            <form method="post" style="margin:0; display:flex; gap:6px; align-items:center;">
              <input type="hidden" name="category_id" value="<?=$c['id']?>">
              <input type="text" name="new_name" value="<?=e($c['name'])?>" style="width:140px;" <?= ((int)$c['id'] === $uncat_id) ? 'disabled' : '' ?> >
              <button class="btn gray btn-small" name="rename_cat" value="1" <?= ((int)$c['id'] === $uncat_id) ? 'disabled' : '' ?>>Rename</button>
            </form>
            <form method="post" style="margin:0;" onsubmit="return confirm('Delete this category? All channels in this category will be deleted too.');">
              <input type="hidden" name="category_id" value="<?=$c['id']?>">
              <button class="btn danger btn-small" name="del_cat" value="1" <?= ((int)$c['id'] === $uncat_id) ? 'disabled' : '' ?>>Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Channels column -->
  <div style="flex:2; min-width:520px;">
    <div class="card">
      <h3><?= $edit ? 'Edit Channel #' . (int)$edit['id'] : 'Add Channel' ?></h3>
      <form method="post">
        <input type="hidden" name="channel_id" value="<?= (int)($edit['id'] ?? 0) ?>">

        <div class="row">
          <div>
            <label>Name</label>
            <input name="name" value="<?=e($edit['name'] ?? '')?>" required>
          </div>
          <div>
            <label>Category</label>
            <select name="category_id">
              <?php foreach($cats as $c): ?>
                <?php $cid = (int)$c['id']; ?>
                <option value="<?=$cid?>" <?= (int)($edit['category_id'] ?? $selected) === $cid ? 'selected' : '' ?>><?=e($c['name'])?></option>
              <?php endforeach; ?>
            </select>
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

        <div class="row" style="margin-top:10px;">
          <div>
            <label>Direct Play</label>
            <input type="checkbox" name="direct_play" value="1" <?= !empty($edit['direct_play']) ? 'checked' : '' ?>>
          </div>
          <div>
            <label>Adult</label>
            <input type="checkbox" name="is_adult" value="1" <?= !empty($edit['is_adult']) ? 'checked' : '' ?>>
          </div>
          <div>
            <label>Container Extension</label>
            <?php $ce = $edit['container_ext'] ?? ''; ?>
            <select name="container_ext">
              <option value="" <?=$ce===''?'selected':''?>>Auto-detect</option>
              <option value="m3u8" <?=$ce==='m3u8'?'selected':''?>>m3u8 (HLS)</option>
              <option value="ts" <?=$ce==='ts'?'selected':''?>>ts (Xtream TS)</option>
            </select>
          </div>
        </div>

        <div style="margin-top:12px;">
          <button class="btn" name="save_channel" value="1"><?= $edit ? 'Update' : 'Create' ?></button>
          <?php if($edit): ?>
            <a href="category_manager.php?category_id=<?=$selected?>" style="margin-left:10px;">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <br>

    <div class="card">
      <h3>Channels in “<?= e(cat_name($pdo, $selected) ?: 'Uncategorized') ?>”</h3>
      <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:10px;">
        <input type="hidden" name="category_id" value="<?=$selected?>">
        <div style="flex:1;min-width:220px;">
          <label>Search</label>
          <input name="q" value="<?=e($q)?>" placeholder="name, tvg-id, tvg-name...">
        </div>
        <div>
          <button class="btn" type="submit">Search</button>
          <?php if($q !== ''): ?>
            <a class="muted" href="category_manager.php?category_id=<?=$selected?>" style="margin-left:10px;">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <p class="muted">Showing <?=count($channels)?> channel(s) (max 500)</p>

      <table>
        <tr>
          <th>ID</th><th>Name</th><th>Direct?</th><th>Ext</th><th>Works?</th><th>Last Checked</th><th>Actions</th>
        </tr>
        <?php foreach($channels as $ch): ?>
          <tr>
            <td><?=$ch['id']?></td>
            <td><?=e($ch['name'])?></td>
            <td><?=$ch['direct_play']?'<span class="pill good">YES</span>':'<span class="pill bad">NO</span>'?></td>
            <td class="code"><?=e($ch['container_ext'] ?: 'auto')?></td>
            <td><?=$ch['works']?'<span class="pill good">OK</span>':'<span class="pill bad">DEAD</span>'?></td>
            <td><?=e($ch['last_checked_at'])?></td>
            <td style="white-space:nowrap;">
              <a href="category_manager.php?edit_channel=<?=$ch['id']?>">Edit</a>
              |
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this channel?');">
                <input type="hidden" name="channel_id" value="<?=$ch['id']?>">
                <input type="hidden" name="category_id" value="<?=$selected?>">
                <button class="btn danger btn-small" name="del_channel" value="1">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
