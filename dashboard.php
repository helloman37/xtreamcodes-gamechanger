<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/email_lib.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION['store_user'])) {
  header("Location: /login.php");
  exit;
}

$pdo = db();

// Global maintenance mode: block storefront pages while enabled
try {
  if (function_exists('gc_enforce_maintenance')) {
    gc_enforce_maintenance($pdo, ['format' => 'html']);
  }
} catch (Throwable $e) { /* ignore */ }

$userId = is_array($_SESSION['store_user']) ? (int)($_SESSION['store_user']['id'] ?? 0) : (int)$_SESSION['store_user'];

// Global toggle (controlled from Admin -> Plans)
$trial_enabled = (system_setting_get($pdo, 'trial_enabled', '1') === '1');

$uSt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$uSt->execute([$userId]);
$user = $uSt->fetch();
if (!$user) { header("Location: /logout.php"); exit; }

$verifyNeeded = false;
try {
  if (gc_email_verification_required($pdo) && !gc_email_user_is_verified($user)) {
    $em = trim((string)($user['email'] ?? ''));
    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $verifyNeeded = true;
  }
} catch (Throwable $e) {}

// active sub
$subSt = $pdo->prepare("SELECT s.*, p.name plan_name, p.duration_days, p.is_trial
                      FROM subscriptions s
                      JOIN plans p ON p.id=s.plan_id
                      WHERE s.user_id=? AND s.status='active'
                      ORDER BY s.ends_at DESC LIMIT 1");
$subSt->execute([$userId]);
$sub = $subSt->fetch();

// trial eligibility (one per account ever)
$trialEligible = false;
$trialPlan = $pdo->query("SELECT id,name FROM plans WHERE is_trial=1 LIMIT 1")->fetch();
if ($trial_enabled && $trialPlan) {
  $stUsed = $pdo->prepare("SELECT 1
                           FROM subscriptions s
                           JOIN plans p ON p.id=s.plan_id
                           WHERE s.user_id=? AND p.is_trial=1
                           LIMIT 1");
  $stUsed->execute([$userId]);
  $hasTrial = $stUsed->fetchColumn();

  $stClaim = $pdo->prepare("SELECT 1 FROM trial_claims WHERE user_id=? LIMIT 1");
  $stClaim->execute([$userId]);
  $claimed = $stClaim->fetchColumn();

  if (!$hasTrial && !$claimed) {
    $trialEligible = true;
  }
}

$host = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — My Account';
$PUBLIC_SIDEBAR = true;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>My Account</h1>
  <p>Welcome, <b><?= e($user['username']) ?></b>. Your playlist + EPG links live here, and the portal is one click away.</p>
  <div class="big-buttons">
    <a class="btn primary" href="/portal/">Open Portal</a>
    <a class="btn" href="/plans.php">Plans</a>
  </div>

  <?php if ($verifyNeeded): ?>
    <div class="notice" style="margin-top:12px;border-color: rgba(255,187,0,.35); background: rgba(120,80,0,.18);">
      Your email is not verified yet. Please verify before using the portal / playlists. <a href="/verify_needed.php">Verify now</a>
    </div>
  <?php endif; ?>

<?php
$avatarUrl = gc_avatar_url((int)$userId);
$displayName = trim((string)($user['name'] ?? ''));
if ($displayName === '') $displayName = (string)($user['username'] ?? '');
$initial = strtoupper(substr($displayName, 0, 1));
?>

<div class="card profile-icon-card" style="max-width:740px;margin-top:18px;">
  <h3 style="margin:0 0 10px;">Profile Icon</h3>
  <div class="avatarrow">
    <div class="avatar big<?= $avatarUrl ? '' : '' ?>">
      <?php if ($avatarUrl): ?>
        <img src="<?= e($avatarUrl) ?>" alt="Avatar">
      <?php else: ?>
        <img src="/default-avatar.png" alt="Default Avatar">
      <?php endif; ?>
    </div>
    <div class="muted avatarhelp">
      Upload a square image (JPG/PNG/WEBP). If you don’t upload, we’ll show a default profile icon.
    </div>
  </div>

  <form method="post" action="/avatar_upload.php" enctype="multipart/form-data" class="avatarform">
    <?= csrf_input() ?>
    <input class="input fileinput" type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required>
    <button class="btn primary" type="submit">Upload</button>
  </form>

  <?php if ($avatarUrl): ?>
    <form method="post" action="/avatar_remove.php" style="margin-top:10px;">
      <?= csrf_input() ?>
      <button class="btn" type="submit">Remove Avatar</button>
    </form>
  <?php endif; ?>
</div>

</div>

<?php if ($trialEligible): ?>
  <div class="notice" style="margin-top:18px;">
    New here? Try us free for 7 days.
    <div style="margin-top:10px;">
      <a class="btn primary" href="/trial_start.php">Start 7‑Day Trial</a>
    </div>
  </div>
<?php endif; ?>

<div class="card row" style="margin-top:18px;">
  <?php if ($sub): ?>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <span class="badge good">Active</span>
      <span class="badge">Plan: <?= e($sub['plan_name']) ?></span>
      <?php if (!empty($sub['ends_at'])): ?>
        <span class="badge">Expires: <?= e($sub['ends_at']) ?></span>
      <?php else: ?>
        <span class="badge">No expiry date</span>
      <?php endif; ?>
      <?php if (!empty($sub['is_trial'])): ?>
        <span class="badge">Trial</span>
      <?php endif; ?>
    </div>

    <div style="margin-top:16px;">
      <div class="badge">M3U Playlist</div>
      <pre class="linkbox"><?= e($host . "/get.php?username=" . $user['username'] . "&password=YOUR_PASSWORD&type=m3u_plus") ?></pre>
      <div class="muted" style="font-size:12px; margin-top:6px;">Replace <b>YOUR_PASSWORD</b> with your actual password.</div>
    </div>

    <div style="margin-top:16px;">
      <div class="badge">EPG XMLTV</div>
      <pre class="linkbox"><?= e($host . "/xmltv.php?u=" . $user['username'] . "&p=YOUR_PASSWORD") ?></pre>
      <div class="muted" style="font-size:12px; margin-top:6px;">Replace <b>YOUR_PASSWORD</b> with your actual password.</div>
    </div>

    <?php if (!empty($sub['is_trial'])): ?>
      <div class="notice" style="margin-top:16px;">
        You’re on a 7‑day trial. Upgrade anytime to keep access.
        <div style="margin-top:10px;">
          <a class="btn primary" href="/plans.php">Upgrade Plan</a>
        </div>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <span class="badge bad">Inactive</span>
      <span class="muted">No active subscription found.</span>
    </div>
    <div style="margin-top:14px;">
      <a class="btn primary" href="/plans.php">Buy a Plan</a>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
