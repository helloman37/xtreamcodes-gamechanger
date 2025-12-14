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
<!doctype html><html><head>
<meta charset="utf-8"><title>Checkout</title>
<link rel="stylesheet" href="store.css"></head><body>
<div class="wrap">
  <?php include __DIR__."/topbar.php"; ?>
<div class="card" style="max-width:560px;margin:0 auto;">
    <h3>Checkout — <?=e($plan['name'])?></h3>
    <p class="muted">
      <?php if($loggedInUser): ?>
        You’re logged in as <b><?=e($loggedInUser['username'])?></b>. Just choose payment and continue.
      <?php else: ?>
        Create your account, then pay. PayPal = instant activation. CashApp = offline QR.
      <?php endif; ?>
    </p>

    <form method="post">
      <?php if(!$loggedInUser): ?>
        <label>Email</label>
        <input class="input" name="email" type="email" required>

        <label style="margin-top:10px;">Username</label>
        <input class="input" name="username" required>

        <label style="margin-top:10px;">Password</label>
        <input class="input" name="password" type="password" required>
      <?php endif; ?>

      
      <label style="margin-top:10px; display:block;">
        <input type="checkbox" name="allow_adult" value="1">
        Enable adult content (18+)
      </label>
<label style="margin-top:14px;">Payment Method</label>
      <select class="input" name="provider" id="provider">
        <option value="paypal">PayPal (instant)</option>
        <option value="cashapp">CashApp (offline QR)</option>
      </select>

      <div style="margin-top:14px;">
        <button class="btn green" type="submit">Continue — $<?=e($plan['price'])?></button>
      </div>
    </form>
  </div>
</div>
</body></html>
