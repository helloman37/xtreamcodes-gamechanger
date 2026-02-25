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

function iptv_pdo(?array $cfg): PDO {
  if (!is_array($cfg)) {
    throw new RuntimeException('Database config is missing (installer session lost).');
  }
  foreach (['host','name','user'] as $k) {
    if (!isset($cfg[$k]) || trim((string)$cfg[$k]) === '') {
      throw new RuntimeException('Database config missing: ' . $k);
    }
  }
  $charset = (string)($cfg['charset'] ?? 'utf8mb4');
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $charset);
  $pdo = new PDO($dsn, (string)$cfg['user'], (string)($cfg['pass'] ?? ''), [
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

// -----------------------------------------------------------------------------
// Deploy helpers: generate Apache/Nginx configs for "one ZIP" installs
// -----------------------------------------------------------------------------


function iptv_base_path_from_url(string $base_url): string {
  $p = parse_url($base_url, PHP_URL_PATH);
  $p = is_string($p) ? $p : '';
  $p = trim($p);
  if ($p === '' || $p === '/') return '/';
  if ($p[0] !== '/') $p = '/' . $p;
  $p = rtrim($p, '/');
  return $p === '' ? '/' : $p;
}

function iptv_host_from_url(string $base_url): string {
  $h = parse_url($base_url, PHP_URL_HOST);
  return is_string($h) && $h !== '' ? $h : '_';
}

function iptv_write_file(string $path, string $contents): void {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
      throw new RuntimeException("Failed creating directory: " . $dir);
    }
  }
  if (file_put_contents($path, $contents, LOCK_EX) === false) {
    throw new RuntimeException("Failed writing: " . $path);
  }
}

function iptv_render_apache_htaccess(string $basePath = '/'): string {
  $bp = $basePath;
  if ($bp === '') $bp = '/';
  if ($bp[0] !== '/') $bp = '/' . $bp;
  $bp = rtrim($bp, '/');
  if ($bp === '') $bp = '/';

  $out = "# Auto-generated by installer\n";
  $out .= "RewriteEngine On\n";
  if ($bp !== '/') $out .= "RewriteBase {$bp}/\n\n";

  // Keep these ABOVE generic rules
  $out .= "# VOD Enabler routes (keep these ABOVE the generic /movie and /series rules)\n";
  $out .= "RewriteRule ^movie/[0-9]+/?$ vod_enabler_routes.php [L,QSA]\n";
  $out .= "RewriteRule ^tv/[0-9]+/[0-9]+/[0-9]+/?$ vod_enabler_routes.php [L,QSA]\n\n";

  $out .= "# Portal routes (Support Desk)\n";
  $out .= "RewriteRule ^portal/support/?$ portal/support.php [L,QSA]\n";
  $out .= "RewriteRule ^portal/support/.*$ portal/support.php [L,QSA]\n\n";

  $out .= "# Portal routes (Watchlist)\n";
  $out .= "RewriteRule ^portal/watchlist/?$ portal/watchlist.php [L,QSA]\n";
  $out .= "RewriteRule ^portal/watchlist/.*$ portal/watchlist.php [L,QSA]\n\n";

  $out .= "# Portal routes (EPG Guide)\n";
  $out .= "RewriteRule ^portal/guide/?$ portal/guide.php [L,QSA]\n";
  $out .= "RewriteRule ^portal/guide/.*$ portal/guide.php [L,QSA]\n\n";

  $out .= "# Token mode: /live/user/token/id.m3u8?exp=...\n";
  $out .= "RewriteRule ^live/.*$ live.php [L,QSA]\n\n";

  $out .= "# Segment proxy: /seg/user/token/id?url=...\n";
  $out .= "RewriteRule ^seg/.*$ seg.php [L,QSA]\n\n";

  $out .= "# VOD: /movie/user/token/id.mp4?exp=...\n";
  $out .= "RewriteRule ^movie/[0-9]+/?$ vod_enabler_routes.php [L,QSA]\n\n";

  $out .= "# Series: /series/user/token/episode_id.mp4?exp=...\n";
  $out .= "RewriteRule ^series/.*$ series.php [L,QSA]\n";

  return $out;
}

function iptv_render_nginx_routes(string $basePath = '/'): string {
  $bp = $basePath;
  if ($bp === '' || $bp === '/') $prefix = '';
  else {
    if ($bp[0] !== '/') $bp = '/' . $bp;
    $bp = rtrim($bp, '/');
    $prefix = $bp;
  }

  // These regex locations must be defined BEFORE the generic location / or /subpath/
  $out = "# Auto-generated by installer\n";
  $out .= "# Route equivalents for the Apache .htaccess RewriteRules\n\n";

  $out .= "location ~ ^{$prefix}/movie/[0-9]+/?$ {\n  rewrite ^ {$prefix}/vod_enabler_routes.php last;\n}\n\n";
  $out .= "location ~ ^{$prefix}/tv/[0-9]+/[0-9]+/[0-9]+/?$ {\n  rewrite ^ {$prefix}/vod_enabler_routes.php last;\n}\n\n";

  $out .= "location ~ ^{$prefix}/portal/support(/.*)?$ {\n  rewrite ^ {$prefix}/portal/support.php last;\n}\n\n";
  $out .= "location ~ ^{$prefix}/portal/watchlist(/.*)?$ {\n  rewrite ^ {$prefix}/portal/watchlist.php last;\n}\n\n";
  $out .= "location ~ ^{$prefix}/portal/guide(/.*)?$ {\n  rewrite ^ {$prefix}/portal/guide.php last;\n}\n\n";

  $out .= "location ~ ^{$prefix}/live/ {\n  rewrite ^ {$prefix}/live.php last;\n}\n\n";
  $out .= "location ~ ^{$prefix}/seg/ {\n  rewrite ^ {$prefix}/seg.php last;\n}\n\n";
  $out .= "location ~ ^{$prefix}/series/ {\n  rewrite ^ {$prefix}/series.php last;\n}\n\n";

  return $out;
}

function iptv_render_nginx_site(string $base_url, string $appPath, string $phpFpm = ''): string {
  $host = iptv_host_from_url($base_url);
  $bp = iptv_base_path_from_url($base_url);
  $routes = iptv_render_nginx_routes($bp);

  // Sensible default for Ubuntu
  $phpFpm = trim($phpFpm);
  if ($phpFpm === '') {
    $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $phpFpm = "unix:/run/php/php{$ver}-fpm.sock";
  }

  $out = "# Auto-generated by installer\n";
  $out .= "# Nginx vhost sample for this panel\n";
  $out .= "# Server: {$host}\n";
  $out .= "# App path: {$appPath}\n\n";

  if ($bp === '/') {
    $out .= "server {\n";
    $out .= "  listen 80;\n";
    $out .= "  server_name {$host};\n";
    $out .= "  root {$appPath};\n";
    $out .= "  index index.php index.html;\n\n";
    $out .= rtrim($routes) . "\n\n";
    $out .= "  location / {\n";
    $out .= "    try_files \$uri \$uri/ /index.php?\$query_string;\n";
    $out .= "  }\n\n";
    $out .= "  location ~ \\.php$ {\n";
    $out .= "    include fastcgi_params;\n";
    $out .= "    fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
    $out .= "    fastcgi_pass {$phpFpm};\n";
    $out .= "  }\n";
    $out .= "}\n";
    return $out;
  }

  // Served as a subfolder (base URL includes a path). Use alias so it works even if parent docroot differs.
  $bpPrefix = rtrim($bp, '/') . '/';
  $bpNoTrail = rtrim($bp, '/');

  $out .= "server {\n";
  $out .= "  listen 80;\n";
  $out .= "  server_name {$host};\n";
  $out .= "  root " . dirname($appPath) . ";\n";
  $out .= "  index index.php index.html;\n\n";
  $out .= rtrim($routes) . "\n\n";

  $out .= "  # Serve the app under {$bpPrefix}\n";
  $out .= "  location {$bpPrefix} {\n";
  $out .= "    alias {$appPath}/;\n";
  $out .= "    index index.php;\n";
  $out .= "    try_files \$uri \$uri/ {$bpNoTrail}/index.php?\$query_string;\n";
  $out .= "  }\n\n";

  // PHP handling for alias subfolder
  $out .= "  location ~ ^{$bpNoTrail}/(.+\\.php)$ {\n";
  $out .= "    alias {$appPath}/\$1;\n";
  $out .= "    include fastcgi_params;\n";
  $out .= "    fastcgi_param SCRIPT_FILENAME {$appPath}/\$1;\n";
  $out .= "    fastcgi_pass {$phpFpm};\n";
  $out .= "  }\n";
  $out .= "}\n";

  return $out;
}

function iptv_write_deploy_bundle(string $rootPath, string $base_url, string $stack, string $phpFpm = ''): array {
  $bp = iptv_base_path_from_url($base_url);

  $files = [];
  $deploy = rtrim($rootPath, '/') . '/deploy';

  // Always generate both, so you can switch later without re-running install.
  $ht = iptv_render_apache_htaccess($bp);
  $ngRoutes = iptv_render_nginx_routes($bp);
  $ngSite = iptv_render_nginx_site($base_url, $rootPath, $phpFpm);

  iptv_write_file($deploy . '/apache/.htaccess', $ht); $files[] = 'deploy/apache/.htaccess';
  iptv_write_file($deploy . '/nginx/routes.conf', $ngRoutes); $files[] = 'deploy/nginx/routes.conf';
  iptv_write_file($deploy . '/nginx/site.conf', $ngSite); $files[] = 'deploy/nginx/site.conf';

  // If Apache selected, write root .htaccess too (so it "just works")
  if ($stack === 'apache' || $stack === 'aapanel_apache') {
    iptv_write_file(rtrim($rootPath,'/') . '/.htaccess', $ht);
    $files[] = '.htaccess (root overwritten)';
  }

  // Ubuntu helper scripts (generated, not executed)
  $host = iptv_host_from_url($base_url);
  $siteName = preg_replace('/[^a-z0-9\-_\.]/i', '_', $host);
  if ($siteName === '' || $siteName === '_') $siteName = 'iptv_panel';

  $phpFpm = trim($phpFpm);
  if ($phpFpm === '') {
    $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $phpFpm = "unix:/run/php/php{$ver}-fpm.sock";
  }

  $installNginx = "#!/usr/bin/env bash\nset -euo pipefail\n\n";
  $installNginx .= "# Auto-generated by installer. Run as root on Ubuntu.\n";
  $installNginx .= "DOMAIN=" . escapeshellarg($host) . "\n";
  $installNginx .= "SITE_NAME=" . escapeshellarg($siteName) . "\n";
  $installNginx .= "APP_PATH=" . escapeshellarg($rootPath) . "\n";
  $installNginx .= "PHP_FPM=" . escapeshellarg($phpFpm) . "\n\n";
  $installNginx .= "apt-get update\n";
  $installNginx .= "apt-get install -y nginx php-fpm php-mysql curl unzip\n\n";
  $installNginx .= "cp " . escapeshellarg($deploy . "/nginx/site.conf") . " /etc/nginx/sites-available/$SITE_NAME\n";
  $installNginx .= "ln -sf /etc/nginx/sites-available/$SITE_NAME /etc/nginx/sites-enabled/$SITE_NAME\n";
  $installNginx .= "nginx -t\n";
  $installNginx .= "systemctl restart nginx\n";
  $installNginx .= "echo \"OK: Nginx site enabled: $SITE_NAME\"\n";

  iptv_write_file($deploy . '/ubuntu/install-nginx.sh', $installNginx);
  @chmod($deploy . '/ubuntu/install-nginx.sh', 0755);
  $files[] = 'deploy/ubuntu/install-nginx.sh';

  $installApache = "#!/usr/bin/env bash\nset -euo pipefail\n\n";
  $installApache .= "# Auto-generated by installer. Run as root on Ubuntu.\n";
  $installApache .= "DOMAIN=" . escapeshellarg($host) . "\n";
  $installApache .= "SITE_NAME=" . escapeshellarg($siteName) . "\n";
  $installApache .= "APP_PATH=" . escapeshellarg($rootPath) . "\n\n";
  $installApache .= "apt-get update\n";
  $installApache .= "apt-get install -y apache2 libapache2-mod-php php-mysql curl unzip\n";
  $installApache .= "a2enmod rewrite headers\n\n";
  $installApache .= "cat > /etc/apache2/sites-available/$SITE_NAME.conf <<'CONF'\n";
  $installApache .= "<VirtualHost *:80>\n";
  $installApache .= "  ServerName {$host}\n";
  $installApache .= "  DocumentRoot {$rootPath}\n";
  $installApache .= "  <Directory {$rootPath}>\n";
  $installApache .= "    AllowOverride All\n";
  $installApache .= "    Require all granted\n";
  $installApache .= "  </Directory>\n";
  $installApache .= "</VirtualHost>\n";
  $installApache .= "CONF\n\n";
  $installApache .= "a2ensite $SITE_NAME\n";
  $installApache .= "apache2ctl configtest\n";
  $installApache .= "systemctl restart apache2\n";
  $installApache .= "echo \"OK: Apache site enabled: $SITE_NAME\"\n";

  iptv_write_file($deploy . '/ubuntu/install-apache.sh', $installApache);
  @chmod($deploy . '/ubuntu/install-apache.sh', 0755);
  $files[] = 'deploy/ubuntu/install-apache.sh';

  $readme = "DEPLOY BUNDLE\n\n";
  $readme .= "Generated by installer.\n\n";
  $readme .= "Base URL: {$base_url}\n";
  $readme .= "Detected base path: {$bp}\n";
  $readme .= "Detected app path: {$rootPath}\n";
  $readme .= "Chosen stack: {$stack}\n\n";
  $readme .= "Files:\n";
  foreach ($files as $f) $readme .= " - {$f}\n";
  $readme .= "\nNginx:\n";
  $readme .= " - Copy deploy/nginx/site.conf into /etc/nginx/sites-available and enable it.\n";
  $readme .= " - Or run deploy/ubuntu/install-nginx.sh as root (Ubuntu).\n\n";
  $readme .= "Apache:\n";
  $readme .= " - Root .htaccess was written if you selected Apache.\n";
  $readme .= " - Or run deploy/ubuntu/install-apache.sh as root (Ubuntu).\n";

  iptv_write_file($deploy . '/README.txt', $readme);
  $files[] = 'deploy/README.txt';

  return $files;
}
