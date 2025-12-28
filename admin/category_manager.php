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

// Best-effort: keep Uncategorized pinned at the top unless the admin explicitly changes it.
try {
  if ($uncat_id > 0) {
    $pdo->prepare("UPDATE categories SET sort_order=0 WHERE id=? AND (sort_order IS NULL OR sort_order='')")->execute([$uncat_id]);
  }
} catch (Throwable $e) {}

/**
 * Normalize category sort_order values to a spaced sequence (10,20,30...),
 * preserving current ORDER BY (sort_order,id) ordering. This makes move up/down
 * operations always have visible effect even when many sort_order values are equal.
 */
function normalize_cat_order(PDO $pdo): void {
  $rows = $pdo->query("SELECT id, IFNULL(sort_order,0) AS sort_order, name FROM categories ORDER BY IFNULL(sort_order,0), id")
    ->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return;

  $pdo->beginTransaction();
  try {
    $i = 0;
    $st = $pdo->prepare("UPDATE categories SET sort_order=? WHERE id=?");
    foreach ($rows as $r) {
      $id = (int)$r['id'];
      $name = (string)($r['name'] ?? '');
      // Keep Uncategorized at 0 to avoid it bouncing around.
      if (strcasecmp($name, 'Uncategorized') === 0) {
        $st->execute([0, $id]);
        continue;
      }
      $i++;
      $st->execute([$i * 10, $id]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
}

/** Swap sort_order between two categories (transactional). */
function swap_cat_order(PDO $pdo, int $a, int $b): void {
  if ($a <= 0 || $b <= 0 || $a === $b) return;
  $pdo->beginTransaction();
  try {
    $ra = $pdo->query("SELECT IFNULL(sort_order,0) AS so FROM categories WHERE id=".(int)$a)->fetch(PDO::FETCH_ASSOC);
    $rb = $pdo->query("SELECT IFNULL(sort_order,0) AS so FROM categories WHERE id=".(int)$b)->fetch(PDO::FETCH_ASSOC);
    $soA = (int)($ra['so'] ?? 0);
    $soB = (int)($rb['so'] ?? 0);

    $st = $pdo->prepare("UPDATE categories SET sort_order=? WHERE id=?");
    $st->execute([$soB, $a]);
    $st->execute([$soA, $b]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
}

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
  // Normalize category order (spaced 10,20,30...) without changing visible ordering.
  if (isset($_POST['rebuild_cat_order'])) {
    try { normalize_cat_order($pdo); } catch (Throwable $e) {}
    flash_set("Category order rebuilt.", "success");
    header('Location: category_manager.php' . (!empty($_POST['category_id']) ? ('?category_id='.(int)$_POST['category_id']) : ''));
    exit;
  }

  // Move category up/down
  if (isset($_POST['move_cat'])) {
    $cid = (int)($_POST['category_id'] ?? 0);
    $dir = (string)($_POST['dir'] ?? '');

    if ($cid > 0 && $cid !== $uncat_id) {
      try {
        $ids = $pdo->query("SELECT id,name,IFNULL(sort_order,0) AS so FROM categories ORDER BY IFNULL(sort_order,0), id")
          ->fetchAll(PDO::FETCH_ASSOC);
        $ordered = [];
        foreach ($ids as $r) $ordered[] = ['id'=>(int)$r['id'], 'name'=>(string)($r['name'] ?? ''), 'so'=>(int)($r['so'] ?? 0)];

        $idx = -1;
        for ($i=0; $i<count($ordered); $i++) {
          if ($ordered[$i]['id'] === $cid) { $idx = $i; break; }
        }

        if ($idx >= 0) {
          $neighbor = null;
          if ($dir === 'up' && $idx > 0) {
            // Don't allow anything above Uncategorized.
            if (strcasecmp($ordered[$idx-1]['name'], 'Uncategorized') !== 0) {
              $neighbor = $ordered[$idx-1];
            }
          } elseif ($dir === 'down' && $idx < count($ordered)-1) {
            $neighbor = $ordered[$idx+1];
          }

          if ($neighbor) {
            // If sort_order ties would make the swap invisible, normalize first.
            if ((int)$ordered[$idx]['so'] === (int)$neighbor['so']) {
              normalize_cat_order($pdo);
            }
            swap_cat_order($pdo, $ordered[$idx]['id'], (int)$neighbor['id']);
          }
        }
      } catch (Throwable $e) {}
    }
    header('Location: category_manager.php?category_id=' . ($cid ?: $uncat_id));
    exit;
  }

  // Add category
  if (isset($_POST['add_cat'])) {
    $name = preg_replace('/\s+/', ' ', trim((string)($_POST['name'] ?? '')));
    if ($name !== '') {
      $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)")->execute([$name]);
      flash_set("Category added.", "success");
    }
    header('Location: category_manager.php');
    exit;
  }

  // Update category (name + adult flag)
  if (isset($_POST['save_cat']) || isset($_POST['rename_cat'])) {
    $cid = (int)($_POST['category_id'] ?? 0);
    $name = preg_replace('/\s+/', ' ', trim((string)($_POST['new_name'] ?? '')));
    $is_adult = isset($_POST['cat_is_adult']) ? 1 : 0;
    $sort_order = isset($_POST['sort_order']) ? (int)($_POST['sort_order'] ?? 0) : null;

    $redirect_cid = $cid;

    if ($cid > 0) {
      // Update adult flag for any category (including Uncategorized)
      try {
        if ($sort_order !== null) {
          $pdo->prepare("UPDATE categories SET is_adult=?, sort_order=? WHERE id=?")->execute([$is_adult, $sort_order, $cid]);
        } else {
          $pdo->prepare("UPDATE categories SET is_adult=? WHERE id=?")->execute([$is_adult, $cid]);
        }
      } catch (Throwable $e) {}

      // Rename/merge is allowed for any category except the reserved Uncategorized bucket
      if ($name !== '' && $cid !== $uncat_id) {
        try {
          $st = $pdo->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
          $st->execute([$name]);
          $existing_id = (int)($st->fetchColumn() ?: 0);

          if ($existing_id > 0 && $existing_id !== $cid) {
            // Merge into the existing category name (handles UNIQUE constraint cleanly)
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE channels SET category_id=?, group_title=? WHERE category_id=?")
                ->execute([$existing_id, $name, $cid]);

            // If admin marked this category adult, keep the merged target as adult too.
            if ($is_adult) {
              $pdo->prepare("UPDATE categories SET is_adult=1 WHERE id=?")->execute([$existing_id]);
            }

            $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$cid]);

            $pdo->commit();
            $redirect_cid = $existing_id;
            flash_set("Category merged.", "success");
          } else {
            $pdo->prepare("UPDATE categories SET name=? WHERE id=?")->execute([$name, $cid]);
            // Keep channels.group_title aligned with category name for M3U group-title output.
            $pdo->prepare("UPDATE channels SET group_title=? WHERE category_id=?")->execute([$name, $cid]);
            flash_set("Category updated.", "success");
          }
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          flash_set("Category name already exists (or rename failed).", "error");
        }
      } else {
        flash_set("Category updated.", "success");
      }
    }

    header('Location: category_manager.php?category_id=' . $redirect_cid);
    exit;
  }

  // Delete ALL channels in Uncategorized
  if (isset($_POST['purge_uncat'])) {
    $cid = (int)($_POST['category_id'] ?? 0);
    if ($cid <= 0) $cid = $uncat_id;

    if ($cid === $uncat_id) {
      try {
        $pdo->beginTransaction();

        // Delete channels explicitly assigned to Uncategorized
        $pdo->prepare("DELETE FROM channels WHERE category_id=?")->execute([$uncat_id]);

        // Safety: delete legacy channels that never got a category_id but still match Uncategorized
        $pdo->prepare("DELETE FROM channels WHERE (category_id IS NULL OR category_id=0) AND IFNULL(group_title,'Uncategorized')='Uncategorized'")->execute([]);

        $pdo->commit();
        flash_set("All Uncategorized channels deleted.", "success");
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set("Failed to delete Uncategorized channels.", "error");
      }
    }
    header('Location: category_manager.php?category_id=' . $uncat_id);
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
$cats = $pdo->query("SELECT c.id,c.name,IFNULL(c.sort_order,0) AS sort_order,IFNULL(c.is_adult,0) AS is_adult,(SELECT COUNT(*) FROM channels ch WHERE ch.category_id=c.id) AS cnt FROM categories c ORDER BY IFNULL(c.sort_order,0), c.id")
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

$params = [];
if ($selected === $uncat_id) {
  $where = "WHERE (ch.category_id=? OR ((ch.category_id IS NULL OR ch.category_id=0) AND COALESCE(NULLIF(TRIM(ch.group_title),''),'Uncategorized')='Uncategorized'))";
  $params[] = $uncat_id;
} else {
  $where = "WHERE ch.category_id=?";
  $params[] = $selected;
}
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

<div class="grid12">
  <!-- Categories column -->
<div class="card">
  <h3>Categories</h3>
  <form method="post" class="row" style="gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="text" name="name" placeholder="New category name" required style="max-width:360px;">
    <button class="btn" name="add_cat" value="1">Add</button>
    <button class="btn gray" name="rebuild_cat_order" value="1" title="Rebuild numeric sort_order (10,20,30...) without changing the current visible order">Rebuild Order</button>
  </form>

  <div class="cat-list">
    <?php foreach($cats as $c): ?>
      <?php $isSelected = ((int)$c['id'] === (int)$selected); ?>
      <div class="cat-item <?= $isSelected ? 'active' : '' ?>">
        <div class="cat-head">
          <div class="cat-title">
            <a href="category_manager.php?category_id=<?=$c['id']?>" class="cat-link">
              <?=e($c['name'])?>
            </a>
            <?php if(!empty($c['is_adult'])): ?>
              <span class="pill bad" style="margin-left:6px;">Adult</span>
            <?php endif; ?>
          </div>
          <span class="muted"><?=$c['cnt']?> channel(s)</span>
        </div>

        <div class="cat-controls">
          <form method="post" class="cat-form" style="gap:6px;">
            <input type="hidden" name="category_id" value="<?=$c['id']?>">
            <input type="hidden" name="move_cat" value="1">
            <button class="btn gray btn-small" name="dir" value="up" <?= ((int)$c['id'] === $uncat_id) ? 'disabled' : '' ?> title="Move up">▲</button>
            <button class="btn gray btn-small" name="dir" value="down" <?= ((int)$c['id'] === $uncat_id) ? 'disabled' : '' ?> title="Move down">▼</button>
          </form>

          <form method="post" class="cat-form">
            <input type="hidden" name="category_id" value="<?=$c['id']?>">
            <input type="text" name="new_name" value="<?=e($c['name'])?>" placeholder="Rename"
              <?= ((int)$c['id'] === $uncat_id) ? 'disabled' : '' ?> >
            <input type="number" name="sort_order" value="<?= (int)($c['sort_order'] ?? 0) ?>" title="Sort order (lower shows first)" style="width:92px;" <?= ((int)$c['id'] === $uncat_id) ? 'readonly' : '' ?> >
            <label class="cat-adult" title="Mark category as Adult">
              <input type="checkbox" name="cat_is_adult" value="1" <?= !empty($c['is_adult']) ? 'checked' : '' ?>> Adult
            </label>
            <button class="btn gray btn-small" name="save_cat" value="1">Save</button>
          </form>

          <?php if((int)$c['id'] === $uncat_id): ?>
            <form method="post" class="cat-form" onsubmit="return confirm('Delete ALL Uncategorized channels?');">
              <input type="hidden" name="category_id" value="<?=$c['id']?>">
              <button class="btn danger btn-small" name="purge_uncat" value="1">Purge Channels</button>
            </form>
          <?php else: ?>
            <form method="post" class="cat-form" onsubmit="return confirm('Delete this category? All channels in this category will be deleted too.');">
              <input type="hidden" name="category_id" value="<?=$c['id']?>">
              <button class="btn danger btn-small" name="del_cat" value="1">Delete</button>
            </form>
          <?php endif; ?>
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
<?php if($selected === $uncat_id): ?>
  <form method="post" style="margin:8px 0 12px;" onsubmit="return confirm('Delete ALL Uncategorized channels?');">
    <input type="hidden" name="category_id" value="<?=$uncat_id?>">
    <button class="btn danger btn-small" name="purge_uncat" value="1">Delete ALL Uncategorized Channels</button>
  </form>
<?php endif; ?>

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