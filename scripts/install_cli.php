<?php
declare(strict_types=1);

require __DIR__ . '/../install/actions.php';

function arg(string $k, $default=null) {
  global $argv;
  foreach ($argv as $a) {
    if (strpos($a, "--{$k}=") === 0) return substr($a, strlen($k)+3);
  }
  return $default;
}

$db = [
  'host' => (string)arg('db_host', 'localhost'),
  'name' => (string)arg('db_name', ''),
  'user' => (string)arg('db_user', ''),
  'pass' => (string)arg('db_pass', ''),
  'charset' => 'utf8mb4',
];
$base_url = rtrim((string)arg('base_url', ''), '/');
$admin_user = (string)arg('admin_user', 'admin');
$admin_pass = (string)arg('admin_pass', '');
$paypal_client = (string)arg('paypal_client', '');
$paypal_secret = (string)arg('paypal_secret', '');
$paypal_sandbox = iptv_bool(arg('paypal_sandbox', '1'));
$cashapp = (string)arg('cashapp', '$');

$mode = (string)arg('mode', 'builtin'); // builtin|skip|file
$sql_file = (string)arg('sql_file', '');

if ($db['name'] === '' || $db['user'] === '' || $base_url === '') {
  fwrite(STDERR, "Missing required args: --db_name --db_user --base_url\n");
  exit(1);
}

try {
  $pdo = iptv_pdo($db);
  $pdo->query("SELECT 1");
  echo "DB ok\n";

  if ($mode === 'skip') {
    echo "Skipping schema/import\n";
  } else {
    if ($mode === 'file') {
      if ($sql_file === '' || !is_file($sql_file)) throw new RuntimeException("sql_file not found");
      $sql = file_get_contents($sql_file);
    } else {
      $sql = file_get_contents(__DIR__ . '/../install/schema.sql');
    }
    $res = iptv_exec_sql($pdo, (string)$sql);
    echo ($res['ok'] ? "SQL ok " : "SQL fail ") . "{$res['applied']}/{$res['total']}\n";
    if (!$res['ok']) {
      echo implode("\n", $res['log'])."\n";
      exit(2);
    }
  }

  // run runtime migrations to bring DB fully up to date
  try {
    require_once __DIR__ . '/../migration.php';
    if (function_exists('db_migrate')) db_migrate($pdo);
    echo "Migrations ok\n";
  } catch (Throwable $e) {
    echo "Migrations fail: " . $e->getMessage() . "\n";
  }

  if ($admin_pass === '') $admin_pass = bin2hex(random_bytes(6));
  $seed = iptv_seed_admin($pdo, $admin_user, $admin_pass);
  echo "Admin seed: " . json_encode($seed) . "\n";
  if ($admin_pass && strpos($argv[0] ?? '', 'install_cli.php') !== false) {
    // only show if auto-generated
  }

  $cfgPath = iptv_install_root() . '/config.php';
  iptv_write_config_php($cfgPath, [
    'db'=>$db,
    'base_url'=>$base_url,
    'paypal_client'=>$paypal_client,
    'paypal_secret'=>$paypal_secret,
    'paypal_sandbox'=>$paypal_sandbox,
    'cashapp'=>$cashapp,
  ]);
  echo "Wrote config.php\n";

  file_put_contents(__DIR__ . '/../install/installed.lock', 'installed ' . gmdate('c'));
  echo "Locked installer\n";

  echo "Done. Login: {$base_url}/admin/signin.php\n";
  if (arg('admin_pass','') === '') {
    echo "Generated admin password: {$admin_pass}\n";
  }

} catch (Throwable $e) {
  fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
  exit(9);
}
