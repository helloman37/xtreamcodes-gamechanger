<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();
$pdo=db();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_validate();
  if (isset($_POST['add_source'])) {
    $pdo->prepare("INSERT INTO epg_sources (name,xmltv_url,enabled) VALUES (?,?,?)")
        ->execute([trim($_POST['name']), trim($_POST['xmltv_url']), (int)($_POST['enabled']??1)]);
    flash_set("EPG source added","success");
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
$channels=$pdo->query("SELECT id,name,tvg_id,tvg_name,epg_url FROM channels ORDER BY name")->fetchAll();
$topbar = file_get_contents(__DIR__ . '/topbar.html');
?>
<!doctype html>
<html>
<head>
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
    <div style="margin-top:12px;">
      <button>Add Source</button>
    </div>
  </form>
</div>

<br>

<div class="card">
  <table>
    <tr><th>Name</th><th>URL</th><th>Enabled</th></tr>
    <?php foreach($sources as $s): ?>
    <tr>
      <td><?=e($s['name'])?></td>
      <td class="code"><?=e($s['xmltv_url'])?></td>
      <td><?=$s['enabled']?'<span class="pill good">ON</span>':'<span class="pill bad">OFF</span>'?></td>
      <td>
        <form method="post" style="display:inline-block;margin:0;">
          <?=csrf_input()?>
          <input type="hidden" name="toggle_source" value="1">
          <input type="hidden" name="source_id" value="<?=$s['id']?>">
          <button class="btn" type="submit"><?=$s['enabled']?'Disable':'Enable'?></button>
        </form>
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
