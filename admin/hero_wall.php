<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../hero_wall.php';

require_admin();

// Load current config
$cfg = gc_hero_wall_get();

function _files_to_list(array $files): array {
  // Normalize PHP $_FILES multi-upload to a list
  $out = [];
  if (!isset($files['name']) || !is_array($files['name'])) return $out;
  $n = count($files['name']);
  for ($i=0;$i<$n;$i++) {
    $out[] = [
      'name' => $files['name'][$i] ?? '',
      'type' => $files['type'][$i] ?? '',
      'tmp_name' => $files['tmp_name'][$i] ?? '',
      'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
      'size' => $files['size'][$i] ?? 0,
    ];
  }
  return $out;
}

function _handle_uploads(string $rowKey, array $existing): array {
  $field = 'upload_' . $rowKey;
  if (empty($_FILES[$field])) return $existing;

  $uploadDir = gc_hero_wall_upload_dir_fs();
  if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
  if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
    flash_set('Upload folder is not writable: ' . $uploadDir, 'err');
    return $existing;
  }

  $allowedExt = ['jpg','jpeg','png','webp'];
  foreach (_files_to_list($_FILES[$field]) as $f) {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    if (count($existing) >= 5) break;

    $orig = (string)($f['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) continue;

    $base = preg_replace('~[^a-zA-Z0-9_-]+~', '-', pathinfo($orig, PATHINFO_FILENAME));
    $base = trim($base, '-');
    if ($base === '') $base = 'img';
    $fname = $base . '-' . substr(bin2hex(random_bytes(8)), 0, 12) . '.' . $ext;
    $dest = rtrim($uploadDir, '/') . '/' . $fname;

    if (@move_uploaded_file((string)($f['tmp_name'] ?? ''), $dest)) {
      @chmod($dest, 0664);
      $existing[] = gc_hero_wall_upload_dir_web() . '/' . $fname;
    }
  }
  return array_slice($existing, 0, 5);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_wall'])) {
  $new = ['top'=>[], 'mid'=>[], 'bottom'=>[]];
  foreach (['top','mid','bottom'] as $k) {
    $vals = $_POST[$k] ?? [];
    if (!is_array($vals)) $vals = [];
    foreach ($vals as $v) {
      $v = trim((string)$v);
      if ($v === '') continue;
      $new[$k][] = $v;
      if (count($new[$k]) >= 5) break;
    }
  }

  // Apply uploads (append)
  $new['top'] = _handle_uploads('top', $new['top']);
  $new['mid'] = _handle_uploads('mid', $new['mid']);
  $new['bottom'] = _handle_uploads('bottom', $new['bottom']);

  if (gc_hero_wall_save($new)) {
    flash_set('Homepage background updated.', 'ok');
  } else {
    flash_set('Failed to save config. Check permissions on /cache.', 'err');
  }
  header('Location: hero_wall.php');
  exit;
}

// Reload after save
$cfg = gc_hero_wall_get();

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

function _slot_inputs(string $row, array $vals): string {
  $out = '';
  for ($i=0; $i<5; $i++) {
    $v = (string)($vals[$i] ?? '');
    $out .= '<div class="hw-slot">'
      . '<input class="hw-url" type="text" name="' . e($row) . '[]" placeholder="Image URL or /uploads/..." value="' . e($v) . '">' 
      . ($v !== '' ? '<img class="hw-thumb" src="' . e($v) . '" alt="">' : '<div class="hw-thumb hw-empty">Empty</div>')
      . '</div>';
  }
  return $out;
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Homepage Background</title>
  <link rel="stylesheet" href="panel.css">
    <style>
    .hw-help{color:var(--muted); margin-top:6px; line-height:1.55;}

    /* Form wrapper (keeps spacing without creating an extra card) */
    .hw-form{margin:16px 0;}

    /* Section cards */
    .hw-card{background:var(--card); border:1px solid var(--line); border-radius:18px; padding:16px; margin:16px 0; box-shadow:var(--shadow);}
    .hw-rowhead{display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:10px;}
    .hw-rowhead h2{margin:0; font-size:18px; color:var(--text);}
    .hw-rowhead .dir{font-size:12px; color:var(--muted);}

    .hw-grid{display:grid; grid-template-columns: 1fr; gap:12px;}
    @media (min-width: 900px){ .hw-grid{grid-template-columns: 1fr 1fr;} }

    .hw-slot{display:flex; align-items:center; gap:12px;}
    .hw-url{
      width:100%;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--line);
      background:var(--bg-soft);
      color:var(--text);
      outline:none;
    }
    .hw-url::placeholder{color:var(--muted); opacity:.85;}
    .hw-url:focus{border-color:rgba(251,146,60,.65); box-shadow:0 0 0 3px rgba(251,146,60,.18);}

    .hw-thumb{
      width:92px; height:52px; object-fit:cover;
      border-radius:12px;
      border:1px solid var(--line);
      background:var(--bg-soft);
      flex:0 0 auto;
    }
    .hw-empty{display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:12px;}

    .hw-upload{margin-top:10px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
    .hw-upload input[type=file]{max-width:520px;}

    .hw-actions{display:flex; gap:10px; align-items:center; margin-top:14px; flex-wrap:wrap;}
    .hw-btn{
      padding:10px 14px;
      border-radius:14px;
      border:1px solid var(--line);
      background:var(--bg-soft);
      color:var(--text);
      font-weight:800;
      cursor:pointer;
    }
    .hw-btn:hover{filter:brightness(.98);}
    .hw-btn.primary{
      background: linear-gradient(180deg, rgba(251,146,60,.98), rgba(251,146,60,.78));
      border-color: rgba(251,146,60,.55);
      color:#0b0d12;
    }
    .hw-btn.primary:hover{filter:brightness(1.02);}
    .hw-note{font-size:12px; color:var(--muted);}
  </style>
</head>
<body>
<?= $topbar ?>


<div class="card">
  <h2>Homepage Background</h2>
  <div class="hw-help">
    <div style="font-weight:800; color:var(--text); margin-bottom:6px;">How to use</div>
    <ul style="margin:0 0 10px 18px; padding:0;">
      <li>Paste up to <b>5</b> image URLs per row (full <span class="code">https://...</span> URLs or local paths like <span class="code">/uploads/hero_wall/...</span>).</li>
      <li>Or use <b>Upload</b> to add images to that row, then click <b>Save</b> (uploads are stored in <span class="code">/uploads/hero_wall</span>).</li>
      <li>Leave a slot blank to remove it. Top &amp; bottom slide <b>right</b>; middle slides <b>left</b>.</li>
    </ul>
    <b>Auto mode:</b> If you leave <b>all</b> slots empty, the site will automatically pull a fresh set of background images
    from <b>TMDB Trending</b> (movies + TV) using the TMDB key in <span class="code">config.php</span>.
  </div>

  <?php flash_show(); ?>
</div>

<br>
<form method="post" enctype="multipart/form-data" class="hw-form">
    <input type="hidden" name="save_wall" value="1">

    <div class="hw-card">
      <div class="hw-rowhead">
        <h2>Top row</h2>
        <div class="dir">Slides right</div>
      </div>
      <div class="hw-grid">
        <?= _slot_inputs('top', $cfg['top'] ?? []) ?>
      </div>
      <div class="hw-upload">
        <label class="hw-note"><b>Upload</b> (optional, adds to row):</label>
        <input type="file" name="upload_top[]" accept="image/*" multiple>
      </div>
    </div>

    <div class="hw-card">
      <div class="hw-rowhead">
        <h2>Middle row</h2>
        <div class="dir">Slides left</div>
      </div>
      <div class="hw-grid">
        <?= _slot_inputs('mid', $cfg['mid'] ?? []) ?>
      </div>
      <div class="hw-upload">
        <label class="hw-note"><b>Upload</b> (optional, adds to row):</label>
        <input type="file" name="upload_mid[]" accept="image/*" multiple>
      </div>
    </div>

    <div class="hw-card">
      <div class="hw-rowhead">
        <h2>Bottom row</h2>
        <div class="dir">Slides right</div>
      </div>
      <div class="hw-grid">
        <?= _slot_inputs('bottom', $cfg['bottom'] ?? []) ?>
      </div>
      <div class="hw-upload">
        <label class="hw-note"><b>Upload</b> (optional, adds to row):</label>
        <input type="file" name="upload_bottom[]" accept="image/*" multiple>
      </div>
    </div>

    <div class="hw-actions">
      <button class="hw-btn primary" type="submit">Save</button>
      <div class="hw-note">Tip: leave a slot blank to remove it.</div>
    </div>
  </form>



</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
