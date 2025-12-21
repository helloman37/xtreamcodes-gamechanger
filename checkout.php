<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
$pdo=db();

session_start();

$planId=(int)($_GET['plan'] ?? 0);
$planSt=$pdo->prepare("SELECT * FROM plans WHERE id=?");
$planSt->execute([$planId]);
$plan=$planSt->fetch();
if(!$plan){ die("Invalid plan"); }

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
  $provider=$_POST['provider'] ?? 'paypal';
  $want_adult = isset($_POST['allow_adult']) ? 1 : 0;

  if($loggedInUser){
    $email = $loggedInUser['username']."@local"; // fallback if no email column
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

  // create pending order
  $tmpTxn="pending_".bin2hex(random_bytes(8));
  $stmt=$pdo->prepare("INSERT INTO orders (user_id,email, plan_id, amount, currency, provider, provider_txn, status)
                       VALUES (?,?,?,?, ?, ?, ?, 'pending')");
  $stmt->execute([$userId,$email,$planId,$plan['price'],'USD',$provider,$tmpTxn]);
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
  <div class="grid" style="grid-template-columns: 1fr 1fr; gap:18px;">

    <div class="card">
      <h3 style="margin:0 0 10px;">Account</h3>

      <?php if ($loggedInUser): ?>
        <div class="notice">You’re logged in as <b><?= e($loggedInUser['username']) ?></b>.</div>
        <div class="muted" style="margin-top:12px;">Choose payment to continue.</div>
      <?php else: ?>
        <div class="muted" style="margin-bottom:10px;">Create your account during checkout:</div>

        <label>Email</label>
        <input class="input" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>

        <label style="margin-top:10px;">Username</label>
        <input class="input" name="username" value="<?= e($_POST['username'] ?? '') ?>" required>

        <label style="margin-top:10px;">Password</label>
        <input class="input" type="password" name="password" required>

        <label style="margin-top:10px;display:flex;gap:8px;align-items:center;">
          <input type="checkbox" name="allow_adult" value="1" <?= !empty($_POST['allow_adult']) ? 'checked' : '' ?>>
          Allow adult content on this account (optional)
        </label>

        <div class="muted" style="margin-top:12px;">
          Prefer to register first? <a href="/register.php">Register</a> or <a href="/login.php">Login</a>.
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="margin:0 0 10px;">Choose Payment</h3>

      <div class="grid" style="grid-template-columns: 1fr; gap:10px;">
        <button class="btn primary" type="submit" name="provider" value="paypal" style="width:100%;">Pay with PayPal</button>
        <button class="btn" type="submit" name="provider" value="cashapp" style="width:100%;">Pay with CashApp</button>
      </div>

      <div class="muted" style="margin-top:12px;font-size:12px;line-height:1.35;">
        CashApp payments require manual verification. PayPal activates automatically.
      </div>
    </div>

  </div>
</form>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
