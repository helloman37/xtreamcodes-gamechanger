<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(190) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment_settings'])) {
  csrf_check();

  system_setting_set($pdo, 'paypal_enabled', isset($_POST['paypal_enabled']) ? '1' : '0');
  system_setting_set($pdo, 'paypal_client_id', trim((string)($_POST['paypal_client_id'] ?? '')));
  $paypal_secret = (string)($_POST['paypal_secret'] ?? '');
  if ($paypal_secret !== '') {
    system_setting_set($pdo, 'paypal_secret_enc', iptv_encrypt($paypal_secret));
  }
  system_setting_set($pdo, 'paypal_sandbox', (string)((($_POST['paypal_sandbox'] ?? '1') === '1') ? '1' : '0'));

  system_setting_set($pdo, 'cashapp_enabled', isset($_POST['cashapp_enabled']) ? '1' : '0');
  $cashtag = trim((string)($_POST['cashapp_cashtag'] ?? ''));
  if ($cashtag !== '' && $cashtag[0] !== '$') $cashtag = '$' . $cashtag;
  system_setting_set($pdo, 'cashapp_cashtag', $cashtag);

  system_setting_set($pdo, 'stripe_enabled', isset($_POST['stripe_enabled']) ? '1' : '0');
  system_setting_set($pdo, 'stripe_publishable_key', trim((string)($_POST['stripe_publishable_key'] ?? '')));
  $stripe_secret = (string)($_POST['stripe_secret_key'] ?? '');
  if ($stripe_secret !== '') {
    system_setting_set($pdo, 'stripe_secret_enc', iptv_encrypt($stripe_secret));
  }
  $stripe_webhook = (string)($_POST['stripe_webhook_secret'] ?? '');
  if ($stripe_webhook !== '') {
    system_setting_set($pdo, 'stripe_webhook_secret_enc', iptv_encrypt($stripe_webhook));
  }
  $stripe_mode = strtolower(trim((string)($_POST['stripe_mode'] ?? 'test')));
  if (!in_array($stripe_mode, ['test','live'], true)) $stripe_mode = 'test';
  system_setting_set($pdo, 'stripe_mode', $stripe_mode);

  flash_set('Payment gateway settings saved.', 'success');
  header('Location: payment_settings.php');
  exit;
}

$pay = gc_payment_settings($pdo);

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
  <title>Payment Gateways</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Payment Gateways</h2>
  <?php flash_show(); ?>
  <p class="muted" style="margin-top:6px;">Manage storefront payment methods here instead of hard-coding them in the installer.</p>

  <form method="post" style="margin-top:14px;">
    <?= csrf_input() ?>
    <input type="hidden" name="save_payment_settings" value="1">

    <h3 style="margin:10px 0 8px;">PayPal</h3>
    <div class="row">
      <div style="display:flex;align-items:flex-end;gap:10px;">
        <label style="display:flex;gap:8px;align-items:center;margin:0;">
          <input type="checkbox" name="paypal_enabled" value="1" <?= !empty($pay['paypal']['enabled']) ? 'checked' : '' ?>>
          Enable PayPal
        </label>
      </div>
      <div>
        <label>Mode</label>
        <select name="paypal_sandbox">
          <option value="1" <?= !empty($pay['paypal']['sandbox']) ? 'selected' : '' ?>>Sandbox</option>
          <option value="0" <?= empty($pay['paypal']['sandbox']) ? 'selected' : '' ?>>Live</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div>
        <label>Client ID</label>
        <input name="paypal_client_id" value="<?= e($pay['paypal']['client_id']) ?>" placeholder="PayPal client ID">
      </div>
      <div>
        <label>Secret (leave blank to keep current)</label>
        <input name="paypal_secret" type="password" value="" autocomplete="new-password" placeholder="<?= !empty($pay['paypal']['secret_is_set']) ? 'Saved' : 'PayPal secret' ?>">
      </div>
    </div>

    <h3 style="margin:18px 0 8px;">Stripe</h3>
    <p class="muted" style="margin-top:0;">Set your Stripe endpoint to <code>/stripe_webhook.php</code> and subscribe to <code>checkout.session.completed</code>, <code>invoice.paid</code>, <code>invoice.payment_failed</code>, <code>customer.subscription.updated</code>, and <code>customer.subscription.deleted</code>.</p>
    <div class="row">
      <div style="display:flex;align-items:flex-end;gap:10px;">
        <label style="display:flex;gap:8px;align-items:center;margin:0;">
          <input type="checkbox" name="stripe_enabled" value="1" <?= !empty($pay['stripe']['enabled']) ? 'checked' : '' ?>>
          Enable Stripe
        </label>
      </div>
      <div>
        <label>Mode</label>
        <select name="stripe_mode">
          <option value="test" <?= ($pay['stripe']['mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>Test</option>
          <option value="live" <?= ($pay['stripe']['mode'] ?? '') === 'live' ? 'selected' : '' ?>>Live</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div>
        <label>Publishable Key</label>
        <input name="stripe_publishable_key" value="<?= e($pay['stripe']['publishable_key']) ?>" placeholder="pk_test_...">
      </div>
      <div>
        <label>Secret Key (leave blank to keep current)</label>
        <input name="stripe_secret_key" type="password" value="" autocomplete="new-password" placeholder="<?= !empty($pay['stripe']['secret_is_set']) ? 'Saved' : 'sk_test_...' ?>">
      </div>
    </div>
    <div class="row">
      <div>
        <label>Webhook Secret (optional for later recurring/webhook sync)</label>
        <input name="stripe_webhook_secret" type="password" value="" autocomplete="new-password" placeholder="<?= !empty($pay['stripe']['webhook_secret_is_set']) ? 'Saved' : 'whsec_...' ?>">
      </div>
    </div>

    <h3 style="margin:18px 0 8px;">Cash App</h3>
    <div class="row">
      <div style="display:flex;align-items:flex-end;gap:10px;">
        <label style="display:flex;gap:8px;align-items:center;margin:0;">
          <input type="checkbox" name="cashapp_enabled" value="1" <?= !empty($pay['cashapp']['enabled']) ? 'checked' : '' ?>>
          Enable Cash App
        </label>
      </div>
      <div>
        <label>Cashtag</label>
        <input name="cashapp_cashtag" value="<?= e($pay['cashapp']['cashtag']) ?>" placeholder="$yourtag">
      </div>
    </div>

    <div class="notice" style="margin-top:16px;">
      Checkout only shows gateways that are both enabled and configured. Stripe is now stored here and included in the storefront flow.
    </div>

    <div style="margin-top:16px;">
      <button>Save Payment Gateways</button>
    </div>
  </form>
</div>

</body>
</html>
