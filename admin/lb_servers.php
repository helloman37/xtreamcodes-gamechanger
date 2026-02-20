<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

function lb_norm(string $u): string {
  $u = trim($u);
  $u = rtrim($u, '/');
  return $u;
}

function lb_is_valid(string $u): bool {
  if ($u === '') return false;
  $p = @parse_url($u);
  if (!$p || empty($p['scheme']) || empty($p['host'])) return false;
  $scheme = strtolower((string)$p['scheme']);
  return in_array($scheme, ['http','https'], true);
}

// --- Actions ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_check();

  // Add / Update
  if (isset($_POST['save_lb'])) {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $base_url = lb_norm((string)($_POST['base_url'] ?? ''));
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $weight = (int)($_POST['weight'] ?? 1);
    if ($weight < 1) $weight = 1;
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!lb_is_valid($base_url)) {
      flash_set('Invalid base URL. Use http(s)://host (no trailing slash).', 'error');
      header('Location: lb_servers.php' . ($id ? ('?edit=' . $id) : ''));
      exit;
    }

    if ($id > 0) {
      $st = $pdo->prepare("UPDATE lb_servers SET name=?, base_url=?, enabled=?, weight=?, notes=? WHERE id=?");
      $st->execute([$name ?: null, $base_url, $enabled, $weight, $notes ?: null, $id]);
      flash_set('Load balancer updated', 'success');
    } else {
      $st = $pdo->prepare("INSERT INTO lb_servers (name, base_url, enabled, weight, notes) VALUES (?,?,?,?,?)");
      $st->execute([$name ?: null, $base_url, $enabled, $weight, $notes ?: null]);
      flash_set('Load balancer added', 'success');
    }

    header('Location: lb_servers.php');
    exit;
  }

  // Toggle
  if (isset($_POST['toggle_lb'])) {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("UPDATE lb_servers SET enabled = IF(enabled=1,0,1) WHERE id=?");
    $st->execute([$id]);
    header('Location: lb_servers.php');
    exit;
  }

  // Delete
  if (isset($_POST['delete_lb'])) {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("DELETE FROM lb_servers WHERE id=?");
    $st->execute([$id]);
    flash_set('Load balancer deleted', 'success');
    header('Location: lb_servers.php');
    exit;
  }
}

$edit_id = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($edit_id > 0) {
  $st = $pdo->prepare("SELECT * FROM lb_servers WHERE id=? LIMIT 1");
  $st->execute([$edit_id]);
  $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$lbs = $pdo->query("SELECT * FROM lb_servers ORDER BY enabled DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$active_count = 0;
foreach ($lbs as $r) { if ((int)($r['enabled'] ?? 0) === 1) $active_count++; }

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
  <title>Load Balancers</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
  <style>
    /* Page-specific layout tightening (keeps panel.css untouched) */
    .lb-form .row{align-items:flex-start}
    .lb-form .row>div{flex:1; min-width:260px}
    .lb-form .row>div.wide{flex:2; min-width:340px}
    .lb-form label{display:block; margin-bottom:6px; font-weight:800; font-size:12px; color:#334155}
    .lb-form input[type="text"],
    .lb-form input[type="number"]{width:100%}
    .lb-form .muted{line-height:1.25}

    /* Table alignment */
    .lb-table{table-layout:fixed; width:100%}
    .lb-table th, .lb-table td{vertical-align:middle}
    .lb-table .col-id{width:60px}
    .lb-table .col-name{width:160px}
    .lb-table .col-weight{width:90px}
    .lb-table .col-status{width:130px}
    .lb-table .col-actions{width:260px}
    .lb-actions{display:flex; gap:10px; align-items:center; justify-content:flex-start; flex-wrap:wrap}
    .lb-actions form{display:inline; margin:0}
    /* Make action buttons match the orange primary look */
    .lb-actions .xt-btn{
      padding:6px 10px;
      opacity:1;
      border:0;
      background: linear-gradient(135deg, #ff8a00, #ff2e2e);
      color:#0b1020;
      border-radius:12px;
      font-weight:900;
      cursor:pointer;
    }
    .lb-actions .xt-btn:hover{filter:brightness(1.05)}

  </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Load Balancers</h2>
  <?php flash_show(); ?>

  <p class="muted" style="margin-top:6px;">
    This controls the <b>host</b> used in protected stream links your panel generates (live/movie/series/stream).<br>
    <b>If no enabled LBs exist, the panel automatically falls back to your normal base_url.</b>
  </p>

  <div class="row" style="margin-top:14px;">
    <div>
      <div class="muted" style="margin-bottom:8px;">Enabled LBs: <b><?= (int)$active_count ?></b></div>
    </div>
  </div>

  <hr class="sep" style="margin:14px 0;">

  <h3 style="margin:0 0 10px;"><?= $edit ? 'Edit LB' : 'Add LB' ?></h3>
  <form method="post" class="lb-form" style="margin-top:8px;">
    <?= csrf_input() ?>
    <input type="hidden" name="save_lb" value="1">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <div class="row">
      <div>
        <label>Name (optional)</label>
        <input type="text" name="name" value="<?= e((string)($edit['name'] ?? '')) ?>" placeholder="LB #1" maxlength="190">
      </div>
      <div class="wide">
        <label>Base URL (required)</label>
        <input type="text" name="base_url" value="<?= e((string)($edit['base_url'] ?? '')) ?>" placeholder="https://lb1.yourdomain.com" required>
        <div class="muted" style="margin-top:6px;">No trailing slash. Must be http or https.</div>
      </div>
    </div>

    <div class="row" style="margin-top:10px;">
      <div>
        <label>Weight</label>
        <input type="number" name="weight" value="<?= (int)($edit['weight'] ?? 1) ?>" min="1" step="1">
        <div class="muted" style="margin-top:6px;">Higher weight = more users mapped to this LB.</div>
      </div>
      <div class="wide">
        <label>Notes (optional)</label>
        <input type="text" name="notes" value="<?= e((string)($edit['notes'] ?? '')) ?>" placeholder="Datacenter / provider / etc" maxlength="255">
      </div>
    </div>

    <div style="margin-top:10px;">
      <label style="display:inline-flex;gap:8px;align-items:center;">
        <input type="checkbox" name="enabled" <?= ((int)($edit['enabled'] ?? 1) === 1) ? 'checked' : '' ?>>
        Enabled
      </label>
    </div>

    <div style="margin-top:12px;display:flex;gap:10px;align-items:center;">
      <button class="xt-btn xt-btn-primary" type="submit"><?= $edit ? 'Update LB' : 'Add LB' ?></button>
      <?php if ($edit): ?>
        <a class="xt-btn xt-btn-primary" href="lb_servers.php">Cancel</a>
      <?php endif; ?>
    </div>
  </form>

  <hr class="sep" style="margin:16px 0;">

  <h3 style="margin:0 0 10px;">Current LBs</h3>

  <?php if (!$lbs): ?>
    <div class="muted">No load balancers configured.</div>
  <?php else: ?>
    <div class="table-wrap" style="overflow:auto;">
      <table class="table lb-table">
        <thead>
          <tr>
            <th class="col-id">ID</th>
            <th class="col-name">Name</th>
            <th>Base URL</th>
            <th class="col-weight">Weight</th>
            <th class="col-status">Status</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lbs as $r): ?>
            <tr>
              <td class="col-id"><?= (int)$r['id'] ?></td>
              <td class="col-name"><?= e((string)($r['name'] ?? '')) ?></td>
              <td><?= e((string)$r['base_url']) ?></td>
              <td class="col-weight"><?= (int)($r['weight'] ?? 1) ?></td>
              <td>
                <?php if ((int)($r['enabled'] ?? 0) === 1): ?>
                  <span class="chip chip-green">Enabled</span>
                <?php else: ?>
                  <span class="chip">Disabled</span>
                <?php endif; ?>
              </td>
              <td class="col-actions"><div class="lb-actions">
                <a class="xt-btn" href="lb_servers.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                <form method="post" style="display:inline;">
                  <?= csrf_input() ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="xt-btn" type="submit" name="toggle_lb" value="1"><?= ((int)($r['enabled'] ?? 0) === 1) ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this LB?');">
                  <?= csrf_input() ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="xt-btn" type="submit" name="delete_lb" value="1">Delete</button>
                </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="muted" style="margin-top:12px;">
    Tip: you can run unlimited LBs. The panel maps users to an LB using a stable hash.
  </div>
</div>

</body>
</html>
