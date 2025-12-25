<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/actions.php';

$root = iptv_install_root();
$installed = is_file(__DIR__ . '/installed.lock');

// If already installed, normally bounce to login.
// But allow the "done" finish screen to render once after installation.
$isDone = isset($_GET['done']) && $_GET['done'] === '1';
$force  = isset($_GET['force']) && $_GET['force'] === '1';

if ($installed && !$isDone && !$force) {
  $cfgBase = '/';
  try {
    $cfgPath = $root . '/config.php';
    if (is_file($cfgPath)) {
      $tmp = require $cfgPath;
      if (is_array($tmp) && !empty($tmp['base_url'])) $cfgBase = (string)$tmp['base_url'];
    }
  } catch (Throwable $e) {}
  $base = rtrim((string)($cfgBase ?: ($_SESSION['base_url'] ?? '/')), '/');
  header('Location: ' . $base . '/admin/signin.php');
  exit;
}

$step = (int)($_GET['step'] ?? 0);
if ($step < 0) $step = 0;
if ($step > 4) $step = 4;

$errors = [];
$notice = null;
$noticeType = 'good';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pre = iptv_preflight();
$checks = $pre['checks'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'step1') {
      $db = [
        'host' => trim((string)($_POST['db_host'] ?? 'localhost')),
        'name' => trim((string)($_POST['db_name'] ?? '')),
        'user' => trim((string)($_POST['db_user'] ?? '')),
        'pass' => (string)($_POST['db_pass'] ?? ''),
        'charset' => 'utf8mb4',
      ];
      $base_url = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
      $paypal_client = trim((string)($_POST['paypal_client'] ?? ''));
      $paypal_secret = trim((string)($_POST['paypal_secret'] ?? ''));
      $paypal_sandbox = isset($_POST['paypal_sandbox']) ? '1' : '0';
      $cashapp = trim((string)($_POST['cashapp'] ?? '$'));
      $server_stack = trim((string)($_POST['server_stack'] ?? 'apache'));
      $php_fpm = trim((string)($_POST['php_fpm'] ?? ''));

      $allowedStacks = ['apache','nginx','aapanel_apache','aapanel_nginx'];
      if (!in_array($server_stack, $allowedStacks, true)) $server_stack = 'apache';

      if ($db['name'] === '' || $db['user'] === '') throw new RuntimeException('DB name and DB user are required.');
      if ($base_url === '' || !preg_match('~^https?://~i', $base_url)) throw new RuntimeException('Base URL must start with http:// or https://');

      // test connection
      $pdo = iptv_pdo($db);
      $pdo->query('SELECT 1');

      $_SESSION['db'] = $db;
      $_SESSION['base_url'] = $base_url;
      $_SESSION['paypal_client'] = $paypal_client;
      $_SESSION['paypal_secret'] = $paypal_secret;
      $_SESSION['paypal_sandbox'] = $paypal_sandbox;
      $_SESSION['cashapp'] = $cashapp;
      $_SESSION['server_stack'] = $server_stack;
      $_SESSION['php_fpm'] = $php_fpm;

      header('Location: index.php?step=2'); exit;
    }

    if ($action === 'step2') {
      $mode = (string)($_POST['mode'] ?? 'builtin');
      $_SESSION['mode'] = $mode;

      $pdo = iptv_pdo($_SESSION['db']);

      $log = [];
      if ($mode === 'skip') {
        $_SESSION['sql_result'] = ['ok'=>true, 'applied'=>0, 'total'=>0, 'log'=>['Skipped schema/import'], 'failures'=>0];
        try {
          require_once $root . '/migration.php';
          if (function_exists('db_migrate')) db_migrate($pdo);
          $_SESSION['sql_result']['log'][] = 'Migrations: OK';
        } catch (Throwable $e) {
          $_SESSION['sql_result']['log'][] = 'Migrations: FAIL ' . $e->getMessage();
          $errors[] = 'Migrations failed: ' . $e->getMessage();
        }
      } else {
        if ($mode === 'builtin') {
          $sql = file_get_contents(__DIR__ . '/schema.sql');
        } elseif ($mode === 'paste') {
          $sql = (string)($_POST['sql_paste'] ?? '');
        } elseif ($mode === 'upload') {
          if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed. Try again or use paste mode.');
          }
          $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
        } else {
          throw new RuntimeException('Unknown mode.');
        }

        if (trim((string)$sql) === '') throw new RuntimeException('SQL is empty.');

        $res = iptv_exec_sql($pdo, (string)$sql);
        $_SESSION['sql_result'] = $res;

        if (!$res['ok']) {
          // stay on step 2 with error
          $errors[] = "SQL import failed after {$res['applied']}/{$res['total']} statements.";
        } else {
          // run runtime migrations to bring DB fully up to date
          try {
            require_once $root . '/migration.php';
            if (function_exists('db_migrate')) db_migrate($pdo);
          } catch (Throwable $e) {
            $errors[] = 'Migrations failed: ' . $e->getMessage();
          }
          if (empty($errors)) { header('Location: index.php?step=3'); exit; }
        }
      }

      if (empty($errors)) { header('Location: index.php?step=3'); exit; }
    }

    if ($action === 'step3') {
      $admin_user = trim((string)($_POST['admin_user'] ?? 'admin'));
      $admin_pass = (string)($_POST['admin_pass'] ?? '');
      if ($admin_user === '') $admin_user = 'admin';
      if ($admin_pass === '') {
        // generate
        $admin_pass = bin2hex(random_bytes(6));
        $_SESSION['generated_admin_pass'] = $admin_pass;
      }
      $_SESSION['admin_user'] = $admin_user;
      $_SESSION['admin_pass'] = $admin_pass;

      header('Location: index.php?step=4'); exit;
    }

    if ($action === 'finish') {
      $cfgPath = $root . '/config.php';
      $vals = [
        'db' => $_SESSION['db'],
        'base_url' => $_SESSION['base_url'],
        'paypal_client' => $_SESSION['paypal_client'] ?? '',
        'paypal_secret' => $_SESSION['paypal_secret'] ?? '',
        'paypal_sandbox' => ($_SESSION['paypal_sandbox'] ?? '1') === '1',
        'cashapp' => $_SESSION['cashapp'] ?? '$',
      ];

      iptv_write_config_php($cfgPath, $vals);

      // Generate deploy bundle (Apache/Nginx configs + Ubuntu helper scripts)
      try {
        $stack = (string)($_SESSION['server_stack'] ?? 'apache');
        $phpFpm = (string)($_SESSION['php_fpm'] ?? '');
        $_SESSION['deploy_files'] = iptv_write_deploy_bundle($root, (string)$_SESSION['base_url'], $stack, $phpFpm);
      } catch (Throwable $e) {
        $_SESSION['deploy_files'] = [];
        $_SESSION['deploy_error'] = $e->getMessage();
      }

      // seed admin (best-effort)
      $pdo = iptv_pdo($_SESSION['db']);
      $seed = iptv_seed_admin($pdo, (string)($_SESSION['admin_user'] ?? 'admin'), (string)($_SESSION['admin_pass'] ?? 'admin'));
      $_SESSION['seed_result'] = $seed;

      // lock
      file_put_contents(__DIR__ . '/installed.lock', 'installed ' . gmdate('c'));
      $installed = true;

      header('Location: index.php?step=4&done=1'); exit;
    }

  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

function step_state(int $n): string {
  $cur = (int)($_GET['step'] ?? 0);
  if ($n < $cur) return 'done';
  if ($n === $cur) return 'active';
  return '';
}

$baseUrl = (string)($_SESSION['base_url'] ?? '');
?><!doctype html>
<html lang="en">
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>IPTV Panel Installer</title>
  <!-- Match the Admin login theme (blurred background + centered card) -->
  <link rel="stylesheet" href="../admin/login.css?v=<?php echo @filemtime(__DIR__ . '/../admin/login.css') ?: 1; ?>" />
  <link rel="stylesheet" href="assets/app.css?v=9" />
</head>
<body>
  <div class="login-bg installer-bg">
    <div class="login-wrap installer-wrap">
      <div class="login-card installer-card">
        <div class="brand installer-brand">
          <div class="xtream-logo" aria-label="XTREAM ui GAME CHANGER Installer">
            <span class="x">X</span><span class="tream">TREAM</span><span class="ui">ui</span>
          </div>
          <div class="gc">GAME CHANGER</div>
          <div class="installer-meta">
            <span class="pill"><?= $installed ? '✓ Installed' : 'Installer' ?></span>
            <span class="muted">No code editing. ~60 seconds. Writes <code>config.php</code>.</span>
          </div>
        </div>

        <div class="subhead">INSTALLER</div>

      <div class="stepper">
        <div class="step <?= step_state(0) ?>"><div class="n">1</div>Pre-flight</div>
        <div class="step <?= step_state(1) ?>"><div class="n">2</div>Connection</div>
        <div class="step <?= step_state(2) ?>"><div class="n">3</div>Schema / Import</div>
        <div class="step <?= step_state(3) ?>"><div class="n">4</div>Admin</div>
        <div class="step <?= step_state(4) ?>"><div class="n">5</div>Finish</div>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="notice bad">
          <strong>Fix this:</strong><br>
          <?= implode('<br>', array_map('h', $errors)) ?>
        </div>
      <?php endif; ?>

      <div class="grid">
        <div class="card">
          <div class="pad">
            <?php if ($step === 0): ?>
              <h2>Pre-flight checks</h2>
              <p>These should be green before you continue.</p>

              <?php foreach ($checks as $c): ?>
                <div class="kv">
                  <div class="k"><span class="dot <?= $c['ok'] ? 'good' : 'bad' ?>"></span><?= h($c['label']) ?></div>
                  <div class="v"><?= h((string)$c['value']) ?></div>
                </div>
              <?php endforeach; ?>

              <div class="actions">
                <a class="btn" href="index.php?step=0">Re-check</a>
                <a class="btn primary" href="index.php?step=1" <?= $pre['ok'] ? '' : 'style="opacity:.5;pointer-events:none"' ?>>Next</a>
              </div>

            <?php elseif ($step === 1): ?>
              <h2>Connection + Base URL + Payments</h2>
              <p>Enter your database credentials and site URL. PayPal + CashApp are optional.</p>

              <form class="form" method="post" action="index.php?step=1">
                <input type="hidden" name="action" value="step1" />
                <div class="row">
                  <div class="field">
                    <label>DB Host</label>
                    <input name="db_host" placeholder="localhost" value="<?= h($_SESSION['db']['host'] ?? 'localhost') ?>">
                  </div>
                  <div class="field">
                    <label>DB Name</label>
                    <input name="db_name" placeholder="iptv" value="<?= h($_SESSION['db']['name'] ?? '') ?>">
                  </div>
                </div>
                <div class="row">
                  <div class="field">
                    <label>DB User</label>
                    <input name="db_user" placeholder="dbuser" value="<?= h($_SESSION['db']['user'] ?? '') ?>">
                  </div>
                  <div class="field">
                    <label>DB Password</label>
                    <input name="db_pass" placeholder="(optional)" value="<?= h($_SESSION['db']['pass'] ?? '') ?>">
                  </div>
                </div>
                <div class="field">
                  <label>Base URL (no trailing slash)</label>
                  <input name="base_url" placeholder="https://yourdomain.com" value="<?= h($_SESSION['base_url'] ?? '') ?>">
                  <div class="help">Example: <code>https://example.com</code></div>
                </div>


                <div class="hr"></div>
                <h2 style="margin-top:0">Server architecture</h2>
                <p class="small">Choose how you want to run it. Apache writes <code>.htaccess</code>. Nginx generates a ready vhost in <code>/deploy</code>.</p>

                <div class="row">
                  <div class="field">
                    <label>Stack</label>
                    <select name="server_stack" id="server_stack" onchange="document.getElementById('phpFpmWrap').style.display=(this.value.indexOf('nginx')!==-1?'block':'none');">
                      <option value="apache" <?= (($_SESSION['server_stack'] ?? 'apache') === 'apache') ? 'selected' : '' ?>>Apache (.htaccess)</option>
                      <option value="nginx" <?= (($_SESSION['server_stack'] ?? '') === 'nginx') ? 'selected' : '' ?>>Nginx + PHP-FPM (Ubuntu / Xtream-style)</option>
                      <option value="aapanel_apache" <?= (($_SESSION['server_stack'] ?? '') === 'aapanel_apache') ? 'selected' : '' ?>>aaPanel (Apache)</option>
                      <option value="aapanel_nginx" <?= (($_SESSION['server_stack'] ?? '') === 'aapanel_nginx') ? 'selected' : '' ?>>aaPanel (Nginx + PHP-FPM)</option>
                    </select>
                  </div>
                  <div class="field" id="phpFpmWrap" style="display:<?= (strpos((string)($_SESSION['server_stack'] ?? 'apache'), 'nginx') !== false) ? 'block' : 'none' ?>;">
                    <label>PHP-FPM socket</label>
                    <input name="php_fpm" placeholder="unix:/run/php/php8.2-fpm.sock" value="<?= h($_SESSION['php_fpm'] ?? '') ?>">
                    <div class="help">Leave blank to auto-guess from your PHP version.</div>
                  </div>
                </div>


                <h2 style="margin-top:0">Optional storefront</h2>
                <p class="small">These get written into <code>config.php</code>. You can leave them blank.</p>
                <div class="row">
                  <div class="field">
                    <label>PayPal Client ID</label>
                    <input name="paypal_client" value="<?= h($_SESSION['paypal_client'] ?? '') ?>" placeholder="optional">
                  </div>
                  <div class="field">
                    <label>PayPal Secret</label>
                    <input name="paypal_secret" value="<?= h($_SESSION['paypal_secret'] ?? '') ?>" placeholder="optional">
                  </div>
                </div>
                <div class="row">
                  <div class="field">
                    <label>PayPal Sandbox?</label>
                    <select name="paypal_sandbox">
                      <option value="1" <?= (($_SESSION['paypal_sandbox'] ?? '1') === '1') ? 'selected' : '' ?>>Yes (sandbox)</option>
                      <option value="0" <?= (($_SESSION['paypal_sandbox'] ?? '1') === '0') ? 'selected' : '' ?>>No (live)</option>
                    </select>
                  </div>
                  <div class="field">
                    <label>CashApp Cashtag</label>
                    <input name="cashapp" value="<?= h($_SESSION['cashapp'] ?? '$') ?>" placeholder="$yourtag">
                  </div>
                </div>

                <div class="actions">
                  <a class="btn" href="index.php?step=0">Back</a>
                  <button class="btn primary" type="submit">Next</button>
                </div>
              </form>

            <?php elseif ($step === 2): ?>
              <h2>Schema / Import</h2>
              <p>Choose one: run built-in schema, upload an existing SQL dump, paste SQL, or skip (if DB already has tables).</p>

              <form class="form" method="post" action="index.php?step=2" enctype="multipart/form-data">
                <input type="hidden" name="action" value="step2" />
                <div class="field">
                  <label>Mode</label>
                  <select name="mode" id="mode" onchange="document.getElementById('pasteWrap').style.display=(this.value==='paste'?'block':'none');document.getElementById('uploadWrap').style.display=(this.value==='upload'?'block':'none');">
                    <option value="builtin" <?= (($_SESSION['mode'] ?? 'builtin') === 'builtin') ? 'selected' : '' ?>>Run built-in schema (recommended)</option>
                    <option value="upload" <?= (($_SESSION['mode'] ?? '') === 'upload') ? 'selected' : '' ?>>Upload SQL file</option>
                    <option value="paste" <?= (($_SESSION['mode'] ?? '') === 'paste') ? 'selected' : '' ?>>Paste SQL</option>
                    <option value="skip" <?= (($_SESSION['mode'] ?? '') === 'skip') ? 'selected' : '' ?>>Skip schema/import</option>
                  </select>
                </div>

                <div id="uploadWrap" style="display:<?= (($_SESSION['mode'] ?? '') === 'upload') ? 'block' : 'none' ?>;">
                  <div class="field">
                    <label>SQL file (.sql)</label>
                    <input type="file" name="sql_file" accept=".sql,text/plain,application/sql">
                  </div>
                </div>

                <div id="pasteWrap" style="display:<?= (($_SESSION['mode'] ?? '') === 'paste') ? 'block' : 'none' ?>;">
                  <div class="field">
                    <label>Paste SQL</label>
                    <textarea name="sql_paste" placeholder="Paste your .sql dump here"></textarea>
                  </div>
                </div>

                <?php if (!empty($_SESSION['sql_result'])): $sr=$_SESSION['sql_result']; ?>
                  <div class="notice <?= $sr['ok'] ? 'good' : 'bad' ?>">
                    <?= $sr['ok'] ? 'SQL applied' : 'SQL failed' ?>:
                    <?= (int)$sr['applied'] ?>/<?= (int)$sr['total'] ?> statements.
                  </div>
                  <div class="pad">
                    <pre class="log"><?= h(implode("\n", (array)($sr['log'] ?? []))) ?></pre>
                  </div>
                <?php endif; ?>

                <div class="actions">
                  <a class="btn" href="index.php?step=1">Back</a>
                  <button class="btn primary" type="submit">Next</button>
                </div>
              </form>

            <?php elseif ($step === 3): ?>
              <h2>Admin login</h2>
              <p>Sets/updates the admin password. If you leave password blank, we generate one and show it on Finish.</p>

              <form class="form" method="post" action="index.php?step=3">
                <input type="hidden" name="action" value="step3" />
                <div class="row">
                  <div class="field">
                    <label>Admin Username</label>
                    <input name="admin_user" value="<?= h($_SESSION['admin_user'] ?? 'admin') ?>">
                  </div>
                  <div class="field">
                    <label>Admin Password</label>
                    <input name="admin_pass" value="" placeholder="leave blank = auto-generate">
                  </div>
                </div>
                <div class="actions">
                  <a class="btn" href="index.php?step=2">Back</a>
                  <button class="btn primary" type="submit">Next</button>
                </div>
              </form>

            <?php else: ?>
              <h2>Finish</h2>
              <p>This writes <code>config.php</code>, tries to seed the admin login, then locks the installer.</p>

              <?php if (isset($_GET['done']) && $_GET['done'] === '1'): ?>
                <div class="notice good">
                  ✅ Installed.<br>
                  <div style="margin-top:6px">
                    Admin username: <code><?= h((string)($_SESSION['admin_user'] ?? 'admin')) ?></code><br>
                    Admin password: <code><?= h((string)($_SESSION['admin_pass'] ?? ($_SESSION['generated_admin_pass'] ?? ''))) ?></code>
                    <?php if (!empty($_SESSION['generated_admin_pass'])): ?>
                      <span class="small" style="margin-left:8px;opacity:.9">(generated)</span>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if (!empty($_SESSION['seed_result'])): $sr=$_SESSION['seed_result']; ?>
                  <div class="notice <?= $sr['ok'] ? 'good' : 'warn' ?>">
                    Admin seed: <?= $sr['ok'] ? h($sr['action'].' in '.$sr['table']) : h($sr['error'] ?? 'failed') ?>
                  </div>
                <?php endif; ?>

                <?php if (!empty($_SESSION['deploy_error'])): ?>
                  <div class="notice warn">Deploy bundle: <?= h((string)$_SESSION['deploy_error']) ?></div>
                <?php endif; ?>

                <?php if (!empty($_SESSION['deploy_files'])): ?>
                  <div class="notice good">
                    Deploy bundle written to <code><?= h($root) ?>/deploy</code>
                    <div class="small" style="margin-top:6px">Includes Apache <code>.htaccess</code>, Nginx vhost, and Ubuntu helper scripts.</div>
                  </div>
                  <div class="pad">
                    <pre class="log"><?= h(implode("\n", (array)$_SESSION['deploy_files'])) ?></pre>
                  </div>
                <?php endif; ?>


                <div class="actions">
                  <a class="btn primary" href="<?= h($_SESSION['base_url'] ?? '/') ?>/admin/signin.php">Go to login</a>
                  <a class="btn danger" href="index.php?step=0&force=1">Re-run installer (force)</a>
                </div>
              <?php else: ?>
                <form class="form" method="post" action="index.php?step=4">
                  <input type="hidden" name="action" value="finish" />
                  <div class="field">
                    <label>Config path</label>
                    <input value="<?= h($root . '/config.php') ?>" disabled>
                  </div>
                  <div class="small">After install, you can delete <code>/install</code> or keep it locked via <code>install/installed.lock</code>.</div>
                  <div class="actions">
                    <a class="btn" href="index.php?step=3">Back</a>
                    <button class="btn primary" type="submit">Install</button>
                  </div>
                </form>
              <?php endif; ?>

            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="pad">
            <h2>Status</h2>
            <p>Live view of what the installer needs.</p>
          </div>
          <?php foreach ($checks as $c): ?>
            <div class="kv">
              <div class="k"><span class="dot <?= $c['ok'] ? 'good' : 'bad' ?>"></span><?= h($c['label']) ?></div>
              <div class="v"><?= h((string)$c['value']) ?></div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>

      </div>

      <script src="assets/app.js?v=7"></script>
    </div>
  </div>
</div>
</body>
</html>
