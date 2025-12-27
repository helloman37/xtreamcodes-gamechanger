<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();
$pdo=db();

$default_rules = "# Format: REGION=keyword1,keyword2\n"
  . "USA=usa,.us,united states,us|\n"
  . "Canada=canada,.ca\n"
  . "UK=uk,.uk,united kingdom\n"
  . "Australia=australia,.au\n"
  . "Asia=asia,asia|,hk,sg,ph,my,th,jp,kr\n"
  . "India=india,.in\n"
  . "Europe=europe,eu\n"
  . "Latin=latin,latam\n"
  . "Africa=africa\n"
  . "Middle East=middle east,mena,uae,saudi,qatar\n";

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_validate();
  if (isset($_POST['add_source'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $url  = trim((string)($_POST['xmltv_url'] ?? ''));
    $enabled = (int)($_POST['enabled'] ?? 1);
    $rules = trim((string)($_POST['region_rules'] ?? ''));
    $ttl = (int)($_POST['cache_ttl'] ?? 21600);
    if ($ttl <= 0) $ttl = 21600;

    try {
      $pdo->prepare("INSERT INTO epg_sources (name,xmltv_url,enabled,region_rules,cache_ttl) VALUES (?,?,?,?,?)")
          ->execute([$name, $url, $enabled, ($rules !== '' ? $rules : null), $ttl]);
    } catch (Throwable $e) {
      // Back-compat: older schema
      $pdo->prepare("INSERT INTO epg_sources (name,xmltv_url,enabled) VALUES (?,?,?)")
          ->execute([$name, $url, $enabled]);
    }
    flash_set("EPG source added","success");
  }

  if (isset($_POST['update_source'])) {
    $id = (int)($_POST['source_id'] ?? 0);
    if ($id > 0) {
      $name = trim((string)($_POST['name'] ?? ''));
      $url  = trim((string)($_POST['xmltv_url'] ?? ''));
      $rules = trim((string)($_POST['region_rules'] ?? ''));
      $ttl = (int)($_POST['cache_ttl'] ?? 21600);
      if ($ttl <= 0) $ttl = 21600;
      try {
        $pdo->prepare("UPDATE epg_sources SET name=?, xmltv_url=?, region_rules=?, cache_ttl=? WHERE id=?")
            ->execute([$name, $url, ($rules !== '' ? $rules : null), $ttl, $id]);
      } catch (Throwable $e) {
        $pdo->prepare("UPDATE epg_sources SET name=?, xmltv_url=? WHERE id=?")
            ->execute([$name, $url, $id]);
      }
      flash_set("EPG source updated","success");
    }
  }
  if (isset($_POST['map_channel'])) {
    $pdo->prepare("UPDATE channels SET tvg_id=?, tvg_name=?, epg_url=? WHERE id=?")
        ->execute([
          trim($_POST['tvg_id']),
          trim($_POST['tvg_name']),
          trim($_POST['epg_url']) ?: null,
          (int)$_POST['channel_id']
        ]);
    flash_set("Channel EPG mapping updated","success");
  }

  if (isset($_POST['delete_source'])) {
    $id = (int)($_POST['source_id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("DELETE FROM epg_sources WHERE id=?")->execute([$id]);
      flash_set("EPG source deleted","success");
    }
  }
  if (isset($_POST['toggle_source'])) {
    $id = (int)($_POST['source_id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("UPDATE epg_sources SET enabled = IF(enabled=1,0,1) WHERE id=?")->execute([$id]);
      flash_set("EPG source toggled","success");
    }
  }

  header("Location: epg_manager.php"); exit;
}

$sources=$pdo->query("SELECT * FROM epg_sources ORDER BY created_at DESC")->fetchAll();
$channels=$pdo->query("SELECT c.id,c.name,c.tvg_id,c.tvg_name,c.epg_url, IFNULL(cat.sort_order, 999999) AS cat_sort, IFNULL(c.sort_order, c.id) AS ch_sort FROM channels c LEFT JOIN categories cat ON cat.id=c.category_id ORDER BY cat_sort, ch_sort, c.id")->fetchAll();
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
  <title>EPG Manager</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>EPG Sources</h2>
  <?php flash_show(); ?>

  <form method="post">
    <input type="hidden" name="add_source" value="1">
    <?=csrf_input()?>
    <div class="row">
      <div>
        <label>Name</label>
        <input name="name" required>
      </div>
      <div>
        <label>XMLTV URL</label>
        <input name="xmltv_url" required>
      </div>
      <div style="flex:0;">
        <label style="visibility:hidden">Enabled</label>
        <label><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
      </div>
    </div>

    <div class="row" style="margin-top:10px;">
      <div style="flex:1;">
        <label>Region rules (optional)</label>
        <textarea name="region_rules" rows="6" style="width:100%;" placeholder="REGION=keyword1,keyword2&#10;USA=usa,.us,united states"><?=e($default_rules)?></textarea>
        <div class="muted" style="margin-top:6px; font-size:12px;">Used for <code>xmltv.php?region=USA</code> filtering when proxying an upstream XMLTV.</div>
      </div>
      <div style="flex:0; min-width:200px;">
        <label>Cache TTL (seconds)</label>
        <input name="cache_ttl" type="number" min="60" value="21600">
        <div class="muted" style="margin-top:6px; font-size:12px;">Default: 21600 (6 hours)</div>
      </div>
    </div>
    <div style="margin-top:12px;">
      <button>Add Source</button>
    </div>
  </form>
</div>

<br>

<div class="card">
  <table>
    <tr><th>Name</th><th>URL</th><th>TTL</th><th>Enabled</th><th>Actions</th></tr>
    <?php foreach($sources as $s): ?>
    <tr>
      <td><?=e($s['name'])?></td>
      <td class="code"><?=e($s['xmltv_url'])?></td>
      <td class="code"><?=e((string)($s['cache_ttl'] ?? 21600))?></td>
      <td><?=$s['enabled']?'<span class="pill good">ON</span>':'<span class="pill bad">OFF</span>'?></td>
      <td>
        <form method="post" style="display:inline-block;margin:0;">
          <?=csrf_input()?>
          <input type="hidden" name="toggle_source" value="1">
          <input type="hidden" name="source_id" value="<?=$s['id']?>">
          <button class="btn" type="submit"><?=$s['enabled']?'Disable':'Enable'?></button>
        </form>
        <details style="display:inline-block; margin-left:8px;">
          <summary class="btn" style="cursor:pointer;">Edit</summary>
          <div style="padding:10px; border:1px solid var(--line); border-radius:12px; margin-top:8px; background:rgba(255,255,255,.03); min-width:520px;">
            <form method="post" style="margin:0;">
              <?=csrf_input()?>
              <input type="hidden" name="update_source" value="1">
              <input type="hidden" name="source_id" value="<?=$s['id']?>">
              <div class="row">
                <div>
                  <label>Name</label>
                  <input name="name" value="<?=e($s['name'])?>" required>
                </div>
                <div>
                  <label>XMLTV URL</label>
                  <input name="xmltv_url" value="<?=e($s['xmltv_url'])?>" required>
                </div>
                <div style="flex:0; min-width:180px;">
                  <label>Cache TTL</label>
                  <input name="cache_ttl" type="number" min="60" value="<?=e((string)($s['cache_ttl'] ?? 21600))?>">
                </div>
              </div>
              <div class="row" style="margin-top:10px;">
                <div style="flex:1;">
                  <label>Region rules</label>
                  <textarea name="region_rules" rows="6" style="width:100%;"><?=e((string)($s['region_rules'] ?? $default_rules))?></textarea>
                </div>
              </div>
              <div style="margin-top:10px; display:flex; gap:10px; align-items:center;">
                <button class="btn" type="submit">Save</button>
                <span class="muted" style="font-size:12px;">Example: <code>xmltv.php?username=USER&password=PASS&region=USA</code></span>
              </div>
            </form>
          </div>
        </details>
        <form method="post" style="display:inline-block;margin:0;" onsubmit="return confirm('Delete this EPG source?');">
          <?=csrf_input()?>
          <input type="hidden" name="delete_source" value="1">
          <input type="hidden" name="source_id" value="<?=$s['id']?>">
          <button class="btn danger" type="submit">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<br>

<div class="card">
  <h2>Run Import</h2>
  <p class="muted">Runs the XMLTV importer now (admin only). Opens in a new tab.</p>
  <div class="row" style="align-items:center; gap:12px;">
    <a class="btn" href="epg_import.php?flush=1" target="_blank" rel="noopener" onclick="return confirm('This will replace the currently imported EPG with the latest source data. Continue?')">Run Import (replace old)</a>
    <a class="btn" href="epg_import.php?flush=0" target="_blank" rel="noopener" style="background:#2b3545;">Append (advanced)</a>
  </div>
</div>

<hr>

<div class="card">
  <h2>Channel EPG Mapping</h2>
  <p class="muted">Set tvg-id / tvg-name to match XMLTV channel ids. Optionally override EPG URL per channel.</p>
  <table>
    <tr><th>Channel</th><th>tvg-id</th><th>tvg-name</th><th>EPG URL override</th><th>Save</th></tr>
    <?php foreach($channels as $c): ?>
    <tr>
      <form method="post">
        <input type="hidden" name="map_channel" value="1">
        <?=csrf_input()?>
        <input type="hidden" name="channel_id" value="<?=$c['id']?>">
        <td><?=e($c['name'])?></td>
        <td><input name="tvg_id" value="<?=e($c['tvg_id'])?>"></td>
        <td><input name="tvg_name" value="<?=e($c['tvg_name'])?>"></td>
        <td><input name="epg_url" value="<?=e($c['epg_url'])?>"></td>
        <td><button>Save</button></td>
      </form>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
