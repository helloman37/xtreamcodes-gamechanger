<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
$pdo=db();


// Global maintenance mode: block storefront pages while enabled
try {
  if (function_exists('gc_enforce_maintenance')) {
    gc_enforce_maintenance($pdo, ['format' => 'html']);
  }
} catch (Throwable $e) { /* ignore */ }

session_start();

$availableProviders = [];
$planId=(int)($_GET['plan'] ?? 0);
$planSt=$pdo->prepare("SELECT * FROM plans WHERE id=?");
$planSt->execute([$planId]);
$plan=$planSt->fetch();
if(!$plan){ die("Invalid plan"); }

$availableProviders = [];
foreach (['paypal','stripe','cashapp'] as $gcProv) {
  if (!gc_payment_provider_is_available($pdo, $gcProv)) continue;
  if ($gcProv === 'stripe' && trim((string)($plan['stripe_price_id'] ?? '')) === '') continue;
  $availableProviders[] = $gcProv;
}

// if customer already logged in, preload their account
$loggedInUser = null;
if(!empty($_SESSION['store_user'])){
  $uSt=$pdo->prepare("SELECT * FROM users WHERE id=?");
  $uSt->execute([$_SESSION['store_user']]);
  $loggedInUser = $uSt->fetch();
}

// Enforce: only one active subscription at a time.
if ($loggedInUser && !empty($loggedInUser['id'])) {
  $active = iptv_active_subscription($pdo, (int)$loggedInUser['id']);
  if ($active) {
    $until = (string)($active['ends_at'] ?? '');
    $untilPretty = $until ? date('M j, Y H:i', strtotime($until)) : 'never';
    $pname = (string)($active['plan_name'] ?? 'your current plan');
    flash_set("You already have an active subscription ({$pname}) until {$untilPretty}.", "warning");
    header("Location: /dashboard.php");
    exit;
  }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $provider=strtolower(trim((string)($_POST['provider'] ?? 'paypal')));
  $want_adult = isset($_POST['allow_adult']) ? 1 : 0;

  if($loggedInUser){
    $email = trim((string)($loggedInUser['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $email = $loggedInUser['username']."@local"; // fallback
    }
    $username = $loggedInUser['username'];
    $password_hash = $loggedInUser['password_hash'];
    $userId = (int)$loggedInUser['id'];
  } else {
    $email=trim($_POST['email'] ?? '');
    $username=trim($_POST['username'] ?? '');
    $password=$_POST['password'] ?? '';
    if(!$email||!$username||!$password) die("Missing fields");
    $password_hash=password_hash($password,PASSWORD_DEFAULT);
    $password_enc=iptv_encrypt($password);
    $userId = null;
  }

  if (!in_array($provider, $availableProviders, true)) {
    flash_set('That payment method is not available right now.', 'error');
    header('Location: checkout.php?plan='.(int)$planId);
    exit;
  }

  // create pending order
  $tmpTxn="pending_".bin2hex(random_bytes(8));
  $stmt=$pdo->prepare("INSERT INTO orders (user_id,email, plan_id, amount, currency, provider, provider_txn, status, billing_type, stripe_price_id, pending_username, pending_password_hash, pending_password_enc, pending_allow_adult)
                       VALUES (?,?,?,?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)");
  $stmt->execute([$userId,$email,$planId,$plan['price'],'USD',$provider,$tmpTxn, ($provider==='stripe' ? 'subscription' : 'one_time'), ($provider==='stripe' ? (string)($plan['stripe_price_id'] ?? '') : null), (!$loggedInUser ? $username : null), (!$loggedInUser ? $password_hash : null), (!$loggedInUser ? $password_enc : null), $want_adult]);
  $orderId=$pdo->lastInsertId();

  // store onboarding data only if user not logged in yet
  if(!$loggedInUser){
    $_SESSION['checkout_'.$orderId]=[
      'email'=>$email,'username'=>$username,
      'password_hash'=>$password_hash,
      'password_enc'=>$password_enc,
      'allow_adult'=>$want_adult
    ];
  } else {
    $_SESSION['checkout_'.$orderId]=['allow_adult'=>$want_adult];
  }

  if($provider==='cashapp'){
    header("Location: cashapp.php?order=".$orderId);
  } elseif ($provider==='stripe') {
    header("Location: stripe_start.php?order=".$orderId);
  } else {
    header("Location: paypal_start.php?order=".$orderId);
  }
  exit;
}
?>
<?php
$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — Checkout';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>Checkout</h1>
  <p class="muted">Plan: <b><?= e($plan['name']) ?></b> — $<?= number_format((float)$plan['price'], 2) ?></p>
</div>

<form method="post" style="margin:0;">
  <div class="checkout-grid">

    <div class="card pad checkout-card checkout-form">
      <h3 style="margin:0 0 10px;">Account</h3>

      <?php if ($loggedInUser): ?>
        <div class="notice">You’re logged in as <b><?= e($loggedInUser['username']) ?></b>.</div>
        <div class="muted" style="margin-top:12px;">Choose payment to continue.</div>
      <?php else: ?>
        <div class="muted" style="margin-bottom:10px;">Create your account during checkout:</div>

        <div class="two">
          <div>
            <label>Email</label>
            <input class="input" type="email" name="email" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" required>
          </div>
          <div>
            <label>Username</label>
            <input class="input" name="username" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>" required>
          </div>
        </div>

        <label>Password</label>
        <input class="input" type="password" name="password" autocomplete="new-password" required>

        <div class="checkline" style="margin-top:12px;">
          <input type="checkbox" name="allow_adult" value="1" <?= !empty($_POST['allow_adult']) ? 'checked' : '' ?>>
          <label>Allow adult content on this account (optional)</label>
        </div>

        <div class="muted" style="margin-top:12px;">
          Prefer to register first? <a href="/register.php">Register</a> or <a href="/login.php">Login</a>.
        </div>
      <?php endif; ?>
    </div>

    <div class="card pad checkout-card">
      <h3 style="margin:0 0 10px;">Choose Payment</h3>

      <?php if ($availableProviders): ?>
      <div style="display:grid; grid-template-columns: 1fr; gap:10px;">
        <?php if (in_array('paypal', $availableProviders, true)): ?>
          <button class="btn primary" type="submit" name="provider" value="paypal" style="width:100%;">Pay with PayPal</button>
        <?php endif; ?>
        <?php if (in_array('stripe', $availableProviders, true)): ?>
          <button class="btn" type="submit" name="provider" value="stripe" style="width:100%;">Pay with Stripe</button>
        <?php endif; ?>
        <?php if (in_array('cashapp', $availableProviders, true)): ?>
          <button class="btn" type="submit" name="provider" value="cashapp" style="width:100%;">Pay with Cash App</button>
        <?php endif; ?>
      </div>

      <div class="muted" style="margin-top:12px;font-size:12px;line-height:1.35;">
        PayPal activates automatically after a successful checkout. Stripe runs as a recurring subscription when this plan has a Stripe Price ID. Cash App remains manual verification.
      </div>
      <?php else: ?>
        <div class="notice">No payment gateways are enabled yet. Ask the admin to configure them in Admin &rarr; Payment Gateways.</div>
      <?php endif; ?>
    </div>

  </div>
</form>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
