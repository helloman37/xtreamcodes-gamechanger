<?php
declare(strict_types=1);

function iptv_install_root(): string {
  return dirname(__DIR__);
}

function iptv_bool($v): bool {
  if (is_bool($v)) return $v;
  $s = strtolower(trim((string)$v));
  return in_array($s, ['1','true','yes','on'], true);
}

function iptv_random_key(int $len=64): string {
  $bytes = random_bytes($len);
  return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function iptv_preflight(): array {
  $root = iptv_install_root();
  $checks = [];

  $checks['php'] = [
    'label' => 'PHP >= 8.0',
    'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'value' => PHP_VERSION,
  ];

  $checks['pdo'] = [
    'label' => 'PDO MySQL enabled',
    'ok' => extension_loaded('pdo_mysql'),
    'value' => extension_loaded('pdo_mysql') ? 'yes' : 'no',
  ];

  $checks['curl'] = [
    'label' => 'cURL enabled',
    'ok' => extension_loaded('curl'),
    'value' => extension_loaded('curl') ? 'yes' : 'no',
  ];

  $configPath = $root . '/config.php';
  $checks['config_writable'] = [
    'label' => 'Writable: config.php',
    'ok' => (is_file($configPath) && is_writable($configPath)) || (!is_file($configPath) && is_writable($root)),
    'value' => is_file($configPath) ? (is_writable($configPath) ? 'writable' : 'not writable') : 'will create',
  ];

  $checks['install_lock'] = [
    'label' => 'Installer lock not present',
    'ok' => !is_file(__DIR__ . '/installed.lock'),
    'value' => is_file(__DIR__ . '/installed.lock') ? 'locked' : 'not locked',
  ];

  $allOk = true;
  foreach ($checks as $c) { if (!$c['ok']) $allOk = false; }

  return ['ok'=>$allOk, 'checks'=>$checks];
}

function iptv_pdo(array $cfg): PDO {
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4');
  $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
  ]);
  return $pdo;
}

/**
 * Split SQL into statements safely-ish (handles strings + comments).
 */
function iptv_split_sql(string $sql): array {
  $sql = str_replace("\r\n", "\n", $sql);
  $len = strlen($sql);
  $stmts = [];
  $buf = '';
  $inS = false; $inD = false; $inB = false; // single, double, backtick
  for ($i=0; $i<$len; $i++) {
    $ch = $sql[$i];
    $nx = $i+1 < $len ? $sql[$i+1] : '';

    // line comment --
    if (!$inS && !$inD && !$inB && $ch==='-' && $nx==='-') {
      // consume until newline
      while ($i<$len && $sql[$i] !== "\n") $i++;
      continue;
    }
    // line comment #
    if (!$inS && !$inD && !$inB && $ch==='#') {
      while ($i<$len && $sql[$i] !== "\n") $i++;
      continue;
    }
    // block comment /*
    if (!$inS && !$inD && !$inB && $ch==='/' && $nx==='*') {
      $i += 2;
      while ($i<$len-1 && !($sql[$i]==='*' && $sql[$i+1]==='/')) $i++;
      $i++; // skip /
      continue;
    }

    if ($ch==="'" && !$inD && !$inB) {
      // handle escaped quotes
      $escaped = $i>0 && $sql[$i-1]==='\\';
      if (!$escaped) $inS = !$inS;
    } elseif ($ch === '"' && !$inS && !$inB) {
      $escaped = $i>0 && $sql[$i-1]==='\\';
      if (!$escaped) $inD = !$inD;
    } elseif ($ch === '`' && !$inS && !$inD) {
      $inB = !$inB;
    }

    if ($ch === ';' && !$inS && !$inD && !$inB) {
      $stmt = trim($buf);
      $buf = '';
      if ($stmt !== '') $stmts[] = $stmt;
      continue;
    }
    $buf .= $ch;
  }
  $tail = trim($buf);
  if ($tail !== '') $stmts[] = $tail;
  return $stmts;
}

function iptv_exec_sql(PDO $pdo, string $sql): array {
  $stmts = iptv_split_sql($sql);
  $ok = 0; $fail = 0;
  $log = [];
  foreach ($stmts as $idx => $stmt) {
    try {
      $pdo->exec($stmt);
      $ok++;
      if (($idx % 25) === 0) $log[] = "OK #".($idx+1);
    } catch (Throwable $e) {
      $fail++;
      $snippet = preg_replace('/\s+/', ' ', trim($stmt));
      if (strlen($snippet) > 220) $snippet = substr($snippet, 0, 220) . '…';
      $log[] = "FAIL #".($idx+1).": ".$e->getMessage()." | ".$snippet;
      break;
    }
  }
  return ['ok'=>$fail===0, 'applied'=>$ok, 'total'=>count($stmts), 'log'=>$log, 'failures'=>$fail];
}

function iptv_write_config_php(string $path, array $vals): void {
  $paypal_client = (string)($vals['paypal_client'] ?? '');
  $paypal_secret = (string)($vals['paypal_secret'] ?? '');
  $paypal_sandbox = iptv_bool($vals['paypal_sandbox'] ?? true) ? 'true' : 'false';
  $cashapp = (string)($vals['cashapp'] ?? '$');

  $db = $vals['db'] ?? [];
  $defaults = [
    'db' => [
      'host' => (string)($db['host'] ?? 'localhost'),
      'name' => (string)($db['name'] ?? ''),
      'user' => (string)($db['user'] ?? ''),
      'pass' => (string)($db['pass'] ?? ''),
      'charset' => 'utf8mb4',
    ],
    'session_name' => 'iptv_admin_session',
    'base_url' => (string)($vals['base_url'] ?? 'http://'),
    'secret_key' => (string)($vals['secret_key'] ?? iptv_random_key(48)),
    'token_ttl' => (int)($vals['token_ttl'] ?? 604800),
      'sub_cache_ttl' => 60,
    'strict_device_id' => (bool)($vals['strict_device_id'] ?? false),
    'webhook_url' => (string)($vals['webhook_url'] ?? ''),
    'device_window' => (int)($vals['device_window'] ?? 300),
    'segment_window' => (int)($vals['segment_window'] ?? 1800),
    'max_ip_changes' => (int)($vals['max_ip_changes'] ?? 3),
    'max_ip_window'  => (int)($vals['max_ip_window'] ?? 600),
  ];

  $export = var_export($defaults, true);

  $php = "<?php\n";
  $php .= "declare(strict_types=1);\n\n";
  $php .= "// config.php\n";
  $php .= "// -----------------------------------------------------------------------------\n";
  $php .= "// Generated by /install (web/CLI). Safe to re-run installer to rewrite values.\n";
  $php .= "// -----------------------------------------------------------------------------\n\n";
  $php .= "// PayPal REST API (storefront)\n";
  $php .= "if (!defined('PAYPAL_CLIENT_ID')) define('PAYPAL_CLIENT_ID', ".var_export($paypal_client,true).");\n";
  $php .= "if (!defined('PAYPAL_SECRET')) define('PAYPAL_SECRET', ".var_export($paypal_secret,true).");\n";
  $php .= "if (!defined('PAYPAL_SANDBOX')) define('PAYPAL_SANDBOX', {$paypal_sandbox});\n\n";
  $php .= "// CashApp storefront (owner cashtag)\n";
  $php .= "if (!defined('CASHAPP_CASHTAG')) define('CASHAPP_CASHTAG', ".var_export($cashapp,true).");\n\n";
  $php .= "\$defaults = {$export};\n\n";
  $php .= "// Optional local overrides (kept for backwards-compat; not required)\n";
  $php .= "\$local_path = __DIR__ . '/config.local.php';\n";
  $php .= "\$local = [];\n";
  $php .= "if (is_file(\$local_path)) {\n";
  $php .= "  \$tmp = require \$local_path;\n";
  $php .= "  if (is_array(\$tmp)) \$local = \$tmp;\n";
  $php .= "}\n\n";
  $php .= "\$merged = array_replace_recursive(\$defaults, \$local);\n";
  $php .= "return \$merged;\n";

  if (file_put_contents($path, $php) === false) {
    throw new RuntimeException("Failed writing config.php at: ".$path);
  }
}

function iptv_seed_admin(PDO $pdo, string $user, string $pass): array {
  $hash = password_hash($pass, PASSWORD_BCRYPT);

  $candidates = ['admins','admin_users','users'];
  foreach ($candidates as $t) {
    // table exists?
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); } catch (Throwable $e) { continue; }

    // detect columns
    $cols = [];
    try {
      $st = $pdo->query("SHOW COLUMNS FROM `$t`");
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) $cols[] = strtolower($r['Field']);
    } catch (Throwable $e) {}

    $userCol = in_array('username', $cols, true) ? 'username' : (in_array('user', $cols, true) ? 'user' : 'username');
    $passCol = in_array('password_hash', $cols, true) ? 'password_hash' : (in_array('password', $cols, true) ? 'password' : 'password_hash');

    // Try update then insert.
    try {
      $st = $pdo->prepare("UPDATE `$t` SET `$passCol`=? WHERE `$userCol`=?");
      $st->execute([$hash, $user]);
      if ($st->rowCount() > 0) return ['ok'=>true, 'table'=>$t, 'action'=>'updated', 'usercol'=>$userCol, 'passcol'=>$passCol];
    } catch (Throwable $e) {}

    try {
      $st = $pdo->prepare("INSERT INTO `$t` (`$userCol`,`$passCol`) VALUES (?,?)");
      $st->execute([$user, $hash]);
      return ['ok'=>true, 'table'=>$t, 'action'=>'inserted', 'usercol'=>$userCol, 'passcol'=>$passCol];
    } catch (Throwable $e) {
      return ['ok'=>false, 'table'=>$t, 'error'=>$e->getMessage(), 'usercol'=>$userCol, 'passcol'=>$passCol];
    }
  }

  return ['ok'=>false, 'error'=>'No known admin table found (admins/admin_users/users).'];
}