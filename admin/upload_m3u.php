<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

// Defaults for form rendering.
$default_direct = 0;
$default_ext    = '';
$default_adult  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Support single or multi-upload.
  if (empty($_FILES['m3u'])) {
    flash_set("No file uploaded", "error");
    header("Location: upload_m3u.php"); exit;
  }

  // Normalize PHP's "multi-file" structure into a simple list.
  $tmp_names = $_FILES['m3u']['tmp_name'] ?? [];
  $names     = $_FILES['m3u']['name'] ?? [];
  $errors    = $_FILES['m3u']['error'] ?? [];

  if (!is_array($tmp_names)) {
    $tmp_names = [$tmp_names];
    $names     = [($names ?: 'playlist.m3u')];
    $errors    = [($errors ?? UPLOAD_ERR_OK)];
  }

  // Filter out empty slots.
  $files = [];
  for ($i = 0; $i < count($tmp_names); $i++) {
    $t = $tmp_names[$i] ?? '';
    if (!$t) continue;
    $files[] = [
      'orig_index' => $i,
      'tmp_name' => $t,
      'name' => (string)($names[$i] ?? 'playlist.m3u'),
      'error' => (int)($errors[$i] ?? UPLOAD_ERR_OK),
    ];
  }

  if (!$files) {
    flash_set("No file uploaded", "error");
    header("Location: upload_m3u.php"); exit;
  }

  // Optional: admin-defined file processing order (drag/drop list in UI).
  $order_raw = trim($_POST['file_order'] ?? '');
  if ($order_raw !== '') {
    $parts = array_values(array_filter(array_map('trim', explode(',', $order_raw)), 'strlen'));
    $idxs = [];
    foreach ($parts as $p) {
      if (ctype_digit($p)) $idxs[] = (int)$p;
    }
    if ($idxs) {
      $map = [];
      foreach ($files as $f) {
        $map[(int)($f['orig_index'] ?? -1)] = $f;
      }
      $re = [];
      $seen = [];
      foreach ($idxs as $oi) {
        if (isset($map[$oi]) && empty($seen[$oi])) {
          $re[] = $map[$oi];
          $seen[$oi] = 1;
        }
      }
      // Append remaining (any not listed or invalid indices) in original order.
      foreach ($files as $f) {
        $oi = (int)($f['orig_index'] ?? -1);
        if ($oi >= 0 && empty($seen[$oi])) $re[] = $f;
      }
      if ($re) $files = $re;
    }
  }

  // defaults chosen in the upload form
  $default_direct = isset($_POST['direct_play']) ? 1 : 0;
  $default_ext    = trim($_POST['container_ext'] ?? '');
  $default_ext    = ($default_ext === '') ? null : $default_ext; // m3u8 | ts | null(auto)
  $default_adult  = isset($_POST['adult_content']) ? 1 : 0;

  $inserted = 0;
  $file_counts = [];

  // Best-effort: keep categories table in sync and set channels.category_id.
  $use_categories = true;
  $cat_map = [];
  $ins_cat = null;
  $sel_cat = null;
  $uncat_id = 0;
  try {
    ensure_categories($pdo);
    $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)")->execute(['Uncategorized']);
    $uncat_id = (int)($pdo->query("SELECT id FROM categories WHERE name='Uncategorized' LIMIT 1")
      ->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
    foreach ($pdo->query("SELECT id,name FROM categories") as $r) {
      $cat_map[$r['name']] = (int)$r['id'];
    }
    $ins_cat = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
    $sel_cat = $pdo->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
  } catch (Throwable $e) {
    $use_categories = false;
  }

  // Ordering: preserve admin-defined import order in UI + user playlist.
  // We treat stream_url as the stable key: if a channel already exists, we update it
  // (including sort order) instead of creating duplicates.
  $import_cat_order = [];     // category IDs in first-seen order
  $import_cat_seen  = [];     // category ID => true
  $per_cat_pos      = [];     // category ID => last assigned position
  $touched_ids      = [];     // category ID => [channel_id => true]

  if ($use_categories) {
    $sel_existing = $pdo->prepare("SELECT id FROM channels WHERE stream_url=? LIMIT 1");
    $upd_existing = $pdo->prepare("UPDATE channels SET
      name=?, category_id=?, group_title=?, tvg_id=?, tvg_name=?, tvg_logo=?,
      direct_play=?, container_ext=?, is_adult=?, sort_order=?
      WHERE id=?");
    $ins_new = $pdo->prepare("INSERT INTO channels
      (name,category_id,group_title,tvg_id,tvg_name,tvg_logo,stream_url,direct_play,container_ext,is_adult,sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  } else {
    $sel_existing = $pdo->prepare("SELECT id FROM channels WHERE stream_url=? LIMIT 1");
    $upd_existing = $pdo->prepare("UPDATE channels SET
      name=?, group_title=?, tvg_id=?, tvg_name=?, tvg_logo=?,
      direct_play=?, container_ext=?, is_adult=?, sort_order=?
      WHERE id=?");
    $ins_new = $pdo->prepare("INSERT INTO channels
      (name,group_title,tvg_id,tvg_name,tvg_logo,stream_url,direct_play,container_ext,is_adult,sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
  }

  $inserted = 0;
  $updated  = 0;
  $file_counts = [];

  $pdo->beginTransaction();
  try {
    foreach ($files as $f) {
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
      // Skip errored file slots.
      continue;
    }

    $content = @file_get_contents($f['tmp_name']);
    if ($content === false || trim($content) === '') continue;

    $items = parse_m3u($content);
    $this_inserted = 0;

    foreach ($items as $ch) {
      if (!$ch['stream_url']) continue;
      $group = trim($ch['group_title'] ?? '');
      if ($group === '') $group = 'Uncategorized';

      if ($use_categories) {
        // Create category on the fly if missing.
        if (!isset($cat_map[$group])) {
          try {
            $ins_cat->execute([$group]);
            $sel_cat->execute([$group]);
            $cat_map[$group] = (int)($sel_cat->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
          } catch (Throwable $e) {
            $cat_map[$group] = $uncat_id;
          }
        }
        $cid = (int)($cat_map[$group] ?? $uncat_id);
        if ($cid <= 0) $cid = $uncat_id;

        // Track category order (first time encountered in this import).
        if (!isset($import_cat_seen[$cid])) {
          $import_cat_seen[$cid] = true;
          $import_cat_order[] = $cid;
        }

        // Per-category channel order.
        if (!isset($per_cat_pos[$cid])) $per_cat_pos[$cid] = 0;
        $pos = ++$per_cat_pos[$cid];

        // Upsert by stream_url.
        $sel_existing->execute([$ch['stream_url']]);
        $existing_id = (int)($sel_existing->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
        if ($existing_id > 0) {
          $upd_existing->execute([
            $ch['name'],
            $cid ?: null,
            $group,
            $ch['tvg_id'],
            $ch['tvg_name'],
            $ch['tvg_logo'],
            $default_direct,
            $default_ext,
            $default_adult,
            $pos,
            $existing_id
          ]);
          $updated++;
          $touched_ids[$cid][$existing_id] = true;
        } else {
          $ins_new->execute([
            $ch['name'],
            $cid ?: null,
            $group,
            $ch['tvg_id'],
            $ch['tvg_name'],
            $ch['tvg_logo'],
            $ch['stream_url'],
            $default_direct,
            $default_ext,
            $default_adult,
            $pos
          ]);
          $new_id = (int)$pdo->lastInsertId();
          if ($new_id > 0) $touched_ids[$cid][$new_id] = true;
          $inserted++;
          $this_inserted++;
        }
      } else {
        // No categories table available; preserve overall import order.
        $cid = 0;
        if (!isset($per_cat_pos[$cid])) $per_cat_pos[$cid] = 0;
        $pos = ++$per_cat_pos[$cid];

        $sel_existing->execute([$ch['stream_url']]);
        $existing_id = (int)($sel_existing->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
        if ($existing_id > 0) {
          $upd_existing->execute([
            $ch['name'],
            $group,
            $ch['tvg_id'],
            $ch['tvg_name'],
            $ch['tvg_logo'],
            $default_direct,
            $default_ext,
            $default_adult,
            $pos,
            $existing_id
          ]);
          $updated++;
        } else {
          $ins_new->execute([
            $ch['name'],
            $group,
            $ch['tvg_id'],
            $ch['tvg_name'],
            $ch['tvg_logo'],
            $ch['stream_url'],
            $default_direct,
            $default_ext,
            $default_adult,
            $pos
          ]);
          $inserted++;
          $this_inserted++;
        }
      }
    }

    $file_counts[] = basename($f['name']) . ": $this_inserted";
  }

    // If categories are available, rewrite category + channel sort_orders so the UI + exports
    // reflect the admin's chosen import order.
    if ($use_categories && $import_cat_order) {
      // 1) Categories: imported categories first, then the rest.
      $upd_cat_sort = $pdo->prepare("UPDATE categories SET sort_order=? WHERE id=?");
      $pos = 1;
      $seen = [];
      foreach ($import_cat_order as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0 || isset($seen[$cid])) continue;
        $seen[$cid] = true;
        $upd_cat_sort->execute([$pos++, $cid]);
      }

      // Remaining categories keep relative order but come after.
      $in = implode(',', array_fill(0, count(array_keys($seen)), '?'));
      if ($in) {
        $st = $pdo->prepare("SELECT id FROM categories WHERE id NOT IN ($in) ORDER BY sort_order,id");
        $st->execute(array_keys($seen));
      } else {
        $st = $pdo->query("SELECT id FROM categories ORDER BY sort_order,id");
      }
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $upd_cat_sort->execute([$pos++, (int)$r['id']]);
      }

      // 2) Channels inside touched categories: imported/updated first (with sort_order already set to 1..N)
      // then everything else in that category.
      $upd_ch_sort = $pdo->prepare("UPDATE channels SET sort_order=? WHERE id=?");
      foreach ($touched_ids as $cid => $idset) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $max = (int)($per_cat_pos[$cid] ?? 0);
        if ($max <= 0) continue;
        $next = $max + 1;

        $ids = array_keys($idset ?: []);
        if ($ids) {
          // Chunk IN() lists to avoid SQL limits.
          $chunks = array_chunk($ids, 800);
          $whereNot = [];
          $params = [$cid];
          foreach ($chunks as $ch) {
            $whereNot[] = "id NOT IN (" . implode(',', array_fill(0, count($ch), '?')) . ")";
            foreach ($ch as $x) $params[] = (int)$x;
          }
          $sql = "SELECT id FROM channels WHERE category_id=? AND " . implode(' AND ', $whereNot) . " ORDER BY sort_order,id";
          $st2 = $pdo->prepare($sql);
          $st2->execute($params);
        } else {
          $st2 = $pdo->prepare("SELECT id FROM channels WHERE category_id=? ORDER BY sort_order,id");
          $st2->execute([$cid]);
        }

        while ($row = $st2->fetch(PDO::FETCH_ASSOC)) {
          $upd_ch_sort->execute([$next++, (int)$row['id']]);
        }
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  $files_done = count($file_counts);
  if ($files_done === 0) {
    flash_set("No valid M3U files were processed (check upload limits / file format).", "error");
    header("Location: upload_m3u.php"); exit;
  }

  $detail = " (" . implode(", ", array_slice($file_counts, 0, 6)) . (count($file_counts) > 6 ? ", …" : "") . ")";
  $msg = "Imported $inserted new channel(s)";
  if ($updated > 0) $msg .= ", updated $updated existing";
  $msg .= " from $files_done file(s)$detail";
  flash_set($msg, "success");
  header("Location: channels_manager.php"); exit;
}

$topbar = file_get_contents(__DIR__ . '/topbar.html');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Import M3U</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    .m3u-order-list{list-style:none;padding:0;margin:0;border:1px solid rgba(0,0,0,.12);border-radius:10px;overflow:hidden;}
    .m3u-order-list li{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid rgba(0,0,0,.06);cursor:grab;user-select:none;}
    .m3u-order-list li:last-child{border-bottom:none;}
    .m3u-order-list li.dragging{opacity:.5;}
    .m3u-order-list .handle{font-weight:700;opacity:.55;}
    .m3u-order-list .fname{font-weight:600;}
    .m3u-order-list .meta{margin-left:auto;opacity:.6;font-size:12px;}
    .m3u-mini-btn{padding:6px 10px;border:1px solid rgba(0,0,0,.2);background:#fff;border-radius:10px;cursor:pointer;}
    .m3u-mini-btn:hover{filter:brightness(.98);}
  </style>
</head>
<body>
<?= $topbar ?>

<div class="card" style="max-width:760px;margin:0 auto;">
  <h2>Upload / Import M3U</h2>
  <?php flash_show(); ?>
  <p class="muted">
    Upload a <b>legal</b> M3U playlist you built.
    These options apply as <b>defaults to every imported channel</b>.
  </p>

  <form method="post" enctype="multipart/form-data">
    <label>M3U File(s)</label>
    <input type="file" id="m3u_input" name="m3u[]" accept=".m3u,.m3u8" multiple required>
    <input type="hidden" id="file_order" name="file_order" value="">
    <div class="muted" style="margin-top:6px;">
      Tip: hold <b>Ctrl</b> (Windows/Linux) or <b>Cmd</b> (Mac) to select multiple files.
    </div>

    <div id="m3u_order_wrap" style="margin-top:12px;display:none;">
      <div class="muted" style="margin-bottom:6px;">Drag to set import order (top imports first):</div>
      <ul id="m3u_file_list" class="m3u-order-list"></ul>
      <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" id="m3u_sort_az" class="m3u-mini-btn">Sort A→Z</button>
        <button type="button" id="m3u_sort_za" class="m3u-mini-btn">Sort Z→A</button>
      </div>
    </div>

    <label class="check-row" style="margin-top:12px;">
      <input type="checkbox" name="direct_play" value="1" <?= !empty($default_direct) ? 'checked' : '' ?>>
      Direct play for all imported channels (bypass proxy / show source)
    </label>

    <label class="check-row" style="margin-top:10px;">
      <input type="checkbox" name="adult_content" value="1" <?= !empty($default_adult) ? 'checked' : '' ?>>
      Mark ALL imported streams as <b>Adult</b> content
    </label>


    <label style="margin-top:10px;">Container Extension (default)</label>
    <select name="container_ext">
      <option value="">Auto-detect per channel</option>
      <option value="m3u8">m3u8 (HLS)</option>
      <option value="ts">ts (Xtream TS)</option>
    </select>

    <div style="margin-top:12px;">
      <button type="submit">Import</button>
    </div>
  </form>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
<script>
(function(){
  const input = document.getElementById('m3u_input');
  const wrap = document.getElementById('m3u_order_wrap');
  const list = document.getElementById('m3u_file_list');
  const order = document.getElementById('file_order');
  const btnAZ = document.getElementById('m3u_sort_az');
  const btnZA = document.getElementById('m3u_sort_za');

  function formatBytes(bytes){
    const n = Number(bytes||0);
    if (!n) return '';
    const units = ['B','KB','MB','GB'];
    let u = 0; let v = n;
    while (v >= 1024 && u < units.length-1){ v/=1024; u++; }
    return (u===0? v : v.toFixed(1)) + ' ' + units[u];
  }

  function currentOrder(){
    return Array.from(list.querySelectorAll('li')).map(li => li.getAttribute('data-idx')).filter(Boolean);
  }

  function syncHidden(){
    order.value = currentOrder().join(',');
  }

  function buildFromFiles(){
    list.innerHTML = '';
    const files = input && input.files ? Array.from(input.files) : [];
    if (!files.length){
      wrap.style.display = 'none';
      order.value = '';
      return;
    }
    // Show ordering UI only when 2+ files
    wrap.style.display = files.length > 1 ? 'block' : 'none';
    files.forEach((f, i) => {
      const li = document.createElement('li');
      li.draggable = true;
      li.setAttribute('data-idx', String(i));
      li.innerHTML = '<span class="handle">☰</span>' +
        '<span class="fname"></span>' +
        '<span class="meta"></span>';
      const fname = f.name || ('file-'+(i+1));
      li.setAttribute('data-name', fname);
      li.querySelector('.fname').textContent = (i+1) + '. ' + fname;
      li.querySelector('.meta').textContent = formatBytes(f.size);
      list.appendChild(li);
    });
    syncHidden();
  }

  function getDragAfterElement(container, y){
    const els = [...container.querySelectorAll('li:not(.dragging)')];
    return els.reduce((closest, child) => {
      const box = child.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) {
        return { offset: offset, element: child };
      } else {
        return closest;
      }
    }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
  }

  list.addEventListener('dragstart', (e) => {
    const li = e.target.closest('li');
    if (!li) return;
    li.classList.add('dragging');
    try { e.dataTransfer.setData('text/plain', li.getAttribute('data-idx') || ''); } catch(_){}
  });
  list.addEventListener('dragend', (e) => {
    const li = e.target.closest('li');
    if (!li) return;
    li.classList.remove('dragging');
    // re-number labels after reorder
    Array.from(list.querySelectorAll('li')).forEach((el, j) => {
      const nameSpan = el.querySelector('.fname');
      if (!nameSpan) return;
      const orig = el.getAttribute('data-name') || '';
      nameSpan.textContent = (j+1) + '. ' + orig;
    });
    syncHidden();
  });

  list.addEventListener('dragover', (e) => {
    e.preventDefault();
    const dragging = list.querySelector('.dragging');
    if (!dragging) return;
    const after = getDragAfterElement(list, e.clientY);
    if (after == null) {
      list.appendChild(dragging);
    } else {
      list.insertBefore(dragging, after);
    }
  });

  function sortList(direction){
    const items = Array.from(list.querySelectorAll('li'));
    items.sort((a,b) => {
      const an = (a.getAttribute('data-name') || '').toLowerCase();
      const bn = (b.getAttribute('data-name') || '').toLowerCase();
      return direction === 'asc' ? an.localeCompare(bn) : bn.localeCompare(an);
    });
    items.forEach(el => list.appendChild(el));
    // re-number
    Array.from(list.querySelectorAll('li')).forEach((el, j) => {
      const nameSpan = el.querySelector('.fname');
      if (!nameSpan) return;
      const orig = el.getAttribute('data-name') || '';
      nameSpan.textContent = (j+1) + '. ' + orig;
    });
    syncHidden();
  }

  btnAZ && btnAZ.addEventListener('click', () => sortList('asc'));
  btnZA && btnZA.addEventListener('click', () => sortList('desc'));

  input && input.addEventListener('change', buildFromFiles);

  // Ensure hidden order is set on submit even if user never reorders
  const form = input ? input.closest('form') : null;
  form && form.addEventListener('submit', syncHidden);

  // initial render
  buildFromFiles();
})();
</script>
</body>
</html>
