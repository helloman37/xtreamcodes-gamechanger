<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../email_lib.php';
require_admin();

$pdo = db();

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
  csrf_check();

  $driver = strtolower(trim((string)($_POST['mail_driver'] ?? 'phpmail')));
  if (!in_array($driver, ['phpmail','smtp'], true)) $driver = 'phpmail';

  $from_email = trim((string)($_POST['mail_from_email'] ?? ''));
  $from_name  = trim((string)($_POST['mail_from_name'] ?? ''));
  $reply_to   = trim((string)($_POST['mail_reply_to'] ?? ''));
  if ($reply_to !== '' && !filter_var($reply_to, FILTER_VALIDATE_EMAIL)) $reply_to = '';

  system_setting_set($pdo, 'mail_driver', $driver);
  system_setting_set($pdo, 'mail_from_email', $from_email);
  system_setting_set($pdo, 'mail_from_name', $from_name);
  system_setting_set($pdo, 'mail_reply_to', $reply_to);

  // SMTP
  system_setting_set($pdo, 'smtp_host', trim((string)($_POST['smtp_host'] ?? '')));
  system_setting_set($pdo, 'smtp_port', trim((string)($_POST['smtp_port'] ?? '')));
  system_setting_set($pdo, 'smtp_user', trim((string)($_POST['smtp_user'] ?? '')));
  $smtp_pass = (string)($_POST['smtp_pass'] ?? '');
  if ($smtp_pass !== '') {
    system_setting_set($pdo, 'smtp_pass_enc', iptv_encrypt($smtp_pass));
  }
  system_setting_set($pdo, 'smtp_security', strtolower(trim((string)($_POST['smtp_security'] ?? 'tls'))));
  system_setting_set($pdo, 'smtp_auth', isset($_POST['smtp_auth']) ? '1' : '0');
  system_setting_set($pdo, 'smtp_timeout', trim((string)($_POST['smtp_timeout'] ?? '15')));

  // IMAP
  system_setting_set($pdo, 'imap_host', trim((string)($_POST['imap_host'] ?? '')));
  system_setting_set($pdo, 'imap_port', trim((string)($_POST['imap_port'] ?? '')));
  system_setting_set($pdo, 'imap_user', trim((string)($_POST['imap_user'] ?? '')));
  $imap_pass = (string)($_POST['imap_pass'] ?? '');
  if ($imap_pass !== '') {
    system_setting_set($pdo, 'imap_pass_enc', iptv_encrypt($imap_pass));
  }
  system_setting_set($pdo, 'imap_security', strtolower(trim((string)($_POST['imap_security'] ?? 'ssl'))));
  system_setting_set($pdo, 'imap_mailbox', trim((string)($_POST['imap_mailbox'] ?? 'INBOX')));
  system_setting_set($pdo, 'imap_timeout', trim((string)($_POST['imap_timeout'] ?? '15')));

  // Toggles
  system_setting_set($pdo, 'require_email_verification', isset($_POST['require_email_verification']) ? '1' : '0');
  system_setting_set($pdo, 'email_welcome_enabled', isset($_POST['email_welcome_enabled']) ? '1' : '0');
  system_setting_set($pdo, 'email_verify_enabled', isset($_POST['email_verify_enabled']) ? '1' : '0');
  system_setting_set($pdo, 'email_subscription_enabled', isset($_POST['email_subscription_enabled']) ? '1' : '0');
  system_setting_set($pdo, 'email_expiry_enabled', isset($_POST['email_expiry_enabled']) ? '1' : '0');
  $expiry_days = (int)($_POST['email_expiry_days'] ?? 3);
  if ($expiry_days < 1) $expiry_days = 3;
  system_setting_set($pdo, 'email_expiry_days', (string)$expiry_days);

  // Google reCAPTCHA
  system_setting_set($pdo, 'recaptcha_enabled', isset($_POST['recaptcha_enabled']) ? '1' : '0');
  system_setting_set($pdo, 'recaptcha_site_key', trim((string)($_POST['recaptcha_site_key'] ?? '')));
  $rec_secret = (string)($_POST['recaptcha_secret_key'] ?? '');
  if ($rec_secret !== '') {
    system_setting_set($pdo, 'recaptcha_secret_enc', iptv_encrypt($rec_secret));
  }
  system_setting_set($pdo, 'recaptcha_version', 'v2');

  flash_set('Settings saved', 'success');
  header('Location: settings.php');
  exit;
}

// Send test email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
  csrf_check();
  $to = trim((string)($_POST['test_email_to'] ?? ''));
  $ok = false;
  if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $html = gc_email_wrap_html('Test Email', '<h2 style="margin:0 0 10px;">Test email</h2><p style="margin:0;color:rgba(233,238,247,.88);">If you received this, your outbound email settings are working.</p>');
    $ok = gc_email_send_mail($pdo, $to, 'Test email', $html);
  }
  flash_set($ok ? 'Test email sent.' : 'Failed to send test email. Check settings / server mail support.', $ok ? 'success' : 'error');
  header('Location: settings.php');
  exit;
}

// Test IMAP connection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_imap'])) {
  csrf_check();
  $r = gc_email_imap_test($pdo);
  flash_set($r['ok'] ? 'IMAP connection OK.' : ('IMAP failed: ' . ($r['error'] ?? 'unknown error')), $r['ok'] ? 'success' : 'error');
  header('Location: settings.php');
  exit;
}

$s = gc_email_settings($pdo);

$recaptcha_enabled = (system_setting_get($pdo, 'recaptcha_enabled', '0') === '1');
$recaptcha_site_key = trim((string)system_setting_get($pdo, 'recaptcha_site_key', ''));
$recaptcha_secret_is_set = (trim((string)system_setting_get($pdo, 'recaptcha_secret_enc', '')) !== '');


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
  <title>Settings</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>System Settings</h2>
  <?php flash_show(); ?>
  <p class="muted" style="margin-top:6px;">Configure outbound email notifications (PHP mail or SMTP) and optional IMAP connection info.</p>

  <form method="post" style="margin-top:14px;">
    <?= csrf_input() ?>
    <input type="hidden" name="save_settings" value="1">

    <h3 style="margin:10px 0 8px;">Email</h3>

    <div class="row">
      <div>
        <label>Mail Driver</label>
        <select name="mail_driver">
          <option value="phpmail" <?= $s['driver']==='phpmail'?'selected':'' ?>>PHP mail()</option>
          <option value="smtp" <?= $s['driver']==='smtp'?'selected':'' ?>>SMTP</option>
        </select>
      </div>
      <div>
        <label>From Email</label>
        <input name="mail_from_email" value="<?= e($s['from_email']) ?>" placeholder="no-reply@example.com">
      </div>
      <div>
        <label>From Name</label>
        <input name="mail_from_name" value="<?= e($s['from_name']) ?>" placeholder="Your Service">
      </div>
    </div>

    <div class="row">
      <div>
        <label>Reply-To (optional)</label>
        <input name="mail_reply_to" value="<?= e($s['reply_to']) ?>" placeholder="support@example.com">
      </div>
    </div>

    <h3 style="margin:16px 0 8px;">SMTP (when Mail Driver = SMTP)</h3>
    <div class="row">
      <div>
        <label>Host</label>
        <input name="smtp_host" value="<?= e($s['smtp']['host']) ?>" placeholder="smtp.example.com">
      </div>
      <div>
        <label>Port</label>
        <input name="smtp_port" type="number" value="<?= (int)$s['smtp']['port'] ?>">
      </div>
      <div>
        <label>Security</label>
        <select name="smtp_security">
          <option value="none" <?= $s['smtp']['security']==='none'?'selected':'' ?>>None</option>
          <option value="tls" <?= $s['smtp']['security']==='tls'?'selected':'' ?>>TLS (STARTTLS)</option>
          <option value="ssl" <?= $s['smtp']['security']==='ssl'?'selected':'' ?>>SSL</option>
        </select>
      </div>
      <div>
        <label>Timeout (seconds)</label>
        <input name="smtp_timeout" type="number" value="<?= (int)$s['smtp']['timeout'] ?>">
      </div>
    </div>

    <div class="row">
      <div>
        <label>Username</label>
        <input name="smtp_user" value="<?= e($s['smtp']['user']) ?>">
      </div>
      <div>
        <label>Password (leave blank to keep current)</label>
        <input name="smtp_pass" type="password" value="" autocomplete="new-password">
      </div>
      <div style="display:flex;align-items:flex-end;gap:10px;">
        <label style="display:flex;gap:8px;align-items:center;margin:0;">
          <input type="checkbox" name="smtp_auth" value="1" <?= !empty($s['smtp']['auth'])?'checked':'' ?>>
          Use AUTH LOGIN
        </label>
      </div>
    </div>

    <h3 style="margin:16px 0 8px;">IMAP (optional)</h3>
    <div class="row">
      <div>
        <label>Host</label>
        <input name="imap_host" value="<?= e($s['imap']['host']) ?>" placeholder="imap.example.com">
      </div>
      <div>
        <label>Port</label>
        <input name="imap_port" type="number" value="<?= (int)$s['imap']['port'] ?>">
      </div>
      <div>
        <label>Security</label>
        <select name="imap_security">
          <option value="none" <?= $s['imap']['security']==='none'?'selected':'' ?>>None</option>
          <option value="tls" <?= $s['imap']['security']==='tls'?'selected':'' ?>>TLS</option>
          <option value="ssl" <?= $s['imap']['security']==='ssl'?'selected':'' ?>>SSL</option>
        </select>
      </div>
      <div>
        <label>Mailbox</label>
        <input name="imap_mailbox" value="<?= e($s['imap']['mailbox']) ?>" placeholder="INBOX">
      </div>
      <div>
        <label>Timeout (seconds)</label>
        <input name="imap_timeout" type="number" value="<?= (int)$s['imap']['timeout'] ?>">
      </div>
    </div>
    <div class="row">
      <div>
        <label>Username</label>
        <input name="imap_user" value="<?= e($s['imap']['user']) ?>">
      </div>
      <div>
        <label>Password (leave blank to keep current)</label>
        <input name="imap_pass" type="password" value="" autocomplete="new-password">
      </div>
    </div>

    <h3 style="margin:16px 0 8px;">Notifications</h3>
    <div class="row" style="align-items:center;">
      <label style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="checkbox" name="require_email_verification" value="1" <?= !empty($s['toggles']['require_verification'])?'checked':'' ?>>
        Require email verification before using the service
      </label>
    </div>
    <div class="row" style="align-items:center;">
      <label style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="checkbox" name="email_welcome_enabled" value="1" <?= !empty($s['toggles']['send_welcome'])?'checked':'' ?>>
        Send welcome email on signup
      </label>
    </div>
    <div class="row" style="align-items:center;">
      <label style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="checkbox" name="email_verify_enabled" value="1" <?= !empty($s['toggles']['send_verify'])?'checked':'' ?>>
        Send verification email on signup
      </label>
    </div>
    <div class="row" style="align-items:center;">
      <label style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="checkbox" name="email_subscription_enabled" value="1" <?= !empty($s['toggles']['send_subscription'])?'checked':'' ?>>
        Send subscription email on activation
      </label>
    </div>
    <div class="row" style="align-items:center; gap:12px;">
      <label style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="checkbox" name="email_expiry_enabled" value="1" <?= !empty($s['toggles']['send_expiry'])?'checked':'' ?>>
        Send expiration reminder
      </label>
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="muted" style="font-size:12px;">Days before:</span>
        <input name="email_expiry_days" type="number" value="<?= (int)$s['toggles']['expiry_days'] ?>" min="1" style="width:90px;">
      </div>
    </div>


    <h3 style="margin:16px 0 8px;">Google reCAPTCHA</h3>
    <div class="row" style="align-items:center;">
      <label style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="checkbox" name="recaptcha_enabled" value="1" <?= !empty($recaptcha_enabled)?'checked':'' ?>>
        Enable reCAPTCHA on Login and Signup
      </label>
    </div>

    <!-- Align items to the top so inputs line up even when one column has helper text -->
    <div class="row" style="align-items:flex-start;">
      <div>
        <label>Site Key</label>
        <input name="recaptcha_site_key" value="<?= e($recaptcha_site_key) ?>" placeholder="Your reCAPTCHA site key">
      </div>
      <div>
        <label>Secret Key (leave blank to keep current)</label>
        <input name="recaptcha_secret_key" type="password" value="" autocomplete="new-password" placeholder="<?= $recaptcha_secret_is_set ? 'Secret is set' : 'Enter secret key' ?>">
        <div class="muted" style="font-size:12px;margin-top:6px;">This is required for server-side validation.</div>
      </div>
    </div>

    <div style="margin-top:14px;">
      <button>Save Settings</button>
    </div>
  </form>
</div>

<br>

<div class="card">
  <h2>Test</h2>
  <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <?= csrf_input() ?>
    <input type="hidden" name="send_test_email" value="1">
    <div>
      <label>Send test email to</label>
      <input name="test_email_to" type="email" placeholder="you@example.com" required>
    </div>
    <button>Send</button>
  </form>

  <form method="post" style="margin-top:12px;">
    <?= csrf_input() ?>
    <input type="hidden" name="test_imap" value="1">
    <button>Test IMAP Connection</button>
    <div class="muted" style="font-size:12px;margin-top:6px;">Requires the PHP IMAP extension installed on your server.</div>
  </form>

  <div class="muted" style="font-size:12px;margin-top:10px;">
    Expiration reminders: set up a cron job to run <code>php cron_expiration_reminders.php</code> every hour (or daily).
  </div>
</div>

</body>
</html>
