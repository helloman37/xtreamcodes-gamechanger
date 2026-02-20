<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();
$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

$BACKUP_DIR = realpath(__DIR__ . '/../storage') . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($BACKUP_DIR)) {
  @mkdir($BACKUP_DIR, 0755, true);
}

/**
 * Recursively add a folder to a ZipArchive.
 */
function zip_add_folder(ZipArchive $zip, string $folderPath, string $zipPrefix, array $excludeRealPaths = []): void {
  $folderPath = rtrim($folderPath, DIRECTORY_SEPARATOR);
  if (!is_dir($folderPath)) return;

  // Normalize exclude paths to forward slashes and ensure no trailing slash
  $ex = [];
  foreach ($excludeRealPaths as $p) {
    if (!$p) continue;
    $p = rtrim(str_replace('\\', '/', (string)$p), '/');
    $ex[] = $p;
  }

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($folderPath, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if ($file->isLink()) continue;

    $real = $file->getPathname();
    $realNorm = rtrim(str_replace('\\', '/', $real), '/');

    // Skip excluded files/dirs
    foreach ($ex as $p) {
      if ($realNorm === $p || (strpos($realNorm, $p . '/') === 0)) {
        continue 2;
      }
    }

    $rel  = $zipPrefix . '/' . ltrim(str_replace($folderPath, '', $real), DIRECTORY_SEPARATOR);
    $rel  = str_replace('\\', '/', $rel);

    if ($file->isDir()) {
      $zip->addEmptyDir($rel);
    } else {
      $zip->addFile($real, $rel);
    }
  }
}

/**
 * Dump full DB into a SQL string.
 */
function db_dump_sql(PDO $pdo): string {
  $out = "";
  $out .= "-- IPTV Panel Backup\n";
  $out .= "-- Created: " . date('c') . "\n\n";
  $out .= "SET NAMES utf8mb4;\n";
  $out .= "SET foreign_key_checks=0;\n\n";

  $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) ?: [];
  foreach ($tables as $t) {
    $t = (string)$t;
    $out .= "\n-- ----------------------------\n";
    $out .= "-- Table: `$t`\n";
    $out .= "-- ----------------------------\n";
    $out .= "DROP TABLE IF EXISTS `$t`;\n";

    $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
    if (!$row) continue;
    // MySQL returns ["Table"=>..., "Create Table"=>...]
    $create = $row['Create Table'] ?? array_values($row)[1] ?? '';
    $out .= $create . ";\n\n";

    // Data
    $stmt = $pdo->query("SELECT * FROM `$t`");
    if (!$stmt) continue;
    $cols = null;
    $batch = [];
    $batchSize = 200;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if ($cols === null) $cols = array_keys($r);
      $vals = [];
      foreach ($cols as $c) {
        $v = $r[$c];
        if ($v === null) {
          $vals[] = "NULL";
        } elseif (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v))) {
          // numeric-ish
          $vals[] = (string)$v;
        } else {
          $vals[] = $pdo->quote((string)$v);
        }
      }
      $batch[] = "(" . implode(",", $vals) . ")";
      if (count($batch) >= $batchSize) {
        $out .= "INSERT INTO `$t` (`" . implode("`,`", $cols) . "`) VALUES\n" . implode(",\n", $batch) . ";\n";
        $batch = [];
      }
    }
    if ($cols !== null && count($batch)) {
      $out .= "INSERT INTO `$t` (`" . implode("`,`", $cols) . "`) VALUES\n" . implode(",\n", $batch) . ";\n";
    }
    $out .= "\n";
  }

  $out .= "\nSET foreign_key_checks=1;\n";
  return $out;
}

/**
 * Split SQL into statements (simple parser, handles quotes + comments).
 */
function sql_split_statements(string $sql): array {
  $stmts = [];
  $buf = '';
  $len = strlen($sql);
  $inStr = false;
  $strCh = '';
  $inLineComment = false;
  $inBlockComment = false;

  for ($i=0; $i<$len; $i++) {
    $ch = $sql[$i];
    $next = ($i+1<$len) ? $sql[$i+1] : '';

    // End line comment
    if ($inLineComment) {
      if ($ch === "\n") $inLineComment = false;
      continue;
    }

    // End block comment
    if ($inBlockComment) {
      if ($ch === '*' && $next === '/') { $inBlockComment = false; $i++; }
      continue;
    }

    // Start comment (only if not in string)
    if (!$inStr) {
      // -- comment
      if ($ch === '-' && $next === '-' ) {
        // MySQL treats "-- " as comment; be lenient and accept "--\t" too.
        $n2 = ($i+2<$len) ? $sql[$i+2] : '';
        if ($n2 === ' ' || $n2 === "\t" || $n2 === "\r" || $n2 === "\n") {
          $inLineComment = true;
          $i += 2;
          continue;
        }
      }
      // # comment
      if ($ch === '#') { $inLineComment = true; continue; }
      // /* block */
      if ($ch === '/' && $next === '*') { $inBlockComment = true; $i++; continue; }
    }

    // String handling
    if ($inStr) {
      $buf .= $ch;
      if ($ch === '\\' && $next !== '') { // escaped char
        $buf .= $next; $i++;
        continue;
      }
      if ($ch === $strCh) { $inStr = false; $strCh = ''; }
      continue;
    } else {
      if ($ch === "'" || $ch === '"') { $inStr = true; $strCh = $ch; $buf .= $ch; continue; }
    }

    // Statement end
    if ($ch === ';') {
      $trim = trim($buf);
      if ($trim !== '') $stmts[] = $trim;
      $buf = '';
      continue;
    }

    $buf .= $ch;
  }
  $trim = trim($buf);
  if ($trim !== '') $stmts[] = $trim;
  return $stmts;
}

/**
 * Restore DB from SQL file content.
 */
function db_restore_from_sql(PDO $pdo, string $sql): void {
  set_time_limit(0);
  $pdo->exec("SET foreign_key_checks=0");
  $stmts = sql_split_statements($sql);
  foreach ($stmts as $s) {
    $s = trim($s);
    if ($s === '') continue;
    $pdo->exec($s);
  }
  $pdo->exec("SET foreign_key_checks=1");
}

// Handle download
if (isset($_GET['download'])) {
  $name = basename((string)$_GET['download']);
  $path = $BACKUP_DIR . DIRECTORY_SEPARATOR . $name;
  if (!is_file($path)) { http_response_code(404); die("Not found"); }
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="'.$name.'"');
  header('Content-Length: '.filesize($path));
  readfile($path);
  exit;
}

// Handle delete (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_backup') {
  csrf_validate();
  $name = basename((string)($_POST['file'] ?? ''));
  $path = $BACKUP_DIR . DIRECTORY_SEPARATOR . $name;
  if ($name && is_file($path)) {
    @unlink($path);
    flash_set("Backup deleted: {$name}", 'ok');
  }
  header("Location: backup_restore.php");
  exit;
}

// Create backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_backup') {
  csrf_validate();

  
  @set_time_limit(0);
  @ignore_user_abort(true);
$includeStorage = !empty($_POST['include_storage']);
  $includeConfig  = !empty($_POST['include_config']);

  
  $includeFiles   = !empty($_POST['include_files']);

  // A full panel backup already contains config and storage.
  if ($includeFiles) { $includeStorage = false; $includeConfig = false; }
if (!class_exists('ZipArchive')) {
    flash_set("ZipArchive is not available on this server (PHP zip extension).", 'err');
    header("Location: backup_restore.php");
    exit;
  }

  $ts = date('Ymd_His');
  $file = $includeFiles ? "backup_full_{$ts}.zip" : "backup_{$ts}.zip";
  $path = $BACKUP_DIR . DIRECTORY_SEPARATOR . $file;

  $tmpSql = tempnam(sys_get_temp_dir(), 'db_');
  file_put_contents($tmpSql, db_dump_sql($pdo));

  $zip = new ZipArchive();
  if ($zip->open($path, ZipArchive::CREATE) !== true) {
    @unlink($tmpSql);
    flash_set("Could not create backup zip.", 'err');
    header("Location: backup_restore.php");
    exit;
  }

  $manifest = [
    'type' => 'iptv_panel_backup',
    'created_at' => date('c'),
    'includes' => [
      'database' => true,
      'panel_files' => (bool)$includeFiles,
      'storage' => (bool)$includeStorage,
      'config_local' => (bool)$includeConfig,
    ],
  ];
  $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  $zip->addFile($tmpSql, 'database.sql');


  if ($includeFiles) {
    $basePath = realpath(__DIR__ . '/..');
    $exclude  = [ realpath($BACKUP_DIR) ];
    if ($basePath && is_dir($basePath)) {
      zip_add_folder($zip, $basePath, 'files', $exclude);
    }
  }

  if ($includeConfig) {
    $cfgLocal = __DIR__ . '/../config.local.php';
    if (is_file($cfgLocal)) $zip->addFile($cfgLocal, 'config.local.php');
  }

  if ($includeStorage) {
    $storagePath = realpath(__DIR__ . '/../storage');
    if ($storagePath && is_dir($storagePath)) {
      zip_add_folder($zip, $storagePath, 'storage');
    }
  }

  $zip->close();
  @unlink($tmpSql);

  flash_set("Backup created: {$file}", 'ok');
  header("Location: backup_restore.php");
  exit;
}

// Restore backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_backup') {
  csrf_validate();
  
  @set_time_limit(0);
  @ignore_user_abort(true);
if (!class_exists('ZipArchive')) {
    flash_set("ZipArchive is not available on this server (PHP zip extension).", 'err');
    header("Location: backup_restore.php");
    exit;
  }

  $restoreDb      = !empty($_POST['restore_db']);
  $restoreStorage = !empty($_POST['restore_storage']);
  $restoreConfig  = !empty($_POST['restore_config']);

  
  $restoreFiles   = !empty($_POST['restore_files']);

  // A full panel restore already includes storage and config.
  if ($restoreFiles) { $restoreStorage = false; $restoreConfig = false; }
if (empty($_FILES['backup_zip']['tmp_name']) || !is_uploaded_file($_FILES['backup_zip']['tmp_name'])) {
    flash_set("Please choose a backup zip to restore.", 'err');
    header("Location: backup_restore.php");
    exit;
  }

  $tmpZip = $_FILES['backup_zip']['tmp_name'];
  $zip = new ZipArchive();
  if ($zip->open($tmpZip) !== true) {
    flash_set("Could not open uploaded zip.", 'err');
    header("Location: backup_restore.php");
    exit;
  }

  $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restore_' . bin2hex(random_bytes(6));
  @mkdir($tmpDir, 0755, true);

  $zip->extractTo($tmpDir);
  $zip->close();

  try {
    if ($restoreDb) {
      $sqlFile = $tmpDir . DIRECTORY_SEPARATOR . 'database.sql';
      if (!is_file($sqlFile)) throw new Exception("database.sql not found in backup.");
      $sql = file_get_contents($sqlFile);
      if ($sql === false || trim($sql) === '') throw new Exception("database.sql is empty.");
      db_restore_from_sql($pdo, $sql);
    }

    if ($restoreFiles) {
      $src = $tmpDir . DIRECTORY_SEPARATOR . 'files';
      $dst = realpath(__DIR__ . '/..');
      if ($dst === false) $dst = __DIR__ . '/..';
      if (!is_dir($src)) throw new Exception("files/ not found in backup (not a full panel backup).");

      $backupDirReal = realpath($BACKUP_DIR);

      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
      );

      foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $rel = ltrim(str_replace($src, '', $file->getPathname()), DIRECTORY_SEPARATOR);
        $relNorm = str_replace('\\', '/', $rel);

        // Never restore backups into backups (avoid loops / accidental overwrites)
        if (strpos($relNorm, 'storage/backups') === 0) continue;

        $target = rtrim($dst, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
        if ($file->isDir()) {
          if (!is_dir($target)) @mkdir($target, 0755, true);
        } else {
          if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
          @copy($file->getPathname(), $target);
        }
      }
    }

    if ($restoreStorage) {
      $src = $tmpDir . DIRECTORY_SEPARATOR . 'storage';
      $dst = realpath(__DIR__ . '/../storage');
      if ($dst === false) $dst = __DIR__ . '/../storage';
      if (is_dir($src)) {
        // copy recursively, overwrite
        $it = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
          $rel = str_replace($src, '', $file->getPathname());
          $target = rtrim($dst, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($rel, DIRECTORY_SEPARATOR);
          if ($file->isDir()) {
            if (!is_dir($target)) @mkdir($target, 0755, true);
          } else {
            if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
            @copy($file->getPathname(), $target);
          }
        }
      }
    }

    if ($restoreConfig) {
      $cfg = $tmpDir . DIRECTORY_SEPARATOR . 'config.local.php';
      if (is_file($cfg)) {
        @copy($cfg, __DIR__ . '/../config.local.php');
      }
    }

    flash_set("Restore completed successfully.", 'ok');
  } catch (Throwable $e) {
    flash_set("Restore failed: " . $e->getMessage(), 'err');
  }

  // Cleanup temp dir
  if (is_dir($tmpDir)) {
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
      if ($file->isDir()) @rmdir($file->getPathname());
      else @unlink($file->getPathname());
    }
    @rmdir($tmpDir);
  }

  header("Location: backup_restore.php");
  exit;
}

// List backups
$backups = [];
if (is_dir($BACKUP_DIR)) {
  foreach (glob($BACKUP_DIR . DIRECTORY_SEPARATOR . 'backup_*.zip') as $f) {
    $backups[] = [
      'name' => basename($f),
      'size' => filesize($f),
      'mtime' => filemtime($f),
    ];
  }
  usort($backups, fn($a,$b)=>($b['mtime']<=>$a['mtime']));
}
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Backup & Restore</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Backup &amp; Restore</h2>
  <p class="muted" style="margin-top:-6px; margin-bottom:12px;">Create a backup (DB + optional storage/config), or restore from a backup zip.</p>
  <?php flash_show(); ?>
</div>

<br>
<div class="grid" style="grid-template-columns: 1fr 1fr; gap:14px;">
  <div class="card">
    <h2>Create Backup</h2>
    <form method="post">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="create_backup">

      <label>
        <input type="checkbox" id="include_files" name="include_files" value="1" checked>
        <strong>Full panel backup</strong> (all files where the panel lives)
      </label>
      <div class="muted" style="margin:6px 0 10px 22px;">
        Includes <code>/admin</code>, <code>/storage</code>, <code>config.local.php</code>, etc. (excludes <code>/storage/backups</code>).
      </div>

      <hr style="border:0;border-top:1px solid #eee;margin:12px 0;">

      <label style="display:block;margin-bottom:6px;">
        <input type="checkbox" id="include_storage" name="include_storage" value="1">
        Include storage folder only (uploads/EPG/etc)
      </label>
      <label style="display:block;">
        <input type="checkbox" id="include_config" name="include_config" value="1">
        Include config.local.php only (DB creds + secret key)
      </label>

      <div class="muted" style="margin:8px 0 0 22px;">
        If <strong>Full panel backup</strong> is checked, the two options above are ignored (they're already included).
      </div>

      <br>
      <button class="btn" type="submit">Create Backup</button>
</form>
    <p class="muted" style="margin-top:10px;">Backups are saved to <code>/storage/backups</code> on the server.</p>
  </div>

  <div class="card">
    <h2>Restore Backup</h2>
    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Restore will overwrite database/files. Continue?')">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="restore_backup">
      <label>Backup zip</label>
      <input type="file" name="backup_zip" accept=".zip" required>
      <div style="margin:10px 0;">
        
        <label style="display:block;margin-bottom:6px;">
          <input type="checkbox" id="restore_files" name="restore_files" value="1" checked>
          <strong>Restore full panel files</strong> (overwrites existing files)
        </label>
        <label style="display:block;margin-bottom:6px;">
          <input type="checkbox" id="restore_db" name="restore_db" value="1" checked>
          Restore database
        </label>
        <label style="display:block;margin-bottom:6px;">
          <input type="checkbox" id="restore_storage" name="restore_storage" value="1">
          Restore storage folder only
        </label>
        <label style="display:block;">
          <input type="checkbox" id="restore_config" name="restore_config" value="1">
          Restore config.local.php only
        </label>
        <div class="muted" style="margin:8px 0 0 22px;">
          If <strong>Restore full panel files</strong> is checked, the two "only" options are ignored.
        </div>

      </div>
      <button class="btn danger" type="submit">Restore</button>
    </form>
    <p class="muted" style="margin-top:10px;">Tip: for a full backup, keep “Restore full panel files” + “Restore database” checked.</p>
  </div>
</div>

<div class="card" style="margin-top:14px;">
  <h2>Existing Backups</h2>
  <?php if (!$backups): ?>
    <p class="muted">No backups found yet.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>File</th><th>Created</th><th>Size</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td><code><?=e($b['name'])?></code></td>
          <td><?=e(date('Y-m-d H:i:s', (int)$b['mtime']))?></td>
          <td><?=e(number_format(((int)$b['size'])/1024, 1))?> KB</td>
          <td style="white-space:nowrap;">
            <a class="btn gray" href="backup_restore.php?download=<?=urlencode($b['name'])?>">Download</a>
            <form method="post" style="display:inline-block; margin-left:6px;" onsubmit="return confirm('Delete this backup?')">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="delete_backup">
              <input type="hidden" name="file" value="<?=e($b['name'])?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>


<script>
(function(){
  function syncCreate(){
    var full = document.getElementById('include_files');
    var s = document.getElementById('include_storage');
    var c = document.getElementById('include_config');
    if(!full || !s || !c) return;
    var on = !!full.checked;
    s.disabled = on;
    c.disabled = on;
    if(on){ s.checked = false; c.checked = false; }
  }
  function syncRestore(){
    var full = document.getElementById('restore_files');
    var s = document.getElementById('restore_storage');
    var c = document.getElementById('restore_config');
    if(!full || !s || !c) return;
    var on = !!full.checked;
    s.disabled = on;
    c.disabled = on;
    if(on){ s.checked = false; c.checked = false; }
  }
  document.addEventListener('change', function(e){
    if(e.target && (e.target.id==='include_files')) syncCreate();
    if(e.target && (e.target.id==='restore_files')) syncRestore();
  });
  syncCreate();
  syncRestore();
})();
</script>


</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
