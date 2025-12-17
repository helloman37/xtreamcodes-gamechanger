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
  $cleanup_paths = [];
  register_shutdown_function(function() use (&$cleanup_paths) {
    foreach ($cleanup_paths as $p) {
      if ($p && is_file($p)) @unlink($p);
    }
  });

  $import_mode = trim((string)($_POST['import_mode'] ?? ''));
  $url_raw     = trim((string)($_POST['m3u_url'] ?? ''));

  // Detect uploads (single or multi-file).
  $hasUpload = false;
  if (!empty($_FILES['m3u'])) {
    $tmp_names = $_FILES['m3u']['tmp_name'] ?? [];
    if (is_array($tmp_names)) {
      foreach ($tmp_names as $t) { if (!empty($t)) { $hasUpload = true; break; } }
    } else {
      $hasUpload = !empty($tmp_names);
    }
  }

  // Mode decision:
  // - If user selected "URL" => URL mode
  // - If no files uploaded but URL provided => URL mode (nice UX)
  $useUrl = ($import_mode === 'url') || (!$hasUpload && $url_raw !== '');

  $files = [];

  if ($useUrl) {
    $urls = preg_split('/[\r\n\s,]+/', $url_raw);
    $urls = array_values(array_filter(array_map('trim', $urls ?: []), 'strlen'));
    $urls = array_slice($urls, 0, 5);

    if (!$urls) {
      flash_set("No M3U URL provided", "error");
      header("Location: upload_m3u.php"); exit;
    }

    $i = 0;
    foreach ($urls as $u) {
      $r = iptv_fetch_url_to_temp($u, 52428800, 25); // 50MB max, 25s timeout
      if (empty($r['ok'])) {
        flash_set("Failed to download M3U (" . $u . "): " . ($r['error'] ?? 'Unknown error'), "error");
        header("Location: upload_m3u.php"); exit;
      }
      $cleanup_paths[] = $r['tmp'];
      $files[] = [
        'orig_index' => $i++,
        'tmp_name' => $r['tmp'],
        'name' => (string)($r['name'] ?? 'remote.m3u'),
        'error' => UPLOAD_ERR_OK,
      ];
    }
  } else {
    // Upload mode (supports single or multi-upload).
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
  }

  // defaults chosen in the upload form
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


  // Ordering:
  // - Existing channels keep their current sort_order (importing again won't reshuffle your list).
  // - NEW channels are appended to the bottom of their category (max sort_order + 1).
  // - If an existing channel's category changes, it is appended to the bottom of the new category.
  $per_cat_pos = []; // category_id => last assigned position (seeded from DB max)
  $sel_max_sort_cat = null;

  if ($use_categories) {
    $sel_existing = $pdo->prepare("SELECT id, category_id, sort_order FROM channels WHERE stream_url=? LIMIT 1");
    $sel_max_sort_cat = $pdo->prepare("SELECT COALESCE(MAX(CASE WHEN sort_order=0 THEN id ELSE sort_order END),0) AS m FROM channels WHERE category_id=?");
    $upd_existing = $pdo->prepare("UPDATE channels SET
      name=?, category_id=?, group_title=?, tvg_id=?, tvg_name=?, tvg_logo=?,
      direct_play=?, container_ext=?, is_adult=?, sort_order=?
      WHERE id=?");
    $ins_new = $pdo->prepare("INSERT INTO channels
      (name,category_id,group_title,tvg_id,tvg_name,tvg_logo,stream_url,direct_play,container_ext,is_adult,sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  } else {
    $sel_existing = $pdo->prepare("SELECT id, sort_order FROM channels WHERE stream_url=? LIMIT 1");
    $upd_existing = $pdo->prepare("UPDATE channels SET
      name=?, group_title=?, tvg_id=?, tvg_name=?, tvg_logo=?,
      direct_play=?, container_ext=?, is_adult=?, sort_order=?
      WHERE id=?");
    $ins_new = $pdo->prepare("INSERT INTO channels
      (name,group_title,tvg_id,tvg_name,tvg_logo,stream_url,direct_play,container_ext,is_adult,sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?)");

    // Seed global position so new channels append to the bottom.
    $max_global = (int)($pdo->query("SELECT COALESCE(MAX(CASE WHEN sort_order=0 THEN id ELSE sort_order END),0) AS m FROM channels")
      ->fetch(PDO::FETCH_ASSOC)['m'] ?? 0);
    $per_cat_pos[0] = $max_global;
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
        // Seed per-category position from DB once so new channels append to the bottom.
        if (!isset($per_cat_pos[$cid])) {
          $sel_max_sort_cat->execute([$cid]);
          $per_cat_pos[$cid] = (int)($sel_max_sort_cat->fetch(PDO::FETCH_ASSOC)['m'] ?? 0);
        }

        // Upsert by stream_url (keep existing sort_order; only append if moved categories).
        $sel_existing->execute([$ch['stream_url']]);
        $row = $sel_existing->fetch(PDO::FETCH_ASSOC) ?: [];
        $existing_id = (int)($row['id'] ?? 0);

        if ($existing_id > 0) {
          $current_cid  = (int)($row['category_id'] ?? 0);
          $current_sort = (int)($row['sort_order'] ?? 0);
          if ($current_sort <= 0) $current_sort = $existing_id;

          // Only assign a new sort_order if the channel moved categories.
          $new_sort = $current_sort;
          if ($current_cid !== $cid) {
            $new_sort = ++$per_cat_pos[$cid];
          }

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
            $new_sort,
            $existing_id
          ]);
          $updated++;
        } else {
          // New channel: append to bottom of this category.
          $pos = ++$per_cat_pos[$cid];

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
          $inserted++;
          $this_inserted++;
        }
      } else {
        // No categories table available; preserve overall import order.
        $cid = 0;

        // Upsert by stream_url (keep existing sort_order; new ones append to bottom).
        $sel_existing->execute([$ch['stream_url']]);
        $row = $sel_existing->fetch(PDO::FETCH_ASSOC) ?: [];
        $existing_id = (int)($row['id'] ?? 0);

        if ($existing_id > 0) {
          $current_sort = (int)($row['sort_order'] ?? 0);
          if ($current_sort <= 0) $current_sort = $existing_id;

          $upd_existing->execute([
            $ch['name'],
            $group,
            $ch['tvg_id'],
            $ch['tvg_name'],
            $ch['tvg_logo'],
            $default_direct,
            $default_ext,
            $default_adult,
            $current_sort,
            $existing_id
          ]);
          $updated++;
        } else {
          // New channel: append to bottom of the overall list.
          $pos = ++$per_cat_pos[$cid];

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


    $pdo->commit();
	  } catch (Throwable $e) {
	    if ($pdo->inTransaction()) { $pdo->rollBack(); }
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
    <label>Import Source</label>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:10px;">
      <label class="check-row" style="margin:0;">
        <input type="radio" name="import_mode" value="upload" checked>
        Upload file(s)
      </label>
      <label class="check-row" style="margin:0;">
        <input type="radio" name="import_mode" value="url">
        By URL
      </label>
    </div>

    <div id="upload_mode_wrap">
      <label>M3U File(s)</label>
    <input type="file" id="m3u_input" name="m3u[]" accept=".m3u,.m3u8" multiple>
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


</div><!-- upload_mode_wrap -->

<div id="url_mode_wrap" style="display:none;">
  <label>M3U URL</label>
  <textarea id="m3u_url" name="m3u_url" rows="3" placeholder="https://example.com/playlist.m3u" style="width:100%;"></textarea>
  <div class="muted" style="margin-top:6px;">
    Paste a direct M3U link. You can also paste up to <b>5</b> URLs (one per line).
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
const radioUpload = document.querySelector('input[name="import_mode"][value="upload"]');
const radioUrl    = document.querySelector('input[name="import_mode"][value="url"]');
const uploadWrap  = document.getElementById('upload_mode_wrap');
const urlWrap     = document.getElementById('url_mode_wrap');
const urlField    = document.getElementById('m3u_url');

function setMode(mode){
  const isUrl = mode === 'url';
  if (uploadWrap) uploadWrap.style.display = isUrl ? 'none' : 'block';
  if (urlWrap) urlWrap.style.display = isUrl ? 'block' : 'none';
  if (input) input.required = !isUrl;
  if (urlField) urlField.required = isUrl;

  if (isUrl) {
    // URL mode doesn't use file ordering UI
    if (wrap) wrap.style.display = 'none';
    if (order) order.value = '';
  }
}

radioUpload && radioUpload.addEventListener('change', () => {
  if (radioUpload.checked) setMode('upload');
});
radioUrl && radioUrl.addEventListener('change', () => {
  if (radioUrl.checked) setMode('url');
});
urlField && urlField.addEventListener('input', () => {
  if (urlField.value.trim() !== '' && radioUrl) {
    radioUrl.checked = true;
    setMode('url');
  }
});

// initial mode
if (urlField && urlField.value.trim() !== '' && radioUrl) {
  radioUrl.checked = true;
  setMode('url');
} else {
  setMode((radioUrl && radioUrl.checked) ? 'url' : 'upload');
}


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
