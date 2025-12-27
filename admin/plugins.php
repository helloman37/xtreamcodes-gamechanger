<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../plugins_core.php';
require_once __DIR__ . '/../licensebox_client.php';
require_admin();

$pdo = db();
gc_plugins_db_init($pdo);
gc_plugins_ensure_dir();
if (function_exists('gc_plugins_sync_fs')) { gc_plugins_sync_fs($pdo); }

$plugins_dir = gc_plugins_dir();
$can_write = is_writable(dirname($plugins_dir)) || is_writable($plugins_dir) || (is_dir($plugins_dir) && is_writable($plugins_dir));

$lb_cfg = function_exists('lb_cfg') ? lb_cfg() : [];
$lb_has_key = (is_array($lb_cfg) && !empty($lb_cfg['api_key'] ?? '')); // external key (verify/check_update/download_update)
$lb_has_internal_key = (is_array($lb_cfg) && !empty($lb_cfg['internal_api_key'] ?? '')); // internal key (get_products)
$lb_products_json_url = (is_array($lb_cfg) ? trim((string)($lb_cfg['products_json_url'] ?? '')) : '');
$lb_has_products_json_url = ($lb_products_json_url !== '');

$lb_products_cfg = (is_array($lb_cfg) && isset($lb_cfg['products']) && is_array($lb_cfg['products'])) ? $lb_cfg['products'] : [];

// Normalize overrides into a map keyed by product_id (so we can merge with get_products)
$lb_overrides_by_pid = [];
foreach ($lb_products_cfg as $k => $p) {
  if (!is_array($p)) continue;
  $pid = (string)($p['product_id'] ?? '');
  if ($pid === '' && preg_match('/^[a-fA-F0-9]{6,64}$/', (string)$k)) $pid = (string)$k;
  if ($pid === '') continue;
  $p['product_id'] = $pid;
  $lb_overrides_by_pid[$pid] = $p;
}

$lb_products = [];
$lb_store_source = 'config';
$lb_store_error = '';

// Auto-populate store from:
//  - licensebox.products_json_url (GET JSON)
//  - OR LicenseBox internal API (api/get_products)
if (($lb_has_products_json_url || $lb_has_internal_key) && function_exists('lb_get_products_cached') && function_exists('lb_products_from_get_products')) {
  $r = lb_get_products_cached(300);
  if (!empty($r['ok'])) {
    $auto = lb_products_from_get_products($r);
    if ($auto) {
      $lb_store_source = 'licensebox';
      foreach ($auto as $pid => $prod) {
        $ov = $lb_overrides_by_pid[$pid] ?? [];
        $merged = array_replace($prod, $ov);
        if (empty($merged['current_version'])) $merged['current_version'] = '0.0.0';
        if (empty($merged['download_filename'])) $merged['download_filename'] = 'product-' . $pid . '.zip';
        if (empty($merged['name'])) $merged['name'] = (string)($merged['store_name'] ?? ('Product ' . $pid));
        $lb_products[$pid] = $merged;
      }
    } else {
      $keys = '';
      $j = $r['json'] ?? null;
      if (is_array($j)) {
        $ak = array_keys($j);
        if ($ak) $keys = ' Keys: ' . implode(', ', array_slice($ak, 0, 12)) . (count($ak) > 12 ? '…' : '');
      }
      $lb_store_error = 'No products found in LicenseBox response (HTTP ' . (int)($r['http'] ?? 0) . ').' . $keys;
      if (!empty($_GET['lb_debug'])) {
        $body = (string)($r['body'] ?? '');
        $lb_store_error .= ' Debug body: ' . substr(preg_replace('/\s+/', ' ', trim($body)), 0, 220);
      }
    }
  } else {
    $lb_store_error = 'LicenseBox get_products failed (HTTP ' . (int)($r['http'] ?? 0) . ').';
  }
}

// Fallback: use configured products if no auto list
if (!$lb_products && $lb_overrides_by_pid) {
  $lb_products = $lb_overrides_by_pid;
}


function gc_zip_find_manifest(ZipArchive $zip): array {
  // Accept either:
  //  - <plugin_id>/manifest.json
  //  - plugins/<plugin_id>/manifest.json
  $candidates = [];
  for ($i=0; $i<$zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    $name = str_replace('\\', '/', $name);
    if (preg_match('~^plugins/([^/]+)/manifest\.json$~i', $name, $m)) {
      return ['plugin_id' => $m[1], 'root_prefix' => 'plugins/' . $m[1] . '/', 'manifest_path' => $name];
    }
    if (preg_match('~^([^/]+)/manifest\.json$~i', $name, $m)) {
      $candidates[] = ['plugin_id' => $m[1], 'root_prefix' => $m[1] . '/', 'manifest_path' => $name];
    }
  }
  if ($candidates) return $candidates[0];
  return [];
}

function gc_extract_plugin_zip(ZipArchive $zip, string $root_prefix, string $dest_dir): void {
  // Extract only files under $root_prefix into $dest_dir.
  for ($i=0; $i<$zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    $name = str_replace('\\', '/', $name);
    if (!gc_str_starts_with($name, $root_prefix)) continue;
    $rel = substr($name, strlen($root_prefix));
    if ($rel === '') continue;
    if (gc_str_contains($rel, '..')) continue;
    if (gc_str_starts_with($rel, '/')) continue;

    $target = $dest_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    // directories
    if (gc_str_ends_with($name, '/')) {
      @mkdir($target, 0775, true);
      continue;
    }
    @mkdir(dirname($target), 0775, true);
    $stream = $zip->getStream($name);
    if (!$stream) continue;
    $out = fopen($target, 'wb');
    if ($out) {
      stream_copy_to_stream($stream, $out);
      fclose($out);
    }
    fclose($stream);
  }
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $pid = preg_replace('~[^a-zA-Z0-9_-]~', '', (string)($_POST['plugin_id'] ?? ''));

  try {
    if ($action === 'upload') {
      if (!$can_write) throw new RuntimeException('Plugins folder is not writable. Fix permissions for /plugins and try again.');
      if (!isset($_FILES['plugin_zip']) || ($_FILES['plugin_zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
      }
      $tmp = $_FILES['plugin_zip']['tmp_name'];
      $zip = new ZipArchive();
      if ($zip->open($tmp) !== true) throw new RuntimeException('Could not open ZIP.');
      $found = gc_zip_find_manifest($zip);
      if (!$found) throw new RuntimeException('manifest.json missing. ZIP must contain <plugin_id>/manifest.json (or plugins/<plugin_id>/manifest.json).');

      $plugin_id = preg_replace('~[^a-zA-Z0-9_-]~', '', (string)($found['plugin_id'] ?? ''));
      if ($plugin_id === '') throw new RuntimeException('Invalid plugin id in ZIP.');

      $dest = $plugins_dir . DIRECTORY_SEPARATOR . $plugin_id;
      if (is_dir($dest)) {
        // Overwrite/update
        gc_rrmdir($dest);
      }
      @mkdir($dest, 0775, true);
      gc_extract_plugin_zip($zip, $found['root_prefix'], $dest);
      $zip->close();

      // Validate manifest after extraction
      $manifest = gc_plugin_manifest_load($plugin_id);
      gc_plugin_upsert($pdo, $plugin_id, $manifest, 0);

      flash_set('Plugin installed: ' . $manifest['name'] . ' (disabled by default).', 'ok');
      header('Location: plugins.php');
      exit;
    }


if ($action === 'lb_install') {
  if (!$can_write) throw new RuntimeException('Plugins folder is not writable. Fix permissions for /plugins and try again.');

  $email = trim((string)($_POST['lb_email'] ?? ''));
  $license = trim((string)($_POST['lb_license'] ?? ''));
  $choice = (string)($_POST['lb_product'] ?? 'auto');

  if ($email === '' || $license === '') throw new RuntimeException('Email + license are required.');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');

  if (!$lb_has_key) throw new RuntimeException('LicenseBox API key missing. Set licensebox.api_key in config.local.php or env LB_API_KEY.');

  $products = $lb_products;
  if (!$products) throw new RuntimeException('No LicenseBox products configured.');

  $selected = null;

  if ($choice !== '' && $choice !== 'auto' && isset($products[$choice]) && is_array($products[$choice])) {
    $selected = $products[$choice];
  } else {
    // Auto-detect: find the product that matches this license+email
    foreach ($products as $slug => $prod) {
      if (!is_array($prod)) continue;
      $pid2 = (string)($prod['product_id'] ?? '');
      if ($pid2 === '') continue;
      $v = lb_verify_product($pid2, $license, $email);
      $j = $v['json'] ?? null;
      if (is_array($j) && !empty($j['status'])) { $selected = $prod; $selected['_slug'] = $slug; break; }
    }
  }

  if (!$selected) throw new RuntimeException('License not found for any configured product (or email mismatch).');

  $product_id = (string)($selected['product_id'] ?? '');
  $current_version = (string)($selected['current_version'] ?? '0.0.0');
  if ($current_version === '') $current_version = '0.0.0';
  $dl_name = (string)($selected['download_filename'] ?? 'plugin.zip');

  // Ask LicenseBox for the update_id (force by using a low current_version).
  $chk = lb_check_update($product_id, $current_version, $license, $email);
  $j = $chk['json'] ?? null;
  $update_id = is_array($j) ? (string)($j['update_id'] ?? '') : '';

  if ($update_id === '') {
    // Helpful hint: if you're truly on latest, LicenseBox returns update_id null.
    // Set current_version lower (e.g., 0.0.0) or publish a newer update in LicenseBox.
    $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
    throw new RuntimeException('No update_id detected. ' . ($msg ? $msg . ' ' : '') . 'Set current_version to 0.0.0 for downloads or publish an update for this product in LicenseBox.');
  }

  // Download the update ZIP bytes from LicenseBox.
  $dl = lb_download_update_main($update_id, $license, $email);
  if ((int)($dl['http'] ?? 0) !== 200 || empty($dl['body'])) {
    $http = (int)($dl['http'] ?? 0);
    if ($http === 401) throw new RuntimeException('LicenseBox rejected download (401). Usually: update period ended or email/license mismatch.');
    if ($http === 404) throw new RuntimeException('LicenseBox download not found (404). Wrong update_id or update file missing.');
    throw new RuntimeException('LicenseBox download failed (HTTP ' . $http . ').');
  }
  $bytes = (string)$dl['body'];
  if (!lb_is_zip($bytes)) {
    $preview = preg_replace('/[^\x20-\x7E]/', '', substr($bytes, 0, 200));
    throw new RuntimeException('LicenseBox did not return a ZIP. ' . ($preview ? ('Response: ' . $preview) : ''));
  }

  $tmpFile = tempnam(sys_get_temp_dir(), 'lbzip_');
  file_put_contents($tmpFile, $bytes);

  $zip = new ZipArchive();
  if ($zip->open($tmpFile) !== true) {
    @unlink($tmpFile);
    throw new RuntimeException('Could not open downloaded ZIP.');
  }

  $found = gc_zip_find_manifest($zip);
  if (!$found) {
    $zip->close();
    @unlink($tmpFile);
    throw new RuntimeException('Downloaded ZIP is not a plugin package (manifest.json missing).');
  }

  $plugin_id = preg_replace('~[^a-zA-Z0-9_-]~', '', (string)($found['plugin_id'] ?? ''));
  if ($plugin_id === '') {
    $zip->close();
    @unlink($tmpFile);
    throw new RuntimeException('Invalid plugin id in ZIP.');
  }

  $dest = $plugins_dir . DIRECTORY_SEPARATOR . $plugin_id;
  if (is_dir($dest)) gc_rrmdir($dest);
  @mkdir($dest, 0775, true);
  gc_extract_plugin_zip($zip, (string)$found['root_prefix'], $dest);
  $zip->close();
  @unlink($tmpFile);

  $manifest = gc_plugin_manifest_load($plugin_id);
  gc_plugin_upsert($pdo, $plugin_id, $manifest, 0);

  flash_set('Plugin installed from LicenseBox: ' . ($manifest['name'] ?? $plugin_id) . ' (disabled by default).', 'ok');
  header('Location: plugins.php');
  exit;
}


if ($action === 'lb_download') {
  $email = trim((string)($_POST['lb_email'] ?? ''));
  $license = trim((string)($_POST['lb_license'] ?? ''));
  $choice = (string)($_POST['lb_product'] ?? 'auto');

  if ($email === '' || $license === '') throw new RuntimeException('Email + license are required.');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');
  if (!$lb_has_key) throw new RuntimeException('LicenseBox API key missing. Set licensebox.api_key in config.local.php or env LB_API_KEY.');

  $products = $lb_products;
  if (!$products) throw new RuntimeException('No LicenseBox products configured.');

  $selected = null;
  if ($choice !== '' && $choice !== 'auto' && isset($products[$choice]) && is_array($products[$choice])) {
    $selected = $products[$choice];
  } else {
    // Auto-detect: find the product that matches this license+email
    foreach ($products as $slug => $prod) {
      if (!is_array($prod)) continue;
      $pid2 = (string)($prod['product_id'] ?? '');
      if ($pid2 === '') continue;
      $v = lb_verify_product($pid2, $license, $email);
      $j = $v['json'] ?? null;
      if (is_array($j) && !empty($j['status'])) { $selected = $prod; $selected['_slug'] = $slug; break; }
    }
  }

  if (!$selected) throw new RuntimeException('License not found for any configured product (or email mismatch).');

  $product_id = (string)($selected['product_id'] ?? '');
  $current_version = (string)($selected['current_version'] ?? '0.0.0');
  if ($current_version === '') $current_version = '0.0.0';
  $dl_name = (string)($selected['download_filename'] ?? 'download.zip');
  $nice_name = (string)($selected['store_name'] ?? $selected['name'] ?? ('Product-' . $product_id));
  if ($dl_name === '' || !preg_match('/\.zip$/i', $dl_name)) {
    $safe = preg_replace('~[^a-zA-Z0-9._-]+~', '-', $nice_name);
    $dl_name = trim($safe, '-') . '.zip';
  }

  // Ask LicenseBox for the update_id (force by using a low current_version).
  $chk = lb_check_update($product_id, $current_version, $license, $email);
  $j = $chk['json'] ?? null;
  $update_id = is_array($j) ? (string)($j['update_id'] ?? '') : '';

  if ($update_id === '') {
    $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
    throw new RuntimeException('No update_id detected. ' . ($msg ? $msg . ' ' : '') . 'Publish an update for this product in LicenseBox (or set current_version to 0.0.0 and ensure an update exists).');
  }

  // Stream the update ZIP to the browser (safe for large downloads).
  if (!function_exists('lb_download_update_stream_to_browser')) {
    throw new RuntimeException('Streaming downloader missing (lb_download_update_stream_to_browser).');
  }

  $r = lb_download_update_stream_to_browser($update_id, $license, $email, $dl_name);
  if (empty($r['ok'])) {
    $http = (int)($r['http'] ?? 0);
    $err  = trim((string)($r['error'] ?? ''));
    throw new RuntimeException('LicenseBox download failed (HTTP ' . $http . '). ' . ($err ?: 'Unknown error.'));
  }
  exit;
}



    if ($action === 'enable' && $pid) {
      // Ensure manifest still valid
      $manifest = gc_plugin_manifest_load($pid);
      gc_plugin_upsert($pdo, $pid, $manifest, 1);
      flash_set('Plugin enabled: ' . ($manifest['name'] ?? $pid), 'ok');
      header('Location: plugins.php');
      exit;
    }
    if ($action === 'disable' && $pid) {
      gc_plugin_set_enabled($pdo, $pid, 0);
      flash_set('Plugin disabled: ' . $pid, 'ok');
      header('Location: plugins.php');
      exit;
    }
    if ($action === 'uninstall' && $pid) {
      if (!$can_write) throw new RuntimeException('Plugins folder is not writable. Fix permissions for /plugins and try again.');
      gc_plugin_set_enabled($pdo, $pid, 0);
      $dest = $plugins_dir . DIRECTORY_SEPARATOR . $pid;
      gc_rrmdir($dest);
      $stmt = $pdo->prepare('DELETE FROM plugin_settings WHERE plugin_id=:id');
      $stmt->execute([':id'=>$pid]);
      $stmt = $pdo->prepare('DELETE FROM plugins WHERE id=:id');
      $stmt->execute([':id'=>$pid]);
      flash_set('Plugin uninstalled: ' . $pid, 'ok');
      header('Location: plugins.php');
      exit;
    }
  } catch (Throwable $t) {
    flash_set($t->getMessage(), 'err');
    header('Location: plugins.php');
    exit;
  }
}

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

$plugins = gc_plugins_list($pdo);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Plugins</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<h1>Plugins</h1>
<?php flash_show(); ?>


<style>
  .gc-store-toolbar{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:10px}
  .gc-store-grid{display:flex;flex-wrap:nowrap;gap:12px;margin-top:12px;overflow-x:auto;overflow-y:hidden;padding-bottom:10px;-webkit-overflow-scrolling:touch;scroll-snap-type:x mandatory;scrollbar-gutter:stable}
  .gc-store-item{border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px;background:rgba(0,0,0,.12);flex:0 0 360px;scroll-snap-align:start}
  .gc-store-item h3{margin:0 0 6px 0;font-size:16px}
  .gc-store-meta{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 10px 0}
  .gc-store-meta .pill{font-size:12px}
  .gc-store-desc{min-height:34px}
  .gc-store-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}
  .gc-store-actions .btn{white-space:nowrap}
  @media (max-width: 740px){ .gc-store-item{flex-basis:85vw;} }


  .gc-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;}
  .gc-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.65);}
	  .gc-modal-card{
		position:relative;
		max-width:820px;
		width:calc(100% - 24px);
		background:rgba(18,18,18,.98);
		border:1px solid rgba(255,255,255,.10);
		border-radius:16px;
		padding:14px;
		box-shadow:0 18px 60px rgba(0,0,0,.6);
		/* Panel is light-theme by default; modal is dark, so force readable text */
		color:#e5e7eb;
	  }
	  .gc-modal-card h1,
	  .gc-modal-card h2,
	  .gc-modal-card h3,
	  .gc-modal-card h4{color:#fff;}
	  .gc-modal-card .muted{color:rgba(229,231,235,.78) !important;}
	  .gc-modal-card .pill{
		background:rgba(255,255,255,.10);
		border:1px solid rgba(255,255,255,.14);
		color:#f8fafc;
	  }
	  .gc-modal-card .pill.gray{background:rgba(255,255,255,.06);}
	  /* Make code chips readable on dark without blinding white */
	  .gc-modal-card .code{
		background:rgba(255,255,255,.07);
		border:1px dashed rgba(255,255,255,.16);
		color:#fff;
	  }
	  .gc-modal-card .gc-buy-grid > div:first-child{
		background:rgba(255,255,255,.03);
		border:1px solid rgba(255,255,255,.06);
		border-radius:14px;
		padding:12px;
	  }
	  .gc-modal-card #gcBuyPrice{font-size:22px;font-weight:1000;letter-spacing:.02em;}
	  .gc-modal-card #gcBuyCashtag{font-size:14px;}
	  .gc-modal-card .gc-modal-head h3{font-size:18px;}
  .gc-modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;}
  .gc-buy-grid{display:grid;grid-template-columns:1fr 280px;gap:14px;align-items:center;}
  .gc-buy-line{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  .gc-qr-wrap{display:inline-block;padding:10px;border-radius:14px;background:#fff;border:1px solid rgba(0,0,0,.1);}
  @media (max-width: 740px){ .gc-buy-grid{grid-template-columns:1fr;} .gc-qr-wrap img{width:220px;height:220px;} }
</style>

<div class="card" style="margin:14px 0;" id="plugin-store">
  <h2>Plugin Store</h2>
  <p class="muted" style="margin-top:-6px;">
    Browse items below. Click <b>Buy Plugin</b> to pay. After you receive your LicenseBox code, use the section below to install plugins or download files.
  </p>

  <?php if (!empty($lb_store_error)): ?>
    <p class="pill bad"><?= e((string)$lb_store_error) ?></p>
  <?php endif; ?>

  <?php if (!$lb_products): ?>
    <p class="pill bad">No products found</p>
    <?php if (!$lb_has_internal_key && !$lb_has_products_json_url): ?>
      <p class="muted">To auto-load products, either set <span class="code">LB_PRODUCTS_JSON_URL</span> (or <span class="code">licensebox.products_json_url</span> in <span class="code">config.local.php</span>) <b>OR</b> set <span class="code">LB_INTERNAL_API_KEY</span> (or <span class="code">licensebox.internal_api_key</span>). You can also define products manually under <span class="code">licensebox.products</span>.</p>
    <?php else: ?>
      <?php if ($lb_has_products_json_url): ?>
        <p class="muted">Your <span class="code">LB_PRODUCTS_JSON_URL</span> endpoint returned zero products. Check the JSON shape and that it includes <span class="code">product_id</span>.</p>
      <?php else: ?>
        <p class="muted">LicenseBox returned zero products. Verify the internal API key and that your LicenseBox base URL is correct.</p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!$lb_has_key): ?>
    <p class="pill bad">LicenseBox external API key not set</p>
    <p class="muted">Set <span class="code">LB_API_KEY</span> in your server environment or add <span class="code">licensebox.api_key</span> to <span class="code">config.local.php</span>. Install buttons will be disabled until the key is set.</p>
  <?php endif; ?>
  <div class="gc-store-toolbar">
    <div>
      <label class="muted" style="display:block; margin-bottom:4px;">Search</label>
      <input class="input" id="gcStoreSearch" type="text" placeholder="Search plugins…" style="min-width:220px;">
    </div>
</div>

  <div class="gc-store-grid" id="gcStoreGrid">
    <?php foreach ($lb_products as $slug => $prod):
      if (!is_array($prod)) continue;
      $pid2 = (string)($prod['product_id'] ?? '');
      if ($pid2 === '') continue;
      $name = (string)($prod['store_name'] ?? $prod['name'] ?? $slug);
      $desc = (string)($prod['store_description'] ?? $prod['description'] ?? '');
      $cat  = trim((string)($prod['category'] ?? ''));
      $price = trim((string)($prod['price'] ?? ''));
      $badge = trim((string)($prod['badge'] ?? ''));
      $cash = trim((string)($prod['cashapp'] ?? $prod['cashapp_tag'] ?? $prod['cashtag'] ?? $prod['cash_tag'] ?? ''));
      $payurl = trim((string)($prod['pay_url'] ?? $prod['payment_url'] ?? ''));

      $disabled = (!$can_write || !$lb_has_key);
    ?>
      <div class="gc-store-item" data-name="<?= e(strtolower($name)) ?>" data-slug="<?= e(strtolower((string)$slug)) ?>" data-cat="<?= e(strtolower($cat)) ?>" data-title="<?= e($name) ?>" data-price="<?= e($price) ?>" data-cashtag="<?= e($cash) ?>" data-payurl="<?= e($payurl) ?>" data-pid="<?= e($pid2) ?>" data-slugraw="<?= e((string)$slug) ?>">
        <h3><?= e($name) ?></h3>
        <div class="gc-store-meta">
          <?php if ($cat !== ''): ?><span class="pill gray"><?= e($cat) ?></span><?php endif; ?>
          <?php if ($price !== ''): ?><span class="pill"><?= e($price) ?></span><?php endif; ?>
          <?php if ($badge !== ''): ?><span class="pill good"><?= e($badge) ?></span><?php endif; ?>
          <span class="pill gray">ID: <span class="code"><?= e($pid2) ?></span></span>
        </div>
        <div class="muted gc-store-desc"><?= e($desc) ?></div>
        <div class="gc-store-actions">
          <button class="btn gray" type="button" onclick="gcStoreBuy(this)">Buy Plugin</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="muted" style="margin-top:10px;">
    Send your email and product id with payment to receive your license.
  </p>

</div>

<!-- Buy Plugin modal -->
<div id="gcBuyModal" class="gc-modal" style="display:none;">
  <div class="gc-modal-backdrop" onclick="gcBuyClose()"></div>
  <div class="gc-modal-card">
    <div class="gc-modal-head">
      <h3 id="gcBuyTitle" style="margin:0;">Buy Plugin</h3>
      <button class="btn gray" type="button" onclick="gcBuyClose()">Close</button>
    </div>

    <div class="gc-buy-grid" style="margin-top:12px;">
      <div>
        <div class="gc-buy-line">
          <span class="pill gray">CashApp</span>
          <span class="code" id="gcBuyCashtag"></span>
        </div>

        <div class="gc-buy-line" style="margin-top:8px;">
          <span class="pill">Price</span>
          <b id="gcBuyPrice"></b>
        </div>

        <p class="muted" style="margin:10px 0 0 0;">
          Send the payment, then you’ll receive your LicenseBox code. Come back and use the installer below to install the plugin.
        </p>

        <p class="muted" style="margin:8px 0 0 0;">
          Suggested note: <span class="code" id="gcBuyNote"></span>
        </p>

        <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
          <a class="btn" id="gcBuyLink" href="#" target="_blank" rel="noopener">Open CashApp</a>
          <button class="btn gray" type="button" onclick="gcBuyCopy()">Copy Note</button>
        </div>
      </div>

      <div style="text-align:center;">
        <div class="gc-qr-wrap">
          <img id="gcBuyQr" alt="CashApp QR" src="" style="width:240px;height:240px;display:block;">
        </div>
        <p class="muted" style="margin-top:8px;">Scan to pay</p>
      </div>
    </div>
  </div>
</div>

<div class="card" style="margin:14px 0;">
  <h2>Install Plugin from LicenseBox</h2>
  <p class="muted" style="margin-top:-6px;">
    Enter the email + license code used for purchase. This downloads the latest ZIP from LicenseBox and installs it into <span class="code">/plugins/</span> (disabled by default).
  </p>

  <?php if (!$lb_has_key): ?>
    <p class="pill bad">LicenseBox external API key not set</p>
    <p class="muted">Set <span class="code">LB_API_KEY</span> in your server environment or add <span class="code">licensebox.api_key</span> to <span class="code">config.local.php</span>.</p>
  <?php endif; ?>

  <form method="post" id="lbInstallForm" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="action" id="lbAction" value="lb_install">

    <div>
      <label class="muted" style="display:block; margin-bottom:4px;">Email</label>
      <input class="input" id="lbEmail" type="email" name="lb_email" required placeholder="you@example.com" style="min-width:260px;">
    </div>

    <div>
      <label class="muted" style="display:block; margin-bottom:4px;">License Code</label>
      <input class="input" id="lbLicense" type="text" name="lb_license" required placeholder="XXXX-XXXX-XXXX-XXXX" style="min-width:260px;">
    </div>

    <div>
      <label class="muted" style="display:block; margin-bottom:4px;">Product</label>
      <select class="input" id="lbProduct" name="lb_product" style="min-width:220px;">
        <option value="auto" selected>Auto-detect</option>
        <?php foreach ($lb_products as $slug => $prod):
          if (!is_array($prod)) continue;
          $label = $prod['store_name'] ?? ($prod['name'] ?? $slug);
          $pid2 = $prod['product_id'] ?? '';
          if ($pid2 === '') continue;
        ?>
          <option value="<?= e((string)$slug) ?>"><?= e((string)$label) ?> (<?= e((string)$pid2) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <button class="btn" type="submit" onclick="document.getElementById('lbAction').value='lb_install';" <?= ($can_write && $lb_has_key) ? '' : 'disabled' ?>>Download & Install Plugin</button>
    <button class="btn gray" type="submit" onclick="document.getElementById('lbAction').value='lb_download';" <?= ($lb_has_key) ? '' : 'disabled' ?>>Download ZIP</button>
  </form>

  <p class="muted" style="margin-top:10px;">
    Use <b>Download ZIP</b> for non-plugin items (example: Android source code). <a href="https://github.com/helloman37/xtreamcodes-gamechanger/releases/download/v1.app/app.apk" target="_blank" rel="noopener">Download Panel APK</a><span style="opacity:.9;"> — Note: the source code has more updated features. When you buy the source code, you get free updates per license.</span>
  </p>
</div>

<script>

  var GC_CASHTAG = <?php
    $ct = defined('CASHAPP_CASHTAG') ? trim(CASHAPP_CASHTAG) : '$tysonworlds';
    if ($ct !== '' && $ct[0] !== '$') $ct = '$' . $ct;
    echo json_encode($ct ?: '$tysonworlds');
  ?>;

  function gcCashAppUrl(cashtag, price){
    var base = 'https://cash.app/' + encodeURIComponent(cashtag || '$tysonworlds');
    var m = (price || '').toString().match(/(\d+(?:\.\d+)?)/);
    if (m && m[1]) base += '/' + encodeURIComponent(m[1]);
    return base;
  }

  function gcStoreBuy(btn){
    try{
      var item = btn && btn.closest ? btn.closest('.gc-store-item') : null;
      if (!item) return;
      var title = item.getAttribute('data-title') || 'Plugin';
      var price = item.getAttribute('data-price') || '';
      var pid   = item.getAttribute('data-pid') || '';
      var cashtag = item.getAttribute('data-cashtag') || (typeof GC_CASHTAG !== 'undefined' ? GC_CASHTAG : '') || '';
      var payUrl  = item.getAttribute('data-payurl') || '';

      if (cashtag && cashtag.charAt(0) !== '$') cashtag = '$' + cashtag.replace(/^[@$]+/, '');
      if (!payUrl && cashtag) payUrl = 'https://cash.app/' + cashtag;
      if (payUrl && !/^https?:\/\//i.test(payUrl)) payUrl = 'https://' + payUrl.replace(/^\/+/, '');

      var note  = pid ? (title + ' (' + pid + ')') : title;

var cashtagEl = document.getElementById('gcBuyCashtag');
      var titleEl   = document.getElementById('gcBuyTitle');
      var priceEl   = document.getElementById('gcBuyPrice');
      var noteEl    = document.getElementById('gcBuyNote');
      var linkEl    = document.getElementById('gcBuyLink');
      var qrEl      = document.getElementById('gcBuyQr');
      var modal     = document.getElementById('gcBuyModal');

      if (cashtagEl) cashtagEl.textContent = (cashtag || (typeof GC_CASHTAG !== 'undefined' ? GC_CASHTAG : '') || '$tysonworlds');
      if (titleEl) titleEl.textContent = 'Buy: ' + title;
      if (priceEl) priceEl.textContent = price ? price : '—';
      if (noteEl) noteEl.textContent = note;

      var finalUrl = payUrl || gcCashAppUrl((cashtag || (typeof GC_CASHTAG !== 'undefined' ? GC_CASHTAG : '')), price);
      if (linkEl){ linkEl.href = finalUrl; }

      // QR (external image generator)
      if (qrEl){
        qrEl.src = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' + encodeURIComponent(finalUrl);
      }

      if (modal){ modal.style.display = 'flex'; }
    }catch(e){}
  }

  function gcBuyClose(){
    var modal = document.getElementById('gcBuyModal');
    if (modal) modal.style.display = 'none';
  }

  function gcBuyCopy(){
    var noteEl = document.getElementById('gcBuyNote');
    var t = noteEl ? (noteEl.textContent || '') : '';
    if (!t) return;
    if (navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(t).catch(function(){});
    } else {
      var ta = document.createElement('textarea');
      ta.value = t;
      document.body.appendChild(ta);
      ta.select();
      try{ document.execCommand('copy'); }catch(e){}
      document.body.removeChild(ta);
    }
  }

  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape') gcBuyClose();
  });

  function gcStorePrefill(slug){
    var sel = document.getElementById('lbProduct');
    if (sel) sel.value = slug;
    var form = document.getElementById('lbInstallForm');
    if (form) form.scrollIntoView({behavior:'smooth', block:'start'});
  }

  function gcStoreInstall(slug){
    var sel = document.getElementById('lbProduct');
    if (sel) sel.value = slug;
    var email = (document.getElementById('lbEmail')||{}).value || '';
    var lic   = (document.getElementById('lbLicense')||{}).value || '';
    email = email.trim(); lic = lic.trim();
    var form = document.getElementById('lbInstallForm');
    if (!email || !lic){
      if (form) form.scrollIntoView({behavior:'smooth', block:'start'});
      var e = document.getElementById('lbEmail');
      if (e) e.focus();
      alert('Enter purchase email + license below, then click Download & Install Plugin.');
      return;
    }
    try{
      var a = document.getElementById('lbAction');
      if (a) a.value = 'lb_install';
    }catch(e){}
    if (form) form.submit();
  }

  // client-side filter
  function gcStoreFilter(){
    var q = ((document.getElementById('gcStoreSearch')||{}).value||'').toLowerCase().trim();
    var items = document.querySelectorAll('#gcStoreGrid .gc-store-item');
    items.forEach(function(el){
      var name = (el.getAttribute('data-name') || '').toLowerCase();
      var slug = (el.getAttribute('data-slug') || '').toLowerCase();
      var ok = true;
      if (q){ ok = (name.indexOf(q) !== -1) || (slug.indexOf(q) !== -1); }
      el.style.display = ok ? '' : 'none';
    });
  }
  var s = document.getElementById('gcStoreSearch');
  if (s) s.addEventListener('input', gcStoreFilter);
</script>

<div class="card" style="margin:14px 0;">
  <h2>Installed Plugins</h2>

  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>ID</th>
        <th>Version</th>
        <th>Description</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$plugins): ?>
      <tr><td colspan="6" class="muted">No plugins installed.</td></tr>
    <?php endif; ?>
    <?php foreach ($plugins as $p):
      $pid = (string)$p['id'];
      $enabled = (int)($p['enabled'] ?? 0) === 1;
      $manifest = json_decode($p['manifest_json'] ?? '', true);
      if (!is_array($manifest)) $manifest = [];
      $adminPath = $manifest['admin']['path'] ?? null;
      $adminLabel = $manifest['admin']['label'] ?? 'Settings';
      $hasSettings = is_string($adminPath) && $adminPath !== '';
    ?>
      <tr>
        <td><b><?= e($p['name'] ?? $pid) ?></b></td>
        <td><span class="code"><?= e($pid) ?></span></td>
        <td><?= e($p['version'] ?? '') ?></td>
        <td><?= e($p['description'] ?? '') ?></td>
        <td>
          <?php if ($enabled): ?>
            <span class="pill good">Enabled</span>
          <?php else: ?>
            <span class="pill bad">Disabled</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php if ($hasSettings): ?>
              <a class="btn btn-small gray" href="plugin.php?plugin=<?= urlencode($pid) ?>" title="<?= e($adminLabel) ?>"><?= e($adminLabel) ?></a>
            <?php endif; ?>

            <?php if (!$enabled): ?>
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="enable">
                <input type="hidden" name="plugin_id" value="<?= e($pid) ?>">
                <button class="btn btn-small" type="submit">Enable</button>
              </form>
            <?php else: ?>
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="disable">
                <input type="hidden" name="plugin_id" value="<?= e($pid) ?>">
                <button class="btn btn-small gray" type="submit">Disable</button>
              </form>
            <?php endif; ?>

            <form method="post" style="margin:0;" onsubmit="return confirm('Uninstall <?= e($pid) ?>?');">
              <input type="hidden" name="action" value="uninstall">
              <input type="hidden" name="plugin_id" value="<?= e($pid) ?>">
              <button class="btn btn-small danger" type="submit" <?= $can_write ? '' : 'disabled' ?>>Uninstall</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="margin-top:10px;">Routing is handled by <span class="code">/plugins.php</span> + <span class="code">.htaccess</span> patterns.</p>
</div>

</main></div>
</body>
</html>
