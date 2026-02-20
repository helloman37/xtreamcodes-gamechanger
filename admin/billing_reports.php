<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();
iptv_expire_due_subscriptions($pdo);

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
if ($days < 1) $days = 7;
if ($days > 90) $days = 90;

// last 12 months including current month
$now = new DateTimeImmutable('now');
$start = $now->modify('first day of this month')->setTime(0,0,0)->modify('-11 months');
$end = $now->modify('first day of next month')->setTime(0,0,0);

$st = $pdo->prepare("
  SELECT DATE_FORMAT(s.starts_at, '%Y-%m') AS ym,
         COUNT(*) AS subs,
         COALESCE(SUM(p.price),0) AS revenue
  FROM subscriptions s
  JOIN plans p ON p.id = s.plan_id
  WHERE s.starts_at >= :start AND s.starts_at < :end
  GROUP BY ym
  ORDER BY ym DESC
");
$st->execute([
  ':start' => $start->format('Y-m-d H:i:s'),
  ':end'   => $end->format('Y-m-d H:i:s'),
]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$map = [];
$total_revenue = 0.0;
$total_subs = 0;
foreach ($rows as $r) {
  $ym = $r['ym'];
  $map[$ym] = [
    'subs' => (int)$r['subs'],
    'revenue' => (float)$r['revenue'],
  ];
  $total_revenue += (float)$r['revenue'];
  $total_subs += (int)$r['subs'];
}

$months = [];
for ($i=0; $i<12; $i++) {
  $m = $now->modify('first day of this month')->modify("-{$i} months");
  $ym = $m->format('Y-m');
  $label = $m->format('M Y');
  $months[] = [
    'ym' => $ym,
    'label' => $label,
    'subs' => $map[$ym]['subs'] ?? 0,
    'revenue' => $map[$ym]['revenue'] ?? 0.0
  ];
}

// renewals coming up
$days_sql = (int)$days;
$renewals = $pdo->query("
  SELECT u.id, u.username, u.status,
         p.name AS plan_name, p.price,
         s.ends_at,
         TIMESTAMPDIFF(DAY, NOW(), s.ends_at) AS days_left
  FROM subscriptions s
  JOIN users u ON u.id = s.user_id
  JOIN plans p ON p.id = s.plan_id
  WHERE s.status = 'active'
    AND s.ends_at >= NOW()
    AND s.ends_at < DATE_ADD(NOW(), INTERVAL {$days_sql} DAY)
  ORDER BY s.ends_at ASC
  LIMIT 250
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Billing Reports</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Billing Reports</h2>
  <p class="muted" style="margin-top:-6px; margin-bottom:0;">Last 12 months revenue (based on plan price at time of subscription start).</p>
</div>

<br>
<div class="grid">
  <div class="card">
    <h2>12-Month Total</h2>
    <div style="font-size:28px; font-weight:1000;">$<?= number_format($total_revenue, 2) ?></div>
    <div class="muted"><?= (int)$total_subs ?> subscriptions</div>
  </div>
  <div class="card">
    <h2>Average / Month</h2>
    <?php $avg = $total_revenue/12.0; ?>
    <div style="font-size:28px; font-weight:1000;">$<?= number_format($avg, 2) ?></div>
    <div class="muted">based on 12 months</div>
  </div>
  <div class="card">
    <h2>Renewals Window</h2>
    <div style="font-size:28px; font-weight:1000;"><?= count($renewals) ?></div>
    <div class="muted">ending in next <?= (int)$days ?> days</div>
  </div>
</div>

<div class="card" style="margin-top:14px;">
  <h2>Monthly Revenue</h2>
  <div class="grid" style="margin-top:10px;">
    <?php foreach ($months as $m): ?>
      <div class="card" style="background:var(--bg-soft); border-style:dashed;">
        <div class="muted" style="font-weight:900; letter-spacing:.04em;"><?= e($m['label']) ?></div>
        <div style="font-size:22px; font-weight:1000; margin-top:6px;">$<?= number_format((float)$m['revenue'], 2) ?></div>
        <div class="muted"><?= (int)$m['subs'] ?> subs</div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" style="margin-top:14px;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
    <h2 style="margin:0;">Users Up For Renewal</h2>
    <div class="muted">
      Window:
      <a href="?days=7" class="pill" style="background:#0ea5e9; color:#001018; text-decoration:none;">7d</a>
      <a href="?days=14" class="pill" style="background:#0ea5e9; color:#001018; text-decoration:none;">14d</a>
      <a href="?days=30" class="pill" style="background:#0ea5e9; color:#001018; text-decoration:none;">30d</a>
    </div>
  </div>

  <?php if (!$renewals): ?>
    <p class="muted" style="margin-top:10px;">No active subscriptions expiring in the next <?= (int)$days ?> days.</p>
  <?php else: ?>
    <div style="overflow:auto; margin-top:10px;">
      <table class="table">
        <thead>
          <tr>
            <th>User</th>
            <th>Plan</th>
            <th>Price</th>
            <th>Ends</th>
            <th>Days</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($renewals as $r): ?>
            <tr>
              <td>
                <strong><?= e($r['username']) ?></strong>
                <?php if (($r['status'] ?? '') !== 'active'): ?>
                  <span class="pill" style="background:#f59e0b; color:#1a1200;"><?= e($r['status']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($r['plan_name']) ?></td>
              <td>$<?= number_format((float)$r['price'], 2) ?></td>
              <td><?= e($r['ends_at']) ?></td>
              <td><?= (int)$r['days_left'] ?></td>
              <td><a class="btn" href="user_accounts.php?edit=<?= (int)$r['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>


</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
